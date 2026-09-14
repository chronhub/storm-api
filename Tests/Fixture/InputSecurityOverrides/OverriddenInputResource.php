<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture\InputSecurityOverrides;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Storm\Api\Tests\Fixture\InputSecurity\SecuredInput;

#[ApiResource(
    input: SecuredInput::class,
    operations: [
        new Post(uriTemplate: '/disabled-input', input: false),
        new Post(uriTemplate: '/safe-input', input: SafeInput::class),
        new Post(uriTemplate: '/self-input', input: self::class),
    ],
)]
final class OverriddenInputResource
{
    #[ApiProperty(security: 'false')]
    public string $id = '';
}
