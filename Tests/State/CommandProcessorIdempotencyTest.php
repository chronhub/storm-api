<?php

declare(strict_types=1);

namespace Storm\Api\Tests\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Storm\Api\Error\ApiProblem;
use Storm\Api\Freshness\WriteReceipt;
use Storm\Api\State\CommandProcessor;
use Storm\Bureau\Actor;
use Storm\Contracts\Chronicler\IdempotencyConflict;
use Storm\Contracts\Chronicler\IdempotencyRegistry;
use Storm\Story\Stamp\ActorStamp;
use Storm\Story\Stamp\CorrelationStamp;
use Storm\Story\Stamp\MessageIdStamp;
use Storm\Story\Stamp\TenantStamp;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;

final class CommandProcessorIdempotencyTest extends TestCase
{
    #[Test]
    public function the_same_idempotency_key_from_two_actors_yields_two_bus_identities(): void
    {
        $registry = $this->registry();
        $a = $this->processor($registry, new Actor('one', 'user'));
        $b = $this->processor($registry, new Actor('two', 'user'));
        $a->process(null, new Post, context: ['request' => $this->request()]);
        $b->process(null, new Post, context: ['request' => $this->request()]);
        self::assertNotSame($a->bus->sent[0]->last(MessageIdStamp::class)?->id, $b->bus->sent[0]->last(MessageIdStamp::class)?->id);
    }

    #[Test]
    public function a_key_reused_with_another_body_is_refused_before_the_bus(): void
    {
        $p = $this->processor($this->registry());
        $p->process(null, new Post, context: ['request' => $this->request()]);
        try {
            $p->process(null, new Post, context: ['request' => $this->request('{"amount":2}')]);
            self::fail('A different body must be refused.');
        } catch (ApiProblem $e) {
            self::assertSame(422, $e->getStatus());
            self::assertSame('/errors/idempotency-conflict', $e->getType());
            self::assertCount(1, $p->bus->sent);
        }
    }

    #[Test]
    public function a_key_without_a_principal_is_refused_before_the_bus(): void
    {
        $p = $this->processor($this->registry(), null);
        try {
            $p->process(null, new Post, context: ['request' => $this->request()]);
            self::fail('A keyed anonymous request must be refused.');
        } catch (ApiProblem $e) {
            self::assertSame(400, $e->getStatus());
            self::assertSame([], $p->bus->sent);
        }
    }

    #[Test]
    public function a_key_without_a_registry_is_a_wiring_fault(): void
    {
        $p = $this->processor(null);
        $this->expectException(LogicException::class);
        try {
            $p->process(null, new Post, context: ['request' => $this->request()]);
        } finally {
            self::assertSame([], $p->bus->sent);
        }
    }

