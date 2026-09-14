<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture\InputSecurityOverrides;

use ApiPlatform\Metadata\ApiProperty;

final class SafeInput
{
    #[ApiProperty(securityPostDenormalize: 'false')]
    public string $title = '';
}
