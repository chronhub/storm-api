<?php

declare(strict_types=1);

namespace Storm\Api\Freshness;

use Storm\Api\Metadata\ReadAfterWrite;

/**
 * The pluggable mechanism of a fresh read after a sync write: given the write witness and the
 * operation's declaration, produce the FRESH public state, or `null` when freshness could not
 * be established within the declared bounds. Null is an answer the processor turns into an
 * honest 202, never a lie served as 200.
 *
 * The PROVENANCE of the state is the strategy's own business: the shipped `AtHeadFreshness`
 * waits for the declared projection then re-reads through `$reread`; `HandlerResultFreshness`
 * serves the handler's own return without re-reading; an app strategy may poll a row version or
 * fold the stream inline. Each is an implementation of this seam, elected by the app through a
 * container alias or per operation through the declaration's `strategy`.
 */
interface FreshnessStrategy
{
    /**
     * Freshness is established from the write witness and the operation's declaration.
     *
     * @param  callable(): ?object  $reread  re-reads the resource's CURRENT public state, the
     *                                       processor's refresh hook; null when the row is not there
     * @return object|null the fresh public state to serve, or null when not fresh in time
     */
    public function freshState(WriteReceipt $receipt, ReadAfterWrite $declaration, callable $reread): ?object;
}
