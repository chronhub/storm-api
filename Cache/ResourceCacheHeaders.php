<?php

declare(strict_types=1);

namespace Storm\Api\Cache;

use ApiPlatform\HttpCache\PurgerInterface;
use ApiPlatform\Metadata\Operation;
use LogicException;
use Storm\Api\Metadata\ResourceCache;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

use function is_object;
use function is_scalar;
use function sprintf;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * The response side of the declared cache contract: on a cacheable read whose operation
 * declares {@see ResourceCache}, stamp what the cache layers need:
 *
 *  - `ETag: "v<version>"` from the resource's declared version property, then answer 304 when
 *    If-None-Match already holds it. The version is read through the property accessor, so a
 *    private property behind a getter works like a public one. This is Symfony's own
 *    revalidation, needing no infra. A DECLARED property that cannot be read, or that is not
 *    scalar, is a wiring fault and fails loud: a silent skip would quietly disable revalidation
 *    forever;
 *
 *  - The projection's tag, IN THE CONFIGURED PURGER'S OWN PROTOCOL: the tag header's name and
 *    format belong to the purger through `PurgerInterface::getResponseHeaders()`; Souin speaks
 *    `Surrogate-Key`, Varnish speaks `Cache-Tags`; hardcoding one name would emit
 *    purges while no cached object ever carried the tag, silently stale until TTL. Values are
 *    MERGED with whatever a layer already set, never overwritten. With no purger wired the
 *    fallback is `Surrogate-Key`, Souin's protocol and the shipped default; purges are then
 *    nobody's job and the cache falls back to its TTL.
 *
 *  - `Vary` from the declaration's `vary` list, merged, whenever the projection tag is stamped:
 *    the tag is what invites a shared cache to store the response, and a role-shaped or
 *    owner-shaped representation keyed without its credential headers would be served to a
 *    different caller.
 *
 * The ETag rides items only: a collection has no single version, so its declared fallback is
 * the surrogate purge. The tag rides items and collections alike.
 *
 * The tag is only as good as the response's STORABILITY, which this listener does not own: it
 * belongs to the operation's `cacheHeaders`, to the app's defaults, or to the surrogate cache's
 * own configuration, and a Souin-class cache may store beyond what `Cache-Control` says. The
 * listener therefore stamps regardless of `isCacheable()`; until the app makes the response
 * storable somewhere, the purge tier is declared but inert and correctness rides revalidation
 * and TTL.
 */
#[AsEventListener]
final readonly class ResourceCacheHeaders
{
    /** Probe tags whose only job is to reveal, in the purger's own rendering, what sits between two tags. */
    private const string PROBE_A = 'storm-separator-probe-a';

    private const string PROBE_B = 'storm-separator-probe-b';

    private PropertyAccessorInterface $accessor;

    public function __construct(
        private ?PurgerInterface $purger = null,
        ?PropertyAccessorInterface $accessor = null,
    ) {
        $this->accessor = $accessor ?? PropertyAccess::createPropertyAccessor();
    }

    /**
     * @throws LogicException when the operation's cache declaration is present but malformed, or
     *                        its declared etag property cannot be read from the resource; a
     *                        wiring fault surfaced at response time, never silently skipped
     */
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        // main request only: the error sub-request rides its own operation, which today carries no
        // cache declaration, but that is API Platform's internal detail, not a guard to lean on
        if (! $event->isMainRequest() || ! $request->isMethodCacheable() || ! $response->isSuccessful()) {
            return;
        }

        $operation = $request->attributes->get('_api_operation');
        if (! $operation instanceof Operation) {
            return;
        }

        $cache = ResourceCache::fromOperation($operation);
        if ($cache === null) {
            return;
        }

        if ($cache->projection !== null) {
            foreach ($this->tagHeaders(ProjectionCommitPurger::tag($cache->projection)) as $name => $value) {
                // merge, never overwrite: API Platform's own tagging may already have written this header
                $existing = $response->headers->get($name);
                $response->headers->set($name, $existing === null || trim($existing) === '' ? $value : $existing.$this->tagSeparator($name).$value);
            }

            if ($cache->vary !== []) {
                // merged, never replaced: API Platform already varies on Accept, and the app may
                // vary on its own axes; the declared credential headers key the shared entry
                $response->setVary($cache->vary, false);
            }
        }

        if ($cache->etagProperty !== null) {
            $data = $request->attributes->get('data');

            if (is_object($data)) {
                if (! $this->accessor->isReadable($data, $cache->etagProperty)) {
                    throw new LogicException(sprintf(
                        'The declared etag_property "%s" is not readable on %s (no public property, no getter) — the declaration is broken, revalidation would silently never happen.',
                        $cache->etagProperty,
                        $data::class,
                    ));
                }

                $version = $this->accessor->getValue($data, $cache->etagProperty);

                if (! is_scalar($version)) {
                    throw new LogicException(sprintf(
                        'The declared etag_property "%s" on %s is %s — an ETag needs a scalar version.',
                        $cache->etagProperty,
                        $data::class,
                        get_debug_type($version),
                    ));
                }

                $response->setEtag('v'.$version);
                $response->isNotModified($request); // an If-None-Match hit mutates the response to 304 and strips the body
            }
        }
    }

    /**
     * The tag headers in the configured purger's protocol; `Surrogate-Key` when none is wired.
     *
     * @return array<string, string>
     */
    private function tagHeaders(string $tag): array
    {
        if ($this->purger === null) {
            return ['Surrogate-Key' => $tag];
        }

        return $this->purger->getResponseHeaders([$tag]);
    }

    /**
     * The separator between two tags, owned by the purger exactly as the header name is: read from
     * the purger's own rendering of two probe tags, never assumed from the name. Varnish's
     * `Cache-Tags` joins on a bare comma, Souin's `Surrogate-Key` on comma-space, xkey on a space;
     * a guessed separator writes the two halves of one header in two formats, and the mismatched
     * half is a tag the cache never indexes, purged into the void until TTL.
     */
    private function tagSeparator(string $name): string
    {
        if ($this->purger === null) {
            return ' '; // the fallback protocol's own separator; with no purger wired, purges are inert anyway
        }

        // the probes need not open the rendered value, and a purger that renders them in another
        // order reveals no separator: the second probe is searched in the tail past the first alone
        $rendered = $this->purger->getResponseHeaders([self::PROBE_A, self::PROBE_B])[$name];
        $first = strpos($rendered, self::PROBE_A);

        if ($first === false) {
            return ' '; // this header does not carry the tag list; the merge keeps the fallback
        }

        $tail = substr($rendered, $first + strlen(self::PROBE_A));
        $second = strpos($tail, self::PROBE_B);

        return $second === false ? ' ' : substr($tail, 0, $second);
    }
}
