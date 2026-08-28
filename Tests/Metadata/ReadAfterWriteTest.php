<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Metadata;

use ApiPlatform\Metadata\Post;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Api\Metadata\ReadAfterWrite;
use Storm\Api\Tests\Fixture\ShelveBook;

final class ReadAfterWriteTest extends TestCase
{
    #[Test]
    public function an_operation_without_the_declaration_reads_as_null(): void
    {
        self::assertNull(ReadAfterWrite::fromOperation(new Post));
    }

    #[Test]
    public function a_full_declaration_is_read_typed(): void
    {
        $declaration = ReadAfterWrite::fromOperation(new Post(extraProperties: [ReadAfterWrite::KEY => [
            'command' => ShelveBook::class,
            'projection' => 'book_catalog',
            'timeout_seconds' => 2.5,
            'poll_ms' => 20,
            'strategy' => 'App\\OwnStrategy',
        ]]));

        self::assertNotNull($declaration);
        self::assertSame(ShelveBook::class, $declaration->command);
        self::assertSame('book_catalog', $declaration->projection);
        self::assertSame(2.5, $declaration->timeoutSeconds);
        self::assertSame(20, $declaration->pollMs);
        self::assertSame('App\\OwnStrategy', $declaration->strategy);
    }

    #[Test]
    public function only_the_command_is_required_the_rest_defaults(): void
    {
        $declaration = ReadAfterWrite::fromOperation(new Post(extraProperties: [ReadAfterWrite::KEY => [
            'command' => ShelveBook::class,
        ]]));

        self::assertNotNull($declaration);
        self::assertNull($declaration->projection);
        self::assertSame(5.0, $declaration->timeoutSeconds);
        self::assertSame(50, $declaration->pollMs);
        self::assertNull($declaration->strategy);
    }

    #[Test]
    public function a_declaration_without_its_command_is_a_wiring_fault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('must name its "command"');

        ReadAfterWrite::fromOperation(new Post(extraProperties: [ReadAfterWrite::KEY => ['projection' => 'x']]));
    }

    #[Test]
    public function a_non_array_declaration_is_a_wiring_fault(): void
    {
        $this->expectException(LogicException::class);

        ReadAfterWrite::fromOperation(new Post(extraProperties: [ReadAfterWrite::KEY => true]));
    }

    #[Test]
    public function an_unknown_key_is_a_wiring_fault(): void
    {
        // a typo'd key is refused, not silently dropped; the promise it meant to make never happens
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('carries unknown key(s)');

        ReadAfterWrite::fromOperation(new Post(extraProperties: [ReadAfterWrite::KEY => [
            'command' => ShelveBook::class,
            'projectionn' => 'typo',
        ]]));
    }

    #[Test]
    public function a_command_that_does_not_exist_is_a_wiring_fault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('does not exist');

        ReadAfterWrite::fromOperation(new Post(extraProperties: [ReadAfterWrite::KEY => ['command' => 'App\\NoSuchCommand']]));
    }

    #[Test]
    public function a_non_string_projection_is_a_wiring_fault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('"projection" must be a non-empty string');

        ReadAfterWrite::fromOperation(new Post(extraProperties: [ReadAfterWrite::KEY => [
            'command' => ShelveBook::class,
            'projection' => 123,
        ]]));
    }

    #[Test]
    public function a_non_numeric_timeout_is_a_wiring_fault(): void
    {
        // refused, not read as an immediate 0.0 timeout that would answer 202 before the read even tried
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('"timeout_seconds" must be a finite non-negative number');

        ReadAfterWrite::fromOperation(new Post(extraProperties: [ReadAfterWrite::KEY => [
            'command' => ShelveBook::class,
            'timeout_seconds' => 'soon',
        ]]));
    }

    #[Test]
    public function a_non_positive_poll_interval_is_a_wiring_fault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('"poll_ms" must be a positive integer');

        ReadAfterWrite::fromOperation(new Post(extraProperties: [ReadAfterWrite::KEY => [
            'command' => ShelveBook::class,
            'poll_ms' => 0,
        ]]));
    }

    #[Test]
    public function a_blank_strategy_is_a_wiring_fault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('"strategy" must be a non-empty string');

        ReadAfterWrite::fromOperation(new Post(extraProperties: [ReadAfterWrite::KEY => [
            'command' => ShelveBook::class,
            'strategy' => '',
        ]]));
    }
}
