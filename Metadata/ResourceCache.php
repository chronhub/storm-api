<?php

declare(strict_types=1);

namespace Storm\Api\Metadata;

use ApiPlatform\Metadata\Operation;
use LogicException;

use function is_array;
use function is_string;
use function sprintf;

/**
 * The cache contract of a read operation, typed: the operation declares under `self::KEY` what
 * the bridge may derive from the served state, the same array-shape and single-typed-reader
 * motif as {@see ReadAfterWrite}.
 *
 * ```php
 * new Get(uriTemplate: '/books/{id}', extraProperties: [ResourceCache::KEY => [
 *     'etag_property' => 'version',      // resource property carrying the row version, drives ETag and 304
 *     'projection' => 'book_catalog',    // projection this read model belongs to, sets the surrogate tag
 * ]])                                    // purged at that projection's commit, coarse by design
 * ```
 *
 * `etag_property` names the version column the exposed read model carries, the same column a
 * version-polling freshness strategy rides; one convention, declared once, two uses.
 * `projection` opts the response into the projector-driven purge: every commit of that
 * projection purges every response tagged with it, the advertised coarse price; per-key
 * precision is a deferred Projector task.
 *
 * `vary` rides the projection tag alone, since the tag is what invites a SHARED cache to store
 * the response: it lists the request headers a shared entry must be keyed by, defaulting to
 * `Authorization` and `Cookie`, so a role-shaped or owner-shaped representation never gets
 * served to a different caller once an app makes the response storable. An API whose reads are
 * genuinely identity-free declares `vary: []`, the explicit opt-out; declaring `vary` without
 * `projection` is refused, a vary with no shared entry to protect.
 */
final readonly class ResourceCache
{
    public const string KEY = 'storm_cache';

    /**
     * @param  list<string>  $vary
     */
    public function __construct(
        public ?string $etagProperty = null,
        public ?string $projection = null,
        public array $vary = ['Authorization', 'Cookie'],
    ) {}

    /**
     * Read and type the declaration off an operation; null when the operation declares nothing.
     *
     * @throws LogicException when the declaration is present but malformed; a wiring fault, not a request error
     */
    public static function fromOperation(Operation $operation): ?self
    {
        $raw = $operation->getExtraProperties()[self::KEY] ?? null;

        if ($raw === null) {
            return null;
        }

        $where = $operation->getName() ?? $operation::class;

        if (! is_array($raw)) {
            throw new LogicException(sprintf('The "%s" declaration on "%s" must be an array.', self::KEY, $where));
        }

        $unknown = array_diff(array_keys($raw), ['etag_property', 'projection', 'vary']);
        if ($unknown !== []) {
            throw new LogicException(sprintf('The "%s" declaration on "%s" carries unknown key(s) "%s" — known keys: etag_property, projection, vary.', self::KEY, $where, implode('", "', array_map(strval(...), $unknown))));
        }

        $etagProperty = $raw['etag_property'] ?? null;
        if ($etagProperty !== null && (! is_string($etagProperty) || $etagProperty === '')) {
            throw new LogicException(sprintf('The "%s" declaration on "%s": "etag_property" must be a non-empty string, got %s.', self::KEY, $where, get_debug_type($etagProperty)));
        }

        $projection = $raw['projection'] ?? null;
        if ($projection !== null && (! is_string($projection) || $projection === '')) {
            throw new LogicException(sprintf('The "%s" declaration on "%s": "projection" must be a non-empty string, got %s.', self::KEY, $where, get_debug_type($projection)));
        }

        if ($etagProperty === null && $projection === null) {
            throw new LogicException(sprintf('The "%s" declaration on "%s" declares NEITHER etag_property NOR projection — an empty declaration caches nothing and purges nothing; drop it or fill it.', self::KEY, $where));
        }

        $vary = $raw['vary'] ?? null;
        if ($vary !== null) {
            if ($projection === null) {
                throw new LogicException(sprintf('The "%s" declaration on "%s" declares "vary" without "projection" — vary keys a SHARED cache entry, and only the projection tag invites one; drop it or declare the projection.', self::KEY, $where));
            }

            if (! is_array($vary) || ! array_all($vary, static fn ($header): bool => is_string($header) && $header !== '')) {
                throw new LogicException(sprintf('The "%s" declaration on "%s": "vary" must be a list of non-empty header names, got %s.', self::KEY, $where, get_debug_type($vary)));
            }
        }

        return new self(
            etagProperty: $etagProperty,
            projection: $projection,
            vary: $vary === null ? ['Authorization', 'Cookie'] : array_values($vary),
        );
    }
}
