<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Cache;

use ApiPlatform\HttpCache\PurgerInterface;
use ApiPlatform\Metadata\Get;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Api\Cache\ResourceCacheHeaders;
use Storm\Api\Metadata\ResourceCache;
use Storm\Api\Tests\Fixture\ArticleResource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ResourceCacheHeadersTest extends TestCase
{
    #[Test]
    public function the_declared_version_property_becomes_the_etag_and_the_projection_the_surrogate_key(): void
    {
        $event = $this->eventFor(declaration: ['etag_property' => 'title', 'projection' => 'articles']);

        (new ResourceCacheHeaders)($event);

        $response = $event->getResponse();
        self::assertSame('"vFresh"', $response->getEtag());
        self::assertSame('storm-projection-articles', $response->headers->get('Surrogate-Key'));
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function a_matching_if_none_match_mutates_the_response_to_304(): void
    {
        $event = $this->eventFor(declaration: ['etag_property' => 'title'], ifNoneMatch: '"vFresh"');

        (new ResourceCacheHeaders)($event);

        self::assertSame(304, $event->getResponse()->getStatusCode());
        self::assertSame('', (string) $event->getResponse()->getContent());
    }

    #[Test]
    public function an_undeclared_operation_or_uncacheable_method_passes_untouched(): void
    {
        $bare = $this->eventFor(declaration: null);
        (new ResourceCacheHeaders)($bare);
        self::assertNull($bare->getResponse()->getEtag());

        $post = $this->eventFor(declaration: ['etag_property' => 'title'], method: 'POST');
        (new ResourceCacheHeaders)($post);
        self::assertNull($post->getResponse()->getEtag());
    }

    #[Test]
    public function a_write_never_receives_cache_headers_even_under_a_full_declaration(): void
    {
        // a POST is a write, whatever the operation declares: caching its response would serve a
        // stale body to the next GET on the same URI. Both headers, not just the ETag, must be absent.
        $event = $this->eventFor(declaration: ['etag_property' => 'title', 'projection' => 'articles'], method: 'POST');

        (new ResourceCacheHeaders)($event);

        $response = $event->getResponse();
        self::assertNull($response->getEtag());
        self::assertFalse($response->headers->has('Surrogate-Key'));
    }

    #[Test]
    public function an_error_response_never_receives_cache_headers(): void
    {
        // a cacheable GET that came back non-2xx must never be tagged: caching an error would make
        // a transient failure sticky for every reader hitting the same URI.
        $event = $this->eventFor(declaration: ['etag_property' => 'title', 'projection' => 'articles'], responseStatus: 404);

        (new ResourceCacheHeaders)($event);

        $response = $event->getResponse();
        self::assertNull($response->getEtag());
        self::assertFalse($response->headers->has('Surrogate-Key'));
    }

    #[Test]
    public function a_declared_property_that_cannot_be_read_fails_loud(): void
    {
        // the silent skip disabled revalidation forever with zero signal; a declaration that
        // cannot work is a wiring fault, surfaced at first response
        $event = $this->eventFor(declaration: ['etag_property' => 'nope']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('not readable');

        (new ResourceCacheHeaders)($event);
    }

    #[Test]
    public function a_private_property_behind_a_getter_gets_its_etag(): void
    {
        // get_object_vars saw only PUBLIC properties: a perfectly serializable DTO with a private
        // version + getter silently got no ETag; the property accessor reads it like the serializer
        $dto = new class()
        {
            private int $version = 7;

            public function getVersion(): int
            {
                return $this->version;
            }
        };
        $event = $this->eventFor(declaration: ['etag_property' => 'version'], data: $dto);

        (new ResourceCacheHeaders)($event);

        self::assertSame('"v7"', $event->getResponse()->getEtag());
    }

    #[Test]
    public function the_tag_rides_the_configured_purgers_protocol_and_merges(): void
    {
        // the header's name and format belong to the purger: Varnish speaks Cache-Tags; the
        // hardcoded Surrogate-Key meant purges were emitted while no cached object carried the tag
        $varnishStyle = new class() implements PurgerInterface
        {
            public function purge(array $iris): void {}

            public function getResponseHeaders(array $iris): array
            {
                return ['Cache-Tags' => implode(',', $iris)];
            }
        };
        $event = $this->eventFor(declaration: ['projection' => 'articles']);
        $event->getResponse()->headers->set('Cache-Tags', 'existing');

        (new ResourceCacheHeaders($varnishStyle))($event);

        self::assertSame('existing,storm-projection-articles', $event->getResponse()->headers->get('Cache-Tags'));
        self::assertFalse($event->getResponse()->headers->has('Surrogate-Key'), 'the purger protocol replaces the fallback, never doubles it');
    }

    #[Test]
    public function the_merge_separator_is_the_purgers_own_not_a_guess_from_the_header_name(): void
    {
        // the untested half of the merge: Souin's purger joins Surrogate-Key on comma-space and its
        // cache splits on the comma, trimming each key. A space-joined merge fuses the last vendor
        // key and the storm tag into one token the cache never indexes, so every commit purge on the
        // projection tag matches nothing, silently stale until TTL
        $souinStyle = new class() implements PurgerInterface
        {
            public function purge(array $iris): void {}

            public function getResponseHeaders(array $iris): array
            {
                return ['Surrogate-Key' => implode(', ', $iris)];
            }
        };
        $event = $this->eventFor(declaration: ['projection' => 'articles']);
        $event->getResponse()->headers->set('Surrogate-Key', '/books/b1, /books/b2');

        (new ResourceCacheHeaders($souinStyle))($event);

        self::assertSame('/books/b1, /books/b2, storm-projection-articles', $event->getResponse()->headers->get('Surrogate-Key'));
    }

    #[Test]
    public function the_vary_merge_keeps_what_a_layer_already_declared(): void
    {
        // merged, never replaced: API Platform already varies on Accept, and a declared credential
        // axis REPLACING it would serve one negotiated representation to every Accept, the
        // cache-poisoning the merge flag exists to prevent
        $event = $this->eventFor(declaration: ['projection' => 'articles', 'vary' => ['Authorization']]);
        $event->getResponse()->setVary(['Accept']);

        (new ResourceCacheHeaders)($event);

        $vary = $event->getResponse()->getVary();
        self::assertContains('Accept', $vary, 'the pre-existing axis survives the merge');
        self::assertContains('Authorization', $vary, 'the declared axis lands beside it');
    }

    #[Test]
    public function a_purger_that_renders_no_tag_list_keeps_the_fallback_separator(): void
    {
        // the probe guard's own pin: a header the purger renders without the tags in it reveals no
        // separator, and the merge must fall back to the space, never slice a garbage separator out
        // of an unrelated value
        $opaque = new class() implements PurgerInterface
        {
            public function purge(array $iris): void {}

            public function getResponseHeaders(array $iris): array
            {
                return ['Surrogate-Key' => 'opaque-static-value'];
            }
        };
        $event = $this->eventFor(declaration: ['projection' => 'articles']);
        $event->getResponse()->headers->set('Surrogate-Key', 'existing');

        (new ResourceCacheHeaders($opaque))($event);

        self::assertSame('existing opaque-static-value', $event->getResponse()->headers->get('Surrogate-Key'));
    }

    #[Test]
    public function a_rendering_carrying_only_the_second_probe_keeps_the_fallback_too(): void
    {
        // half a probe pair reveals no separator either: without the first probe the tail has no
        // anchor, and slicing up to a second probe found in an unanchored tail would mint a garbage
        // separator out of the purger's own padding
        $halfProbed = new class() implements PurgerInterface
        {
            public function purge(array $iris): void {}

            public function getResponseHeaders(array $iris): array
            {
                return ['Surrogate-Key' => 'a-padding-longer-than-one-probe storm-separator-probe-b'];
            }
        };
        $event = $this->eventFor(declaration: ['projection' => 'articles']);
        $event->getResponse()->headers->set('Surrogate-Key', 'existing');

        (new ResourceCacheHeaders($halfProbed))($event);

        self::assertSame('existing a-padding-longer-than-one-probe storm-separator-probe-b', $event->getResponse()->headers->get('Surrogate-Key'));
    }

    #[Test]
    public function the_derived_separator_survives_a_purger_prefixing_its_header_value(): void
    {
        // the probes need not open the rendered value: a purger that prefixes its tag list anchors
        // the first probe past position zero, where sloppy slice arithmetic would return the wrong
        // cut and still look right on an unprefixed rendering
        $prefixing = new class() implements PurgerInterface
        {
            public function purge(array $iris): void {}

            public function getResponseHeaders(array $iris): array
            {
                return ['Surrogate-Key' => 'keys='.implode(', ', $iris)];
            }
        };
        $event = $this->eventFor(declaration: ['projection' => 'articles']);
        $event->getResponse()->headers->set('Surrogate-Key', '/books/b1');

        (new ResourceCacheHeaders($prefixing))($event);

        self::assertSame('/books/b1, keys=storm-projection-articles', $event->getResponse()->headers->get('Surrogate-Key'));
    }

    #[Test]
    public function a_cacheable_response_without_an_operation_is_left_untouched(): void
    {
        // a route with no _api_operation, outside API Platform's metadata, gives the listener nothing to
        // declare against: it must pass the response through, ETag-less, never guessing a cache identity.
        $event = $this->eventFor(declaration: ['etag_property' => 'title', 'projection' => 'articles'], withOperation: false);

        (new ResourceCacheHeaders)($event);

        self::assertNull($event->getResponse()->getEtag());
        self::assertFalse($event->getResponse()->headers->has('Surrogate-Key'));
    }

    #[Test]
    public function a_declared_property_that_is_not_scalar_fails_loud(): void
    {
        // a readable property whose value is an array or object cannot become an ETag; like the
        // unreadable case, a declaration that cannot work is a wiring fault surfaced at first response.
        $dto = new class()
        {
            /** @var list<string> */
            public array $tags = ['a', 'b'];
        };
        $event = $this->eventFor(declaration: ['etag_property' => 'tags'], data: $dto);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('needs a scalar version');

        (new ResourceCacheHeaders)($event);
    }

    /**
     * @param  array<string, list<string>|string>|null  $declaration
     */
    private function eventFor(?array $declaration, ?string $ifNoneMatch = null, string $method = 'GET', int $responseStatus = 200, ?object $data = null, bool $withOperation = true): ResponseEvent
    {
        $operation = $declaration === null
            ? new Get
            : new Get(extraProperties: [ResourceCache::KEY => $declaration]);

        $request = Request::create('/articles/a1', $method);
        if ($withOperation) {
            $request->attributes->set('_api_operation', $operation);
        }
        $request->attributes->set('data', $data ?? new ArticleResource('a1', 'Fresh'));

        if ($ifNoneMatch !== null) {
            $request->headers->set('If-None-Match', $ifNoneMatch);
        }

        $kernel = new class() implements HttpKernelInterface
        {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
            {
                throw new RuntimeException('the fake kernel never handles');
            }
        };

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('{"id":"a1"}', $responseStatus));
    }
}