    #[Test]
    public function a_replay_keeps_its_receipt_and_computes_stamps_once_per_request(): void
    {
        $p = $this->processor($this->registry());
        $a = $p->process(null, new Post, context: ['request' => $this->request()]);
        $b = $p->process(null, new Post, context: ['request' => $this->request()]);
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $a);
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $b);
        self::assertSame($a->getContent(), $b->getContent());
        self::assertSame(2, $p->stampCalls);
        self::assertSame($p->bus->sent[0]->last(MessageIdStamp::class)?->id, $p->bus->sent[0]->last(CorrelationStamp::class)?->id);
    }

    #[Test]
    public function tenants_and_actor_types_are_distinct_scopes(): void
    {
        $registry = $this->registry();
        $ids = [];
        foreach ([['user', 'a'], ['user', 'b'], ['service', 'a'], ['user', null]] as [$type, $tenant]) {
            $p = $this->processor($registry, new Actor('one', $type), $tenant);
            $p->process(null, new Post, context: ['request' => $this->request()]);
            $ids[] = $p->bus->sent[0]->last(MessageIdStamp::class)?->id;
        }
        self::assertCount(4, array_unique($ids));
    }

    #[Test]
    public function a_key_on_another_uri_is_a_conflict(): void
    {
        $p = $this->processor($this->registry());
        $p->process(null, new Post, context: ['request' => $this->request()]);
        $request = $this->request();
        $request->server->set('REQUEST_URI', '/accounts/other');
        $this->expectException(ApiProblem::class);
        $p->process(null, new Post, context: ['request' => $request]);
    }

    #[Test]
    public function without_a_key_no_registry_or_principal_is_required(): void
    {
        $p = $this->processor(null, null);
        $r = $this->request();
        $r->headers->remove('Idempotency-Key');
        $p->process(null, new Post, context: ['request' => $r]);
        self::assertCount(1, $p->bus->sent);
    }

    #[Test]
    public function an_uncertain_send_keeps_the_identity_for_retry(): void
    {
        $p = $this->processor($this->registry());
        $p->bus->throwAfterSend = true;
        try {
            $p->process(null, new Post, context: ['request' => $this->request()]);
            self::fail('The simulated transport failure must escape.');
        } catch (RuntimeException $e) {
            self::assertSame('Unknown send outcome', $e->getMessage());
        }
        $p->bus->throwAfterSend = false;
        $p->process(null, new Post, context: ['request' => $this->request()]);
        self::assertSame($p->bus->sent[0]->last(MessageIdStamp::class)?->id, $p->bus->sent[1]->last(MessageIdStamp::class)?->id);
    }

    private function request(string $body = '{"amount":1}'): Request
    {
        return Request::create('/accounts/one', 'POST', server: ['HTTP_IDEMPOTENCY_KEY' => 'retry-1', 'CONTENT_TYPE' => 'application/json'], content: $body);
    }

    private function registry(): IdempotencyRegistry
    {
        return new class() implements IdempotencyRegistry
        {
            /** @var array<string, array{string, string}> */
            private array $claims = [];

            public function claim(string $scopeHash, string $keyHash, string $fingerprint): string
            {
                $key = $scopeHash.$keyHash;
                if (isset($this->claims[$key])) {
                    if ($this->claims[$key][0] !== $fingerprint) {
                        throw new class() extends RuntimeException implements IdempotencyConflict {};
                    }

                    return $this->claims[$key][1];
                }
                $id = 'claim-'.count($this->claims);
                $this->claims[$key] = [$fingerprint, $id];

                return $id;
            }
        };
    }

    private function processor(?IdempotencyRegistry $registry, ?Actor $actor = new Actor('one', 'user'), ?string $tenant = null): IdempotencyTestProcessor
    {
        return new IdempotencyTestProcessor(new IdempotencyTestBus, $registry, $actor, $tenant);
    }
}

final class IdempotencyTestBus implements MessageBusInterface
{
    /** @var list<Envelope> */
    public array $sent = [];

    public bool $throwAfterSend = false;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message, $stamps);
        $envelope = $envelope->with(new SentStamp('test', 'async'));
        if ($envelope->last(CorrelationStamp::class) === null) {
            $envelope = $envelope->with(new CorrelationStamp('trace-'.count($this->sent)));
        }
        $this->sent[] = $envelope;
        if ($this->throwAfterSend) {
            throw new RuntimeException('Unknown send outcome');
        }

        return $envelope;
    }
}

/**
 * A processor with server-owned identity for the HTTP boundary tests.
 *
 * @extends CommandProcessor<object>
 */
final class IdempotencyTestProcessor extends CommandProcessor
{
    public int $stampCalls = 0;

    public function __construct(public IdempotencyTestBus $bus, ?IdempotencyRegistry $registry, private ?Actor $actor, private ?string $tenant)
    {
        parent::__construct($bus, new ServiceLocator([]), null, $registry);
    }

    protected function commandFor(mixed $data, Operation $operation, array $uriVariables, array $context): object
    {
        return new stdClass;
    }

    protected function refresh(WriteReceipt $receipt, Operation $operation, array $uriVariables, array $context): ?object
    {
        return null;
    }

    protected function stamps(): array
    {
        $this->stampCalls++;
        $stamps = $this->actor === null ? [] : [new ActorStamp($this->actor)];
        if ($this->tenant !== null) {
            $stamps[] = new TenantStamp($this->tenant);
        }

        return $stamps;
    }
}
