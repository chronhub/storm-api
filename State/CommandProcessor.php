<?php

declare(strict_types=1);

namespace Storm\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use LogicException;
use Override;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Storm\Api\Freshness\FreshnessStrategy;
use Storm\Api\Freshness\WriteReceipt;
use Storm\Api\Metadata\ReadAfterWrite;
use Storm\Story\Stamp\MessageIdStamp;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

use function sprintf;

/**
 * The bridge's write side: one template method builds the command, dispatches it on
 * `storm.command.bus`, and, when the operation DECLARES a fresh read, serves the fresh state
 * through the elected {@see FreshnessStrategy} or an honest 202. `process()` is final so the
 * bridge invariants cannot be re-decided per resource:
 *
 *  - The bridge NEVER retries: a version race that survives the bus is the client's 409 to
 *    replay. On a sync dispatch the first conflict IS the answer; the bus-level replay
 *    `RecoverConcurrencyConflict` only drives worker redeliveries;
 *
 *  - A declared-fresh operation must dispatch the command it declared; the promise has a
 *    runtime witness, not just the compile-time gate;
 *
 *  - Not-fresh-in-time is a 202 carrying the correlation_id, never a stale body sold as 200;
 *
 *  - An undeclared sync write is a 204: the write happened, nothing is claimed about the read
 *    side;
 *
 *  - A write that LEFT on the wire, sent to a transport rather than handled in-process, is a
 *    202 with the correlation_id no matter what the operation declared; the honest async
 *    answer, and the runtime backstop of the compile-time freshness gate.
 *
 * The app implements two hooks. `commandFor` builds the command, fabricating the authorization
 * witness HERE once the voter has passed, so the sensitive command is unconstructible without
 * it. `refresh` re-reads the resource's current public state for the re-reading strategies.
 *
 * @template TResource of object
 *
 * @implements ProcessorInterface<mixed, TResource|Response|null>
 */
