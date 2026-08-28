<?php

declare(strict_types=1);

namespace Storm\Api\Freshness;

use LogicException;
use Storm\Api\Metadata\ReadAfterWrite;

use function get_debug_type;
use function is_object;
use function sprintf;

/**
 * The freshest state there is: the handler's own return, served without any re-read. Electing
 * this strategy is a contract on the app's handler, which returns a purpose-built result DTO
 * whose shape IS the public state, never a naked read model, since what leaves the app is
 * always mapped whatever the path. No projection, no wait, no timeout: the state was produced
 * inside the writer itself.
 */
final readonly class HandlerResultFreshness implements FreshnessStrategy
{
    /**
     * {@inheritDoc}
     *
     * @throws LogicException when the handler returned nothing usable; electing this strategy without returning a result DTO is a wiring fault
     */
    public function freshState(WriteReceipt $receipt, ReadAfterWrite $declaration, callable $reread): object
    {
        $result = $receipt->handlerResult;

        if (! is_object($result)) {
            throw new LogicException(sprintf('The command-result freshness strategy needs the "%s" handler to return the result DTO to serve; got %s.', $receipt->command::class, get_debug_type($result)));
        }

        return $result;
    }
}
