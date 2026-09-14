<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture\InputSecurityInherited;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Storm\Api\Tests\Fixture\InputSecurity\SecuredInput;

#[ApiResource(input: SecuredInput::class, operations: [new Post(uriTemplate: '/inherited-input')], )]
final class InheritedInputResource
{
    public string $id = '';
}
