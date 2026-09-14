<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture\InputSecurityGenerated;

use ApiPlatform\Metadata\ApiResource;
use Storm\Api\Tests\Fixture\InputSecurity\SecuredInput;

#[ApiResource(input: SecuredInput::class, )]
final class GeneratedInputResource
{
    public string $id = '';
}
