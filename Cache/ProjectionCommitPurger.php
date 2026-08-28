<?php

declare(strict_types=1);

namespace Storm\Api\Cache;

use ApiPlatform\HttpCache\PurgerInterface;
use Psr\Log\LoggerInterface;
use Storm\Contracts\Projector\ProjectionCommitListener;
use Throwable;

/**
 * Projector-driven cache invalidation: the runner fires the contracted post-commit hook the
 * moment a read model visibly changed, and this purger translates it into one surrogate-tag
 * purge through API Platform's own {@see \ApiPlatform\HttpCache\PurgerInterface}. The app aliases that interface to
 * its configured purger, for example `api_platform.http_cache.purger.souin` with the
 * Souin/Varnish integration shipped vendor-side.
 *
 * Coarse by design, the advertised price: every response carrying the projection's surrogate
 * key, set by {@see ResourceCacheHeaders}, is purged on any commit of that projection. Correct
 * by construction, since the purge follows real visibility; per-key precision needs the
 * projection to report its touched keys, a deferred Projector task.
 *
 * Degradation is the contract: with no purger wired the hook is a no-op and the cache falls
 * back to its TTL; a purger failure is logged and swallowed. A side-channel outage must NEVER
 * fail a projection, the fire-and-forget clause of the port.
 *
 * Collected through the contracted listener tag beside any sibling listener; installing this
 * bundle claims no exclusive slot.
 */
final readonly class ProjectionCommitPurger implements ProjectionCommitListener
{
    public function __construct(
        private ?PurgerInterface $purger = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * The surrogate key a projection's responses carry; shared with the response tagger.
     */
    public static function tag(string $projection): string
    {
        return 'storm-projection-'.$projection;
    }

    public function committed(string $projection): void
    {
        if ($this->purger === null) {
            return;
        }

        try {
            $this->purger->purge([self::tag($projection)]);
        } catch (Throwable $e) {
            // the last-resort diagnostic must not become the throw it reports: a logger outage
            // riding a purger outage is swallowed here, a redundant defense; the runner shields
            // itself regardless, per the port's fire-and-forget clause enforced by its owner
            try {
                $this->logger?->error('storm api: cache purge failed after projection commit — stale entries live until their TTL', [
                    'projection' => $projection,
                    'exception' => $e,
                ]);
            } catch (Throwable) {
            }
        }
    }
}
