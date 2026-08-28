<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture\FreshTopology;

use ApiPlatform\Metadata\ApiResource;
use Storm\Api\Metadata\ReadAfterWrite;
use Storm\Api\Tests\Compiler\GuardFreshnessTopologyPassTest;
use Storm\Api\Tests\Fixture\ShelveBook;

/**
 * A resource declaring a fresh-read promise on {@see ShelveBook}, in a directory scanned ONLY by
 * {@see GuardFreshnessTopologyPassTest}. The declaration sits on the RESOURCE, whose
 * extraProperties are read straight off the raw attribute with no metadata pipeline involved, so
 * whether the promise is a lie depends ENTIRELY on how the test's container routes the command;
 * the same fixture drives both the compiling and the refusing topologies.
 */
#[ApiResource(
    shortName: 'FreshCatalogBook',
    extraProperties: [ReadAfterWrite::KEY => [
        'command' => ShelveBook::class,
        'projection' => 'book_catalog',
    ]],
)]
final class FreshCatalogResource
{
    public string $id = '';

    public string $title = '';
}
