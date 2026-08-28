<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture\InputSecurity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;

/**
 * The resource wiring the trap: its POST declares the secured custom input DTO.
 */
#[ApiResource(
    shortName: 'SecuredWrite',
    operations: [
        new Post(uriTemplate: '/secured-writes', input: SecuredInput::class),
    ],
)]
final class SecuredInputResource
{
    public string $id = '';
}
