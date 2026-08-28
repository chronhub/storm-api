<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Compiler;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Api\Compiler\GuardFreshnessTopologyPass;
use Storm\Api\Tests\Fixture\ShelveBook;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class GuardFreshnessTopologyPassTest extends TestCase
{
    // holds exactly one fresh-declaring resource fixture, FreshCatalogResource, whose command is ShelveBook
    private const string FIXTURES = __DIR__.'/../Fixture/FreshTopology';

    #[Test]
    public function a_container_without_api_platform_is_a_no_op(): void
    {
        // stan's symfony extension folds has()/get() against a dev container carrying no api_platform;
        // the pass catches the missing parameter and stays quiet rather than declaring the flow dead.
        $this->expectNotToPerformAssertions();

        new GuardFreshnessTopologyPass()->process(new ContainerBuilder);
    }

    #[Test]
    public function empty_resource_directories_is_a_no_op(): void
    {
        $this->expectNotToPerformAssertions();

        $container = new ContainerBuilder;
        $container->setParameter('api_platform.resource_class_directories', []);

        new GuardFreshnessTopologyPass()->process($container);
    }

    #[Test]
    public function a_fresh_promise_with_no_messenger_routing_compiles(): void
    {
        // no messenger.senders_locator at all: nothing is routed, so no promise is a lie.
        $this->expectNotToPerformAssertions();

        new GuardFreshnessTopologyPass()->process($this->container());
    }

    #[Test]
    public function a_command_routed_to_a_sync_transport_is_not_a_lie(): void
    {
        // sync:// passes through the sender then HANDLES in-process, stamping SentStamp AND HandledStamp,
        // the exact case the processor treats as synchronous: a fresh promise over it is valid, not refused.
        $this->expectNotToPerformAssertions();

        new GuardFreshnessTopologyPass()->process($this->container(
            routing: [ShelveBook::class => ['sync']],
            transports: ['sync' => 'sync://in-memory'],
        ));
    }

    #[Test]
    public function a_command_routed_nowhere_relevant_compiles(): void
    {
        // routing exists but names another class, and there is no `*` wildcard: our command rides no
        // transport, so the promise holds and the container compiles.
        $this->expectNotToPerformAssertions();

        new GuardFreshnessTopologyPass()->process($this->container(
            routing: ['App\\SomeOtherCommand' => ['async']],
        ));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_fresh_promise_on_an_async_routed_command_refuses_to_compile(): void
    {
        // the command is routed to a transport that does NOT resolve to sync://; here it has no
        // definition at all, so it is treated, conservatively, as leaving the process: a lie by construction.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('lie by construction');

        new GuardFreshnessTopologyPass()->process($this->container(
            routing: [ShelveBook::class => ['async']],
        ));
    }

    #[Test]
    public function a_sync_first_sender_does_not_excuse_an_async_sibling(): void
    {
        // the SendersLocator accumulates EVERY matching sender and the middleware sends to
        // each. A [sync, async] pair would answer as synchronous while a queue copy flew toward
        // a second execution; ONE sender leaving the process makes the promise a lie, however
        // many in-process siblings ride beside it
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('routes it to transport "async"');

        new GuardFreshnessTopologyPass()->process($this->container(
            routing: [ShelveBook::class => ['sync', 'async']],
            transports: ['sync' => 'sync://in-memory'],
        ));
    }

    #[Test]
    public function a_wildcard_async_sender_is_seen_behind_a_class_level_sync_route(): void
    {
        // same accumulation across CANDIDATES: the class key routes sync, the * wildcard adds an
        // async sender; Messenger sends to both, so the guard must weigh both
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('routes it to transport "async"');

        new GuardFreshnessTopologyPass()->process($this->container(
            routing: [ShelveBook::class => ['sync'], '*' => ['async']],
            transports: ['sync' => 'sync://in-memory'],
        ));
    }

    #[Test]
    public function a_wildcard_async_sender_is_seen_when_the_class_routes_nowhere(): void
    {
        // the candidate scan must survive an unrouted candidate rather than end on it: the command
        // carries no key of its own here and `*` sits LAST in the candidate list, so a scan that
        // stopped at the first miss would read no sender at all and bless what the wildcard routes
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('routes it to transport "async"');

        new GuardFreshnessTopologyPass()->process($this->container(
            routing: ['*' => ['async']],
        ));
    }

    /**
     * A container scanning the FreshTopology fixture directory, optionally with a Messenger routing
     * map at `messenger.senders_locator` argument 0 and transport definitions to drive each topology.
     *
     * @param  array<string, list<string>>|null  $routing
     * @param  array<string, string>  $transports
     */
    private function container(?array $routing = null, array $transports = []): ContainerBuilder
    {
        $container = new ContainerBuilder;
        $container->setParameter('api_platform.resource_class_directories', [self::FIXTURES]);

        if ($routing !== null) {
            $container->register('messenger.senders_locator')->setArguments([$routing]);
        }

        foreach ($transports as $name => $dsn) {
            $container->register('messenger.transport.'.$name)->setArguments([$dsn]);
        }

        return $container;
    }
}
