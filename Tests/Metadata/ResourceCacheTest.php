<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Metadata;

use ApiPlatform\Metadata\Get;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Api\Metadata\ResourceCache;

final class ResourceCacheTest extends TestCase
{
    #[Test]
    public function an_operation_without_the_declaration_reads_as_null(): void
    {
        self::assertNull(ResourceCache::fromOperation(new Get));
    }

    #[Test]
    public function the_declaration_is_read_typed_with_optional_halves(): void
    {
        $full = ResourceCache::fromOperation(new Get(extraProperties: [ResourceCache::KEY => [
            'etag_property' => 'version',
            'projection' => 'book_catalog',
        ]]));

        self::assertNotNull($full);
        self::assertSame('version', $full->etagProperty);
        self::assertSame('book_catalog', $full->projection);

        $tagOnly = ResourceCache::fromOperation(new Get(extraProperties: [ResourceCache::KEY => [
            'projection' => 'book_catalog',
        ]]));

        self::assertNotNull($tagOnly);
        self::assertNull($tagOnly->etagProperty);
    }

    #[Test]
    public function the_vary_default_keys_the_shared_entry_by_credential(): void
    {
        // safe by default: a projection tag invites a shared cache to store, and the entry must
        // never serve a role-shaped body to a different caller unless the app SAYS its reads are
        // identity-free
        $declared = ResourceCache::fromOperation(new Get(extraProperties: [ResourceCache::KEY => [
            'projection' => 'book_catalog',
        ]]));

        self::assertNotNull($declared);
        self::assertSame(['Authorization', 'Cookie'], $declared->vary);
    }

    #[Test]
    public function an_identity_free_read_opts_out_of_the_vary_with_an_empty_list(): void
    {
        $declared = ResourceCache::fromOperation(new Get(extraProperties: [ResourceCache::KEY => [
            'projection' => 'book_catalog',
            'vary' => [],
        ]]));

        self::assertNotNull($declared);
        self::assertSame([], $declared->vary);
    }

    #[Test]
    public function vary_without_a_projection_is_a_wiring_fault(): void
    {
        // vary keys a SHARED entry, and only the projection tag invites one; a vary beside a bare
        // etag declaration protects nothing and reads as if it did
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/vary.*without.*projection/');

        ResourceCache::fromOperation(new Get(extraProperties: [ResourceCache::KEY => [
            'etag_property' => 'version',
            'vary' => ['Authorization'],
        ]]));
    }

    #[Test]
    public function a_malformed_vary_is_a_wiring_fault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/vary/');

        ResourceCache::fromOperation(new Get(extraProperties: [ResourceCache::KEY => [
            'projection' => 'book_catalog',
            'vary' => ['Authorization', ''],
        ]]));
    }

    #[Test]
    public function a_non_array_declaration_is_a_wiring_fault(): void
    {
        $this->expectException(LogicException::class);

        ResourceCache::fromOperation(new Get(extraProperties: [ResourceCache::KEY => 'version']));
    }

    #[Test]
    public function an_unknown_key_is_a_wiring_fault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('carries unknown key(s)');

        ResourceCache::fromOperation(new Get(extraProperties: [ResourceCache::KEY => [
            'etag_property' => 'version',
            'projectionn' => 'typo',
        ]]));
    }

    #[Test]
    public function a_non_string_etag_property_is_a_wiring_fault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('"etag_property" must be a non-empty string');

        ResourceCache::fromOperation(new Get(extraProperties: [ResourceCache::KEY => ['etag_property' => 123]]));
    }

    #[Test]
    public function a_non_string_projection_is_a_wiring_fault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('"projection" must be a non-empty string');

        ResourceCache::fromOperation(new Get(extraProperties: [ResourceCache::KEY => ['projection' => 123]]));
    }

    #[Test]
    public function a_declaration_of_neither_half_is_a_wiring_fault(): void
    {
        // an empty declaration caches nothing and purges nothing: drop it or fill it, never leave it inert
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('NEITHER etag_property NOR projection');

        ResourceCache::fromOperation(new Get(extraProperties: [ResourceCache::KEY => []]));
    }
}
