<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Freshness;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Api\Freshness\AtHeadFreshness;
use Storm\Api\Freshness\WriteReceipt;
use Storm\Api\Metadata\ReadAfterWrite;
use Storm\Api\Tests\Fixture\FakeProjectionFreshness;
use Storm\Api\Tests\Fixture\ShelveBook;
use Symfony\Component\Messenger\Envelope;

final class AtHeadFreshnessTest extends TestCase
{
    #[Test]
    public function serves_the_reread_state_once_the_projection_is_at_head(): void
    {
        $port = new FakeProjectionFreshness(pollsUntilAtHead: 0);
        $strategy = new AtHeadFreshness($port);
        $fresh = new stdClass;

        $state = $strategy->freshState($this->receipt(), $this->declaration(), static fn (): object => $fresh);

        self::assertSame($fresh, $state);
        // the declared bounds reached the wait; the declaration drives the strategy, not defaults
        self::assertSame([['book_catalog', 2.0, 25]], $port->headWaits);
    }

    #[Test]
    public function answers_null_without_rereading_when_the_projection_stays_behind(): void
    {
        $port = new FakeProjectionFreshness(pollsUntilAtHead: 1000);
        $strategy = new AtHeadFreshness($port);
        $rereadCalled = false;

        $state = $strategy->freshState($this->receipt(), $this->declaration(), static function () use (&$rereadCalled): ?object {
            $rereadCalled = true;

            return null;
        });

        self::assertNull($state);
        self::assertFalse($rereadCalled, 'a stale projection must never trigger the re-read');
    }

    #[Test]
    public function a_declaration_without_projection_is_a_wiring_fault(): void
    {
        $strategy = new AtHeadFreshness(new FakeProjectionFreshness);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('nothing to wait on');

        $strategy->freshState($this->receipt(), new ReadAfterWrite(command: ShelveBook::class), static fn (): ?object => null);
    }

    private function receipt(): WriteReceipt
    {
        return WriteReceipt::fromEnvelope(new Envelope(new ShelveBook('b1')));
    }

    private function declaration(): ReadAfterWrite
    {
        return new ReadAfterWrite(command: ShelveBook::class, projection: 'book_catalog', timeoutSeconds: 2.0, pollMs: 25);
    }
}
