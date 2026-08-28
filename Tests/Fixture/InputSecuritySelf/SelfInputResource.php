<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture\InputSecuritySelf;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;

/**
 * The legitimate sibling: the resource IS its own input, and a property security expression on
 * the resource is evaluated on the read side, a shaping tool the guard must tolerate.
 */
#[ApiResource(
    shortName: 'SelfWrite',
    operations: [
        new Post(uriTemplate: '/self-writes', input: SelfInputResource::class),
    ],
)]
final class SelfInputResource
{
    public string $id = '';

    #[ApiProperty(security: "is_granted('ROLE_ADMIN')")]
    public string $adminNote = '';
}
