<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture;

use ApiPlatform\Metadata\Operation;
use Override;
use Storm\Api\Freshness\WriteReceipt;
use Storm\Api\State\CommandProcessor;

/**
 * What an app processor looks like: build the command from the route, re-read through the
 * app's own query. The re-read answer is scripted by the test.
 *
 * @extends CommandProcessor<object>
 */
final class ShelveBookProcessor extends CommandProcessor
{
    public ?object $rereadState = null;

    #[Override]
    protected function commandFor(mixed $data, Operation $operation, array $uriVariables, array $context): object
    {
        return new ShelveBook((string) ($uriVariables['id'] ?? 'b1'));
    }

    #[Override]
    protected function refresh(WriteReceipt $receipt, Operation $operation, array $uriVariables, array $context): ?object
    {
        return $this->rereadState;
    }
}
