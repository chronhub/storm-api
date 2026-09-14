<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture\InputSecurity;

use ApiPlatform\Metadata\ApiProperty;

/**
 * Declares property security on a custom input that the build policy refuses.
 */
final class SecuredInput
{
    public string $title = '';

    #[ApiProperty(security: "is_granted('ROLE_ADMIN')")]
    public string $adminNote = '';
}
