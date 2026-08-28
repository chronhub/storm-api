<?php

declare(strict_types=1);

namespace Storm\Api\Tests\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Closure;
use DomainException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Api\Tests\Fixture\ArticleProvider;
use Storm\Api\Tests\Fixture\ArticleResource;
use Storm\Api\Tests\Fixture\ArticleView;
use Storm\Api\Tests\Fixture\FindArticle;
use Storm\Story\QueryBus;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Throwable;

final class QueryProviderTest extends TestCase
{
    #[Test]
    public function an_item_read_carries_the_uri_variable_into_the_query_and_maps_the_read_model(): void
    {
        $provider = $this->providerHandling(static function (object $query): ?ArticleView {
            if (! $query instanceof FindArticle) {
                return null;
            }

            return $query->id === 'a1' ? new ArticleView('a1', 'Storm ships an API bridge') : null;
        });

        $resource = $provider->provide(new Get, ['id' => 'a1']);

        self::assertInstanceOf(ArticleResource::class, $resource);
        self::assertSame('a1', $resource->id);
        self::assertSame('Storm ships an API bridge', $resource->title);
    }

    #[Test]
    public function a_missing_item_stays_null_for_the_native_404(): void
    {
        $provider = $this->providerHandling(static fn (object $query): ?ArticleView => null);

        self::assertNull($provider->provide(new Get, ['id' => 'unknown']));
    }

    #[Test]
    public function a_collection_is_rekeyed_as_a_list_and_mapped_item_by_item(): void
    {
        // string keys on purpose: the base must re-key, or the JSON body would become an object
        $provider = $this->providerHandling(static fn (object $query): array => [
            'first' => new ArticleView('a1', 'One'),
            'second' => new ArticleView('a2', 'Two'),
        ]);

        $resources = $provider->provide(new GetCollection);

        self::assertSame([0, 1], array_keys($resources));
        self::assertSame(['a1', 'a2'], array_map(static fn (ArticleResource $r): string => $r->id, $resources));
    }

    #[Test]
    public function a_lazy_collection_is_drained_and_mapped_the_same_way(): void
    {
        $provider = $this->providerHandling(static function (object $query): iterable {
            yield 'x' => new ArticleView('a1', 'One');
            yield 'y' => new ArticleView('a2', 'Two');
        });

        $resources = $provider->provide(new GetCollection);

        self::assertSame([0, 1], array_keys($resources));
        self::assertSame(['One', 'Two'], array_map(static fn (ArticleResource $r): string => $r->title, $resources));
    }

    #[Test]
    public function a_collection_handler_answering_a_non_iterable_is_named_as_the_wiring_fault(): void
    {
        // the collection route wired onto an ITEM handler: one read model comes back where a list
        // was owed. Left alone it becomes a TypeError inside array_map, naming the symptom in the
        // bridge's own frame; the refusal names the operation the app declared and what it got
        $provider = $this->providerHandling(static fn (object $query): ArticleView => new ArticleView('a1', 'One'));

        try {
            $provider->provide(new GetCollection(name: 'articles_list'));
            self::fail('a non-iterable collection answer must be refused');
        } catch (LogicException $e) {
            self::assertStringContainsString('"articles_list"', $e->getMessage(), 'the operation is named, so the wiring is findable');
            self::assertStringContainsString(ArticleView::class, $e->getMessage(), 'and what came back instead');
            self::assertStringContainsString('collection read must answer an iterable', $e->getMessage());
        }
    }

    #[Test]
    public function an_unnamed_collection_operation_is_identified_by_its_class_rather_than_by_nothing(): void
    {
        // an operation declared without a name answers null to getName(), and a message quoting an
        // empty pair of quotes would send an operator looking through every collection route; a
        // handler answering null for an empty collection is the likeliest way to arrive here
        $provider = $this->providerHandling(static fn (object $query): ?ArticleView => null);

        try {
            $provider->provide(new GetCollection);
            self::fail('a non-iterable collection answer must be refused');
        } catch (LogicException $e) {
            self::assertStringContainsString(GetCollection::class, $e->getMessage());
            self::assertStringContainsString('returned null', $e->getMessage(), 'null is a wiring fault here, never an empty collection');
        }
    }

    #[Test]
    public function a_handler_failure_travels_out_still_wrapped_for_the_error_layer(): void
    {
        $boom = new DomainException('the read model is gone');

        $provider = $this->providerHandling(static fn (object $query): never => throw $boom);

        try {
            $provider->provide(new Get, ['id' => 'a1']);
            self::fail('the wrapped handler failure should have surfaced');
        } catch (HandlerFailedException $e) {
            // the base never unwraps: one unwrapping point, the error mapping layer
            self::assertSame($boom, $e->getPrevious());
        }
    }

    /**
     * The provider under test over an in-memory bus mapping each asked query to a result, or
     * throwing as Messenger would, wrapped in HandlerFailedException.
     *
     * @param  callable(object): mixed  $handle
     */
    private function providerHandling(callable $handle): ArticleProvider
    {
        $bus = new readonly class($handle) implements MessageBusInterface
        {
            private Closure $handle;

            public function __construct(callable $handle)
            {
                $this->handle = $handle(...);
            }

            /**
             * @param  array<int, StampInterface>  $stamps
             */
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                try {
                    $result = ($this->handle)($message);
                } catch (Throwable $e) {
                    throw new HandlerFailedException(new Envelope($message), [$e]);
                }

                return new Envelope($message, [new HandledStamp($result, 'handler')]);
            }
        };

        return new ArticleProvider(new QueryBus($bus));
    }
}
