<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Cache;

use ApiPlatform\HttpCache\PurgerInterface;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Storm\Api\Cache\ProjectionCommitPurger;
use Stringable;

final class ProjectionCommitPurgerTest extends TestCase
{
    #[Test]
    public function a_commit_purges_the_projection_surrogate_tag(): void
    {
        $recorder = $this->recorder();

        new ProjectionCommitPurger($recorder)->committed('book_catalog');

        self::assertSame([['storm-projection-book_catalog']], $recorder->purged);
    }

    #[Test]
    public function no_purger_wired_is_a_noop(): void
    {
        new ProjectionCommitPurger()->committed('book_catalog');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function a_failing_purger_is_swallowed_and_logged_never_thrown(): void
    {
        $logger = new class() extends AbstractLogger
        {
            /** @var list<string> */
            public array $logged = [];

            /**
             * @param  array<string, mixed>  $context
             */
            #[Override]
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->logged[] = (string) $message;
            }
        };

        $broken = new class() implements PurgerInterface
        {
            /**
             * @param  string[]  $iris
             */
            public function purge(array $iris): void
            {
                throw new RuntimeException('souin is down');
            }

            /**
             * @param  string[]  $iris
             * @return array<string, string>
             */
            public function getResponseHeaders(array $iris): array
            {
                return [];
            }
        };

        // the fire-and-forget clause of the port: a purger down must never fail the projection
        new ProjectionCommitPurger($broken, $logger)->committed('book_catalog');

        self::assertCount(1, $logger->logged);
        self::assertStringContainsString('purge failed', $logger->logged[0]);
    }

    #[Test]
    public function a_failing_logger_on_top_of_a_failing_purger_is_swallowed_too(): void
    {
        // the last-resort diagnostic must not become the throw it reports: with the purger down AND
        // the logger down, committed() still returns; the runner shields itself regardless, this is
        // the implementation's redundant half of the same clause
        $hostileLogger = new class() extends AbstractLogger
        {
            /**
             * @param  array<string, mixed>  $context
             */
            #[Override]
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        };

        $broken = new class() implements PurgerInterface
        {
            /**
             * @param  string[]  $iris
             */
            public function purge(array $iris): void
            {
                throw new RuntimeException('souin is down');
            }

            /**
             * @param  string[]  $iris
             * @return array<string, string>
             */
            public function getResponseHeaders(array $iris): array
            {
                return [];
            }
        };

        new ProjectionCommitPurger($broken, $hostileLogger)->committed('book_catalog');

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return PurgerInterface&object{purged: list<string[]>}
     */
    private function recorder(): PurgerInterface
    {
        return new class() implements PurgerInterface
        {
            /** @var list<string[]> */
            public array $purged = [];

            /**
             * @param  string[]  $iris
             */
            public function purge(array $iris): void
            {
                $this->purged[] = $iris;
            }

            /**
             * @param  string[]  $iris
             * @return array<string, string>
             */
            public function getResponseHeaders(array $iris): array
            {
                return [];
            }
        };
    }
}