abstract class CommandProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'storm.command.bus')]
        private readonly MessageBusInterface $commandBus,
        #[AutowireLocator(FreshnessStrategy::class)]
        private readonly ContainerInterface $strategies,
        private readonly ?FreshnessStrategy $freshness = null,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return TResource|Response
     *
     * @throws HandlerFailedException wrapping the handler's own failure; unwrapped by the error layer, never here
     * @throws LogicException when the declaration is malformed, promises another command, no strategy is wired, or the command was handled by more than one handler
     * @throws ExceptionInterface on a transport failure
     */
    #[Override]
    final public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $command = $this->commandFor($data, $operation, $uriVariables, $context);
        $declaration = ReadAfterWrite::fromOperation($operation);

        if ($declaration !== null && ! $command instanceof $declaration->command) {
            throw new LogicException(sprintf('Operation "%s" declares a fresh read for "%s" but dispatched "%s" — the freshness promise names its command.', $operation->getName() ?? $operation::class, $declaration->command, $command::class));
        }

        // resolved BEFORE the dispatch, deliberately: a declared-fresh operation whose strategy is
        // miswired must refuse while nothing has happened; resolved after, the same fault answers
        // 500 on top of a COMMITTED write
        $strategy = $declaration !== null ? $this->strategyFor($declaration) : null;

        $stamps = $this->stamps();

        $idempotentId = $this->idempotentMessageId($command, $context);
        if ($idempotentId !== null) {
            $stamps[] = new MessageIdStamp($idempotentId);
        }

        $envelope = $this->commandBus->dispatch($command, $stamps);

        // what the bus DID, not what the config says: ANY copy that left toward a real transport
        // means the command's full effect has not happened in this process, including the
        // double-routed shape where a sync route ALSO handled it while a queued copy flies toward
        // a second execution; the only honest answer is 202, whatever the operation declared.
        // This is the runtime backstop of the compile-time gate, complete even for the metadata
        // sources the gate cannot see and for the documented TransportNamesStamp bypass: a command
        // forced onto the wire never gets a fake fresh read. NB `sync://` IS in-process handling:
        // its SentStamp names the SyncTransport sender and stays on the sync path.
        $left = array_any(
            $envelope->all(SentStamp::class),
            static fn (SentStamp $sent): bool => ! is_a($sent->getSenderClass(), SyncTransport::class, true),
        );

        if ($left) {
            return new JsonResponse(['correlation_id' => self::receiptId(WriteReceipt::fromEnvelope($envelope))], 202);
        }

        if ($declaration === null || $strategy === null) {
            return new Response(status: 204);
        }

        // fromEnvelope refuses a multi-handler command, and it can only do so HERE, after the
        // dispatch, since the handler count is a fact of the returned envelope; the one refusal
        // this method cannot move ahead of the write, accepted rather than hidden
        $receipt = WriteReceipt::fromEnvelope($envelope);

        $fresh = $strategy->freshState(
            $receipt,
            $declaration,
            fn (): ?object => $this->refresh($receipt, $operation, $uriVariables, $context),
        );

        if ($fresh !== null) {
            // the serialization frontier, verified, not assumed: a handler that mistakenly
            // returns an aggregate or a raw read model would otherwise reach the serializer
            // NAKED, internal fields included; the operation's declared output is the contract
            $output = $operation->getOutput();

            if (is_array($output) && ($output['class'] ?? null) === null) {
                // output DELIBERATELY disabled: `output: false` normalizes to ['class' => null],
                // the documented async recipe; falling back to the resource class here would
                // re-enable the frontier against an operation that asked for silence, a 500
                /** @var TResource $fresh */
                return $fresh;
            }

            $outputClass = is_array($output) ? ($output['class'] ?? null) : (is_string($output) ? $output : null);
            $outputClass ??= $operation->getClass();

            // interface_exists too: an operation declaring its output as an INTERFACE would
            // otherwise disable this frontier entirely, class_exists alone reading it as "no contract"
            if (is_string($outputClass) && (class_exists($outputClass) || interface_exists($outputClass)) && ! $fresh instanceof $outputClass) {
                throw new LogicException(sprintf(
                    'The freshness strategy returned %s but the operation declares %s as its output — a handler leaked an internal object to the serialization frontier.',
                    $fresh::class,
                    $outputClass,
                ));
            }

            /** @var TResource $fresh the strategy's state IS the public resource, verified above */
            return $fresh;
        }

        return new JsonResponse(['correlation_id' => self::receiptId($receipt)], 202);
    }

    /**
     * The receipt a 202 hands back, guaranteed usable: the correlation id when the bus stamped
     * one, the message id as its own correlation root otherwise, and a loud refusal when neither
     * exists, since a bus without the metadata middleware is a wiring fault and a
     * `{"correlation_id": null}` receipt is a promise the client cannot redeem.
     *
     * @throws LogicException when the envelope carries neither a correlation nor a message id
     */
    private static function receiptId(WriteReceipt $receipt): string
    {
        return $receipt->correlationId
            ?? $receipt->messageId
            ?? throw new LogicException('The dispatched envelope carries neither a correlation nor a message id — the command bus is missing its metadata middleware, and a 202 receipt without an id is a promise the client cannot redeem.');
    }

    /**
     * Build the command answering this operation via `::with(...)`. Parse the input DTO, never
     * expose it onward.
     *
     * @param  array<string, mixed>  $uriVariables
     * @param  array<string, mixed>  $context
     */
    abstract protected function commandFor(mixed $data, Operation $operation, array $uriVariables, array $context): object;

    /**
     * Re-read the resource's CURRENT public state, typically the same Story query the GET
     * provider asks, mapped by the resource's explicit factory. Called by the re-reading
     * strategies once freshness is established; null when the row is not there.
     *
     * @param  array<string, mixed>  $uriVariables
     * @param  array<string, mixed>  $context
     */
    abstract protected function refresh(WriteReceipt $receipt, Operation $operation, array $uriVariables, array $context): ?object;

    /**
     * Extra stamps for the dispatch, an override seam for app processors. Rare in practice;
     * prefer bus middleware for anything systematic.
     *
     * @return array<int, StampInterface>
     */
    protected function stamps(): array
    {
        return [];
    }

    /**
     * The bus identity of a REPLAYED request: a client's `Idempotency-Key` header becomes the
     * message id, bound to the command class so the same key on two endpoints never collides, and
     * the metadata middleware respects a pre-stamped id, so a timeout retry re-dispatches the SAME
     * bus identity instead of a fresh one.
     *
     * What that closes, honestly bounded: an ASYNC write's double-apply, since the consumer inbox
     * dedups on this id and the replayed copy skip-acks. A sync write runs in-process through no
     * inbox, so its replay protection stays where it already lives, the aggregate's CAS. Override
     * returning null to opt out, or derive the identity from the command itself for a
     * domain-keyed replay window.
     *
     * @param  array<string, mixed>  $context
     */
    protected function idempotentMessageId(object $command, array $context): ?string
    {
        $request = $context['request'] ?? null;
        if (! $request instanceof Request) {
            return null;
        }

        $key = trim((string) $request->headers->get('Idempotency-Key', ''));

        return $key === '' ? null : 'idem-'.hash('sha256', $command::class."\0".$key);
    }

    /**
     * The app-level default, the `FreshnessStrategy` alias, unless the declaration names its
     * own; the framework itself elects nothing, keeping the default open.
     *
     * @throws LogicException when no strategy is reachable; a declared-fresh operation without wiring is a fault, not a fallback
     */
    private function strategyFor(ReadAfterWrite $declaration): FreshnessStrategy
    {
        if ($declaration->strategy !== null) {
            try {
                $strategy = $this->strategies->get($declaration->strategy);
            } catch (ContainerExceptionInterface $e) {
                throw new LogicException(sprintf('The "%s" declaration names unknown freshness strategy "%s" — strategies are autoconfigured by implementing %s.', ReadAfterWrite::KEY, $declaration->strategy, FreshnessStrategy::class), 0, $e);
            }

            if (! $strategy instanceof FreshnessStrategy) {
                throw new LogicException(sprintf('Freshness strategy "%s" must implement %s.', $declaration->strategy, FreshnessStrategy::class));
            }

            return $strategy;
        }

        return $this->freshness
            ?? throw new LogicException(sprintf('Operation declares "%s" but no %s is wired — alias your app default once, or name a strategy on the declaration.', ReadAfterWrite::KEY, FreshnessStrategy::class));
    }
}
