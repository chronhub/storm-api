<?php

declare(strict_types=1);

namespace Storm\Api\Tests\State;

use ApiPlatform\Metadata\Post;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use stdClass;
use Storm\Api\Freshness\FreshnessStrategy;
use Storm\Api\Freshness\WriteReceipt;
use Storm\Api\Metadata\ReadAfterWrite;
use Storm\Api\Tests\Fixture\ArticleResource;
use Storm\Api\Tests\Fixture\ShelveBook;
use Storm\Api\Tests\Fixture\ShelveBookProcessor;
use Storm\Story\Stamp\CorrelationStamp;
use Storm\Story\Stamp\MessageIdStamp;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

use function json_decode;
use function sprintf;

final class CommandProcessorTest extends TestCase
{
    private ?object $dispatched = null;

    /** @var array<int, StampInterface> */
    private array $dispatchedStamps = [];

    #[Test]
    public function an_undeclared_write_dispatches_and_answers_204(): void
    {
        $processor = new ShelveBookProcessor($this->bus(), $this->locator([]));

        $response = $processor->process(null, new Post, ['id' => 'b7']);

        self::assertInstanceOf(ShelveBook::class, $this->dispatched);
        self::assertSame('b7', $this->dispatched->id);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent(), 'a 204 carries no body, not even an encoded null');
    }

    #[Test]
    public function a_declared_fresh_write_serves_the_strategy_state(): void
    {
        $fresh = new stdClass;
        $seen = null;

        $strategy = $this->strategy(function (WriteReceipt $receipt) use ($fresh, &$seen): object {
            $seen = $receipt;

            return $fresh;
        });

        $processor = new ShelveBookProcessor($this->bus(), $this->locator([]), $strategy);

        $state = $processor->process(null, $this->declaredPost(), ['id' => 'b1']);

        self::assertSame($fresh, $state);
        self::assertInstanceOf(WriteReceipt::class, $seen);
        self::assertSame('c-1', $seen->correlationId);
        self::assertInstanceOf(ShelveBook::class, $seen->command);
    }

    #[Test]
    public function a_strategy_state_that_is_not_the_declared_output_is_a_wiring_fault(): void
    {
        // the serialization-frontier guard: a strategy that returns something other than the operation's
        // declared output would reach the serializer NAKED, internal fields and all; refused, not served.
        // The sibling test above serves a stdClass only because its bare Post declares no output class.
        $strategy = $this->strategy(static fn (): object => new stdClass);

        $processor = new ShelveBookProcessor($this->bus(), $this->locator([]), $strategy);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('leaked an internal object');

        $processor->process(null, new Post(
            class: ArticleResource::class,
            extraProperties: [ReadAfterWrite::KEY => ['command' => ShelveBook::class, 'projection' => 'book_catalog']],
        ), ['id' => 'b1']);
    }

    #[Test]
    public function an_operation_that_disabled_its_output_is_served_past_the_frontier(): void
    {
        // the shape an operation reaches this processor with when it declared `output: false`:
        // ApiPlatform's InputOutputResourceMetadataCollectionFactory turns that into
        // ['class' => null], and the declaration means the operation asked for no output contract.
        // Falling back to the resource class here would raise the frontier against an operation
        // that opted out of it, turning the documented async recipe into a 500. The sibling test
        // above proves the same stdClass IS refused once a class is declared
        $fresh = new stdClass;
        $strategy = $this->strategy(static fn (): object => $fresh);

        $processor = new ShelveBookProcessor($this->bus(), $this->locator([]), $strategy);

        $state = $processor->process(null, new Post(
            class: ArticleResource::class,
            output: ['class' => null],
            extraProperties: [ReadAfterWrite::KEY => ['command' => ShelveBook::class, 'projection' => 'book_catalog']],
        ), ['id' => 'b1']);

        self::assertSame($fresh, $state);
    }

    #[Test]
    public function not_fresh_in_time_is_an_honest_202_carrying_the_correlation_id(): void
    {
        $processor = new ShelveBookProcessor($this->bus(), $this->locator([]), $this->strategy(static fn (): ?object => null));

        $response = $processor->process(null, $this->declaredPost(), ['id' => 'b1']);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['correlation_id' => 'c-1'], json_decode((string) $response->getContent(), true));
    }

    #[Test]
    public function dispatching_another_command_than_declared_is_a_wiring_fault(): void
    {
        $processor = new ShelveBookProcessor($this->bus(), $this->locator([]), $this->strategy(static fn (): ?object => null));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('the freshness promise names its command');

        $processor->process(null, new Post(extraProperties: [ReadAfterWrite::KEY => ['command' => stdClass::class]]), ['id' => 'b1']);
    }

    #[Test]
    public function a_declared_fresh_write_without_any_strategy_is_a_wiring_fault(): void
    {
        $processor = new ShelveBookProcessor($this->bus(), $this->locator([]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('no Storm\Api\Freshness\FreshnessStrategy is wired');

        $processor->process(null, $this->declaredPost(), ['id' => 'b1']);
    }

    #[Test]
    public function a_wiring_fault_refuses_before_anything_is_dispatched(): void
    {
        // the strategy resolves BEFORE the dispatch, pinned as an ORDER: resolved after, the same
        // fault would answer 500 on top of a COMMITTED write; the refusal must land while nothing
        // has happened yet
        $bus = new class() implements MessageBusInterface
        {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                CommandProcessorTest::fail('a declared-fresh operation with no wired strategy must refuse BEFORE the write is dispatched');
            }
        };
        $processor = new ShelveBookProcessor($bus, $this->locator([]));

        $this->expectException(LogicException::class);

        $processor->process(null, $this->declaredPost(), ['id' => 'b1']);
    }

    #[Test]
    public function the_declaration_strategy_overrides_the_app_default(): void
    {
        $fromOverride = new stdClass;
        $override = $this->strategy(static fn (): object => $fromOverride);
        $default = $this->strategy(static fn (): ?object => self::fail('the app default must not run when the declaration names its own strategy'));

        $processor = new ShelveBookProcessor($this->bus(), $this->locator(['app.own_strategy' => $override]), $default);

        $state = $processor->process(null, $this->declaredPost(strategy: 'app.own_strategy'), ['id' => 'b1']);

        self::assertSame($fromOverride, $state);
    }

    #[Test]
    public function naming_an_unknown_strategy_is_a_wiring_fault(): void
    {
        $processor = new ShelveBookProcessor($this->bus(), $this->locator([]), $this->strategy(static fn (): ?object => null));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('unknown freshness strategy');

        $processor->process(null, $this->declaredPost(strategy: 'app.gone'), ['id' => 'b1']);
    }

    #[Test]
    public function naming_a_strategy_that_resolves_to_the_wrong_type_is_a_wiring_fault(): void
    {
        // a different failure mode than an unknown name: the container DOES have something wired
        // under that id, it just isn't a FreshnessStrategy; a dev registering the wrong service
        // under an otherwise valid-looking strategy name must be refused, not called.
        $processor = new ShelveBookProcessor($this->bus(), $this->wrongTypeLocator('app.wrong_type', new stdClass));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('must implement Storm\Api\Freshness\FreshnessStrategy');

        $processor->process(null, $this->declaredPost(strategy: 'app.wrong_type'), ['id' => 'b1']);
    }

    #[Test]
    public function a_write_that_left_on_the_wire_is_a_202_even_undeclared(): void
    {
        $processor = new ShelveBookProcessor($this->bus(new SentStamp('in-memory', 'async')), $this->locator([]));

        $response = $processor->process(null, new Post, ['id' => 'b1']);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['correlation_id' => 'c-1'], json_decode((string) $response->getContent(), true));
    }

    #[Test]
    public function a_sent_write_never_reaches_the_freshness_strategy(): void
    {
        // the TransportNamesStamp bypass residual: forced onto the wire despite the
        // compile-time gate, the declared-fresh operation still answers an honest 202
        $strategy = $this->strategy(static fn (): ?object => self::fail('a write that left the process must never await freshness'));

        $processor = new ShelveBookProcessor($this->bus(new SentStamp('in-memory', 'async')), $this->locator([]), $strategy);

        $response = $processor->process(null, $this->declaredPost(), ['id' => 'b1']);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function a_sync_transport_write_keeps_the_sync_path(): void
    {
        // sync:// is routed AND handled in-process: its SentStamp names the SyncTransport sender,
        // the shape the real SendMessageMiddleware produces, so still a 204
        $processor = new ShelveBookProcessor(
            $this->bus(new SentStamp(SyncTransport::class, 'sync'), new HandledStamp(null, 'handler')),
            $this->locator([]),
        );

        $response = $processor->process(null, new Post, ['id' => 'b1']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function a_double_routed_write_answers_202_even_when_a_sync_route_also_handled_it(): void
    {
        // the shape the compile-time gate cannot see for every metadata source: routed to BOTH a
        // sync and a real transport, the envelope carries HandledStamp AND a broker-bound
        // SentStamp, and the queued copy flies toward a second execution; serving a fresh 200
        // here would present a half-applied write as settled, so the honest answer stays 202
        $strategy = $this->strategy(static fn (): ?object => self::fail('a write whose copy left the process must never await freshness'));

        $processor = new ShelveBookProcessor(
            $this->bus(
                new SentStamp(SyncTransport::class, 'sync'),
                new HandledStamp(null, 'handler'),
                new SentStamp('in-memory', 'async'),
            ),
            $this->locator([]),
            $strategy,
        );

        $response = $processor->process(null, $this->declaredPost(), ['id' => 'b1']);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function an_idempotency_key_becomes_a_deterministic_bus_identity(): void
    {
        // the replay contract: the same client key on the same command class must re-dispatch the
        // SAME message id, so the consumer inbox can skip-ack the retried copy of an async write;
        // and the id is bound to the command class, so one key on two endpoints never collides
        $request = Request::create('/books/b7/shelve', 'POST', server: ['HTTP_IDEMPOTENCY_KEY' => 'client-key-1']);

        $processor = new ShelveBookProcessor($this->bus(), $this->locator([]));
        $processor->process(null, new Post, ['id' => 'b7'], ['request' => $request]);
        $first = $this->dispatchedStamps;

        $processor->process(null, new Post, ['id' => 'b7'], ['request' => $request]);

        self::assertCount(1, $first);
        self::assertInstanceOf(MessageIdStamp::class, $first[0]);
        self::assertStringStartsWith('idem-', $first[0]->id);
        self::assertEquals($first, $this->dispatchedStamps, 'the replayed request carries the SAME bus identity');
    }

    #[Test]
    public function without_the_header_no_identity_is_imposed(): void
    {
        // no key, no opinion: the metadata middleware assigns its fresh id per dispatch, the
        // pre-existing behavior every non-replaying client keeps
        $request = Request::create('/books/b7/shelve', 'POST');

        $processor = new ShelveBookProcessor($this->bus(), $this->locator([]));
        $processor->process(null, new Post, ['id' => 'b7'], ['request' => $request]);

        self::assertSame([], $this->dispatchedStamps);
    }

    private function declaredPost(?string $strategy = null): Post
    {
        $declaration = ['command' => ShelveBook::class, 'projection' => 'book_catalog'];

        if ($strategy !== null) {
            $declaration['strategy'] = $strategy;
        }

        return new Post(extraProperties: [ReadAfterWrite::KEY => $declaration]);
    }

    /**
     * The in-memory command bus: records the dispatched command and returns the envelope the
     * real edge would see; identity stamps assigned by the bus middleware, plus whatever
     * outcome stamps the test scripts, such as `SentStamp` or `HandledStamp`.
     */
    private function bus(StampInterface ...$outcome): MessageBusInterface
    {
        $test = $this;

        return new readonly class($test, $outcome) implements MessageBusInterface
        {
            /**
             * @param  array<array-key, StampInterface>  $outcome
             */
            public function __construct(private CommandProcessorTest $test, private array $outcome) {}

            /**
             * @param  array<int, StampInterface>  $stamps
             */
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->test->recordDispatched($message, $stamps);

                return new Envelope($message, [new MessageIdStamp('m-1'), new CorrelationStamp('c-1'), ...$this->outcome]);
            }
        };
    }

    /**
     * @param  array<int, StampInterface>  $stamps
     */
    public function recordDispatched(object $message, array $stamps = []): void
    {
        $this->dispatched = $message;
        $this->dispatchedStamps = $stamps;
    }

    /**
     * @param  callable(WriteReceipt): ?object  $answer
     */
    private function strategy(callable $answer): FreshnessStrategy
    {
        return new readonly class($answer(...)) implements FreshnessStrategy
        {
            public function __construct(private Closure $answer) {}

            public function freshState(WriteReceipt $receipt, ReadAfterWrite $declaration, callable $reread): ?object
            {
                return ($this->answer)($receipt);
            }
        };
    }

    /**
     * @param  array<string, FreshnessStrategy>  $strategies
     */
    private function locator(array $strategies): ContainerInterface
    {
        return new readonly class($strategies) implements ContainerInterface
        {
            /**
             * @param  array<string, FreshnessStrategy>  $strategies
             */
            public function __construct(private array $strategies) {}

            public function get(string $id): FreshnessStrategy
            {
                return $this->strategies[$id] ?? throw new class(sprintf('strategy "%s" not wired', $id)) extends LogicException implements NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return isset($this->strategies[$id]);
            }
        };
    }

    /**
     * A container resolving the given id to an arbitrary object; unlike `locator()`, which only
     * ever holds real strategies, this models a service of the WRONG type wired under a valid name.
     */
    private function wrongTypeLocator(string $id, object $resolved): ContainerInterface
    {
        return new readonly class($id, $resolved) implements ContainerInterface
        {
            public function __construct(private string $id, private object $resolved) {}

            public function get(string $id): object
            {
                return $this->id === $id ? $this->resolved : throw new class(sprintf('strategy "%s" not wired', $id)) extends LogicException implements NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return $this->id === $id;
            }
        };
    }
}
