<?php

declare(strict_types=1);

namespace Storm\Api\Freshness;

use LogicException;
use Storm\Api\Metadata\ReadAfterWrite;
use Storm\Contracts\Chronicler\StorageFailure;
use Storm\Contracts\Projector\ProjectionFreshness;

/**
 * Wait until the declared projection has caught up to the HEAD, then re-read. Token-less
 * read-your-writes: the head is the one measured when the wait begins, so catching up covers every
 * write committed before that instant, ours included; the trade is that the wait rides the
 * projection's whole lag, not just our write, since the receipt carries no position token by
 * design. Bounded by the declared timeout; behind at the deadline it returns null, which the
 * processor turns into an honest 202.
 */
final readonly class AtHeadFreshness implements FreshnessStrategy
{
    public function __construct(
        private ProjectionFreshness $projections,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws LogicException when the operation declared no projection; this strategy has nothing to wait on
     * @throws StorageFailure when the safe-head or checkpoint read fails at the storage level
     */
    public function freshState(WriteReceipt $receipt, ReadAfterWrite $declaration, callable $reread): ?object
    {
        $projection = $declaration->projection
            ?? throw new LogicException(sprintf('The at-head freshness strategy needs the "%s" declaration to name its "projection" — there is nothing to wait on for command "%s".', ReadAfterWrite::KEY, $declaration->command));

        if (! $this->projections->waitForHead($projection, $declaration->timeoutSeconds, $declaration->pollMs)) {
            return null;
        }

        return $reread();
    }
}
