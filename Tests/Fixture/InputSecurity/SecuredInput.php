<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture\InputSecurity;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The trap fixture: a custom input DTO whose property claims a security expression API Platform
 * will never evaluate; the guard pass must refuse the build.
 */
final class SecuredInput
{
    public string $title = '';

    #[ApiProperty(security: "is_granted('ROLE_ADMIN')")]
    public string $adminNote = '';
}
