<?php

declare(strict_types=1);

namespace Storm\Api\Compiler;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Resource\Factory\AttributesResourceNameCollectionFactory;
use LogicException;
use Override;
use ReflectionClass;
use ReflectionException;
use Storm\Api\Metadata\ReadAfterWrite;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;

use function array_keys;
use function array_values;
use function class_implements;
use function class_parents;
use function is_array;
use function is_string;
use function sprintf;

/**
 * The freshness topology gate: an operation that PROMISES a fresh read for a command ROUTED to
 * transport is a lie by construction, since the write leaves on the wire and no strategy can
 * await it in-process, so the container refuses to compile. "Illegal states unrepresentable"
 * translated to wiring: the witness is produced by the build, not by discipline.
 *
 * A routed command is NOT always a lie: Messenger's `sync://` transport passes through the
 * sender then handles IN the process; its `SentStamp` names the `SyncTransport` sender, exactly
 * what `CommandProcessor` recognizes as in-process, so a fresh declaration over a sync-routed
 * command is valid and this gate resolves the named transport's DSN before refusing. A DSN it
 * cannot resolve to a literal, such as an env placeholder or a missing definition, is treated
 * as leaving the process; conservative, and the runtime backstop answers an honest 202 either
 * way.
 *
 * BOUNDARY, stated honestly: resources are discovered through API Platform's ATTRIBUTE factory
 * over `api_platform.resource_class_directories`; resources declared via XML/PHP metadata,
 * config-registered classes or metadata decorators escape this gate. The gate is a build-time
 * comfort; the RUNTIME backstop, any `SentStamp` toward a non-in-process sender yielding an
 * honest 202 and never a stale 200, even beside a sync route's `HandledStamp` on the
 * double-routed shape, is the guarantee and holds for every resource however declared. The
 * routing is read where Messenger bakes it, at `messenger.senders_locator` argument 0, matched
 * with Messenger's own semantics: the class, its parents, its interfaces, and the `*` wildcard.
 * A malformed declaration dies here too, at build time, through the same typed reader the
 * runtime uses.
 */
final class GuardFreshnessTopologyPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws LogicException when an operation promises a fresh read for a command Messenger routes to a transport, or a freshness declaration is malformed; either way the container refuses to compile
     * @throws ReflectionException on a reflection failure
     */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        // try/get instead of has/get on purpose: phpstan symfony extension constant-folds the
        // hassers against the dev container's XML, which carries no api_platform, and declares
        // everything below unreachable; the catch keeps the flow alive.
        try {
            $resolved = $container->getParameterBag()->resolveValue(
                $container->getParameter('api_platform.resource_class_directories'),
            );
        } catch (ParameterNotFoundException) {
            return; // no API Platform in this container: nothing declares freshness
        }

        /** @var list<string> $paths */
        $paths = (array) $resolved;

        if ($paths === []) {
            return;
        }

        $routing = $this->routing($container);

        /** @var class-string $resourceClass the collection yields discovered resource FQCNs */
        foreach (new AttributesResourceNameCollectionFactory($paths)->create() as $resourceClass) {
            foreach ($this->declarations($resourceClass) as $where => $declaration) {
                $transport = $this->routedTransport($declaration->command, $routing, $container);

                if ($transport !== null) {
                    throw new LogicException(sprintf(
                        'Freshness promise on "%s" is a lie by construction: "%s" declares a fresh read for command "%s", but Messenger routes it to transport "%s" — the write leaves the process, nothing can await it. Drop the "%s" declaration (the async answer is a 202) or un-route the command.',
                        $resourceClass,
                        $where,
                        $declaration->command,
                        $transport,
                        ReadAfterWrite::KEY,
                    ));
                }
            }
        }
    }

    /**
     * Every ReadAfterWrite declaration the resource carries: on the resource attribute itself,
     * which applies to its operations, and on each explicitly declared operation.
     *
     * @param  class-string  $resourceClass
     * @return iterable<string, ReadAfterWrite>
     *
     * @throws LogicException when a declaration is malformed; the same typed reader as runtime, failing at build
     * @throws ReflectionException on a reflection failure
     */
    private function declarations(string $resourceClass): iterable
    {
        foreach (new ReflectionClass($resourceClass)->getAttributes(ApiResource::class) as $attribute) {
            $resource = $attribute->newInstance();

            $raw = $resource->getExtraProperties()[ReadAfterWrite::KEY] ?? null;
            if ($raw !== null) {
                yield $resourceClass => ReadAfterWrite::fromArray($raw, $resourceClass);
            }

            foreach ($resource->getOperations() ?? [] as $operation) {
                $raw = $operation->getExtraProperties()[ReadAfterWrite::KEY] ?? null;
                if ($raw !== null) {
                    $where = $resourceClass.'::'.($operation->getName() ?? $operation::class);

                    yield $where => ReadAfterWrite::fromArray($raw, $where);
                }
            }
        }
    }

    /**
     * The message-to-senders map exactly as Messenger baked it.
     *
     * @return array<string, mixed>
     */
    private function routing(ContainerBuilder $container): array
    {
        if (! $container->hasDefinition('messenger.senders_locator')) {
            return [];
        }

        $mapping = $container->getDefinition('messenger.senders_locator')->getArgument(0);

        return is_array($mapping) ? $mapping : [];
    }

    /**
     * Messenger's own routing semantics, ALL of them. The `SendersLocator` accumulates the senders
     * of EVERY matching candidate: the class, its parents, its interfaces, and `*`; then
     * `SendMessageMiddleware` sends to each. Reading only the first candidate's first sender would
     * hide a `[sync, async]` pair's async copy behind the sync pass-through: the guard would bless
     * the fresh promise and the processor answer as synchronous while a queue copy flew toward a
     * second execution. Every sender is collected here; ONE sender leaving the process is enough to
     * make the promise a lie, however many in-process siblings ride beside it.
     *
     * @param  class-string  $commandClass
     * @param  array<string, mixed>  $routing
     */
    private function routedTransport(string $commandClass, array $routing, ContainerBuilder $container): ?string
    {
        if ($routing === []) {
            return null;
        }

        $candidates = [
            $commandClass,
            ...array_values(class_parents($commandClass) ?: []),
            ...array_values(class_implements($commandClass) ?: []),
            '*',
        ];

        $senders = [];

        foreach ($candidates as $candidate) {
            $routed = $routing[$candidate] ?? null;

            if (! is_array($routed)) {
                continue;
            }

            foreach ($routed as $sender) {
                if (is_string($sender)) {
                    $senders[$sender] = true;
                }
            }
        }

        // leaves the process: the fresh promise is a lie, sync siblings or not;
        // if null, no sender, or in-process `sync://` senders only: SentStamp AND HandledStamp, the exact
        // case the processor treats as synchronous, so a fresh promise over it is valid
        return array_find(array_keys($senders), fn ($name) => ! $this->handlesInProcess($name, $container));
    }

    /**
     * Whether the named transport handles in-process, meaning `sync://`. Anything unresolvable,
     * such as no definition or a non-literal DSN like an env placeholder, reads as leaving the
     * process: conservative, and the runtime backstop stays honest either way.
     */
    private function handlesInProcess(string $transportName, ContainerBuilder $container): bool
    {
        $id = 'messenger.transport.'.$transportName;

        if (! $container->hasDefinition($id)) {
            return false;
        }

        $dsn = $container->getDefinition($id)->getArgument(0);

        return is_string($dsn) && str_starts_with($dsn, 'sync://');
    }
}
