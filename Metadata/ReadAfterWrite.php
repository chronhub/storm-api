<?php

declare(strict_types=1);

namespace Storm\Api\Metadata;

use ApiPlatform\Metadata\Operation;
use LogicException;

use function is_array;
use function is_string;
use function sprintf;

/**
 * The freshness contract of a write operation, typed: the operation DECLARES it under the
 * `self::KEY` extra property, a plain array so the declaration stays declarative in the
 * attribute and safe for the metadata cache, and this reader is the single place the shape is
 * validated and typed. The declaration names the command it promises fresh reads for, which
 * the compile-time gate crosses with the Messenger routing, and, for a re-reading strategy,
 * the projection whose catch-up defines "fresh".
 *
 * ```php
 * new Post(uriTemplate: '/books/{id}/publish', extraProperties: [ReadAfterWrite::KEY => [
 *     'command' => PublishBook::class,
 *     'projection' => 'book_catalog',
 * ]])
 * ```
 *
 * `strategy` names a FreshnessStrategy service id for the per-operation override; absent, the
 * app-level `FreshnessStrategy` alias decides. The app elects its default once; the framework
 * elects nothing, keeping the default open.
 */
final readonly class ReadAfterWrite
{
    public const string KEY = 'storm_read_after_write';

    public function __construct(
        /** @var class-string */
        public string $command,
        public ?string $projection = null,
        public float $timeoutSeconds = 5.0,
        /** @var positive-int */
        public int $pollMs = 50,
        public ?string $strategy = null,
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

        return self::fromArray($raw, $operation->getName() ?? $operation::class);
    }

    /** The declaration's closed shape; an unknown key is a typo, refused, never ignored. */
    private const array KNOWN_KEYS = ['command', 'projection', 'timeout_seconds', 'poll_ms', 'strategy'];

    /**
     * Strict by contract: a malformed declaration dies here, never laundered into a valid-looking
     * value that silently misbehaves at runtime:
     *
     *  - A non-numeric `timeout_seconds` is refused, not read as an immediate 0.0 timeout;
     *
     *  - A mistyped `projection` is refused, not a silently disabled wait;
     *
     *  - An unknown key is refused, not dropped.
     *
     * @throws LogicException when the declaration is malformed
     */
    public static function fromArray(mixed $raw, string $where): self
    {
        if (! is_array($raw)) {
            throw new LogicException(sprintf('The "%s" declaration on "%s" must be an array.', self::KEY, $where));
        }

        $unknown = array_diff(array_keys($raw), self::KNOWN_KEYS);
        if ($unknown !== []) {
            throw new LogicException(sprintf('The "%s" declaration on "%s" carries unknown key(s) "%s" — known keys: %s.', self::KEY, $where, implode('", "', array_map(strval(...), $unknown)), implode(', ', self::KNOWN_KEYS)));
        }

        $command = $raw['command'] ?? null;
        if (! is_string($command) || $command === '') {
            throw new LogicException(sprintf('The "%s" declaration on "%s" must name its "command" class — the freshness promise is per command.', self::KEY, $where));
        }
        if (! class_exists($command) && ! interface_exists($command)) {
            throw new LogicException(sprintf('The "%s" declaration on "%s" names command "%s", which does not exist.', self::KEY, $where, $command));
        }

        $projection = $raw['projection'] ?? null;
        if ($projection !== null && (! is_string($projection) || $projection === '')) {
            throw new LogicException(sprintf('The "%s" declaration on "%s": "projection" must be a non-empty string, got %s.', self::KEY, $where, get_debug_type($projection)));
        }

        $timeout = $raw['timeout_seconds'] ?? 5.0;
        if (! (is_int($timeout) || is_float($timeout)) || ! is_finite((float) $timeout) || (float) $timeout < 0.0) {
            throw new LogicException(sprintf('The "%s" declaration on "%s": "timeout_seconds" must be a finite non-negative number, got %s.', self::KEY, $where, get_debug_type($timeout)));
        }

        $poll = $raw['poll_ms'] ?? 50;
        if (! is_int($poll) || $poll < 1) {
            throw new LogicException(sprintf('The "%s" declaration on "%s": "poll_ms" must be a positive integer, got %s.', self::KEY, $where, is_int($poll) ? (string) $poll : get_debug_type($poll)));
        }

        $strategy = $raw['strategy'] ?? null;
        if ($strategy !== null && (! is_string($strategy) || $strategy === '')) {
            throw new LogicException(sprintf('The "%s" declaration on "%s": "strategy" must be a non-empty string, got %s.', self::KEY, $where, get_debug_type($strategy)));
        }

        return new self(
            command: $command,
            projection: $projection,
            timeoutSeconds: (float) $timeout,
            pollMs: $poll,
            strategy: $strategy,
        );
    }
}
