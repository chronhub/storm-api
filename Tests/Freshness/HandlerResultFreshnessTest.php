<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Freshness;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Api\Freshness\HandlerResultFreshness;
use Storm\Api\Freshness\WriteReceipt;
use Storm\Api\Metadata\ReadAfterWrite;
use Storm\Api\Tests\Fixture\ShelveBook;
use Storm\Story\Stamp\CorrelationStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class HandlerResultFreshnessTest extends TestCase
{
    #[Test]
    public function serves_the_handler_result_without_rereading(): void
    {
        $result = new stdClass;
        $receipt = WriteReceipt::fromEnvelope(new Envelope(new ShelveBook('b1'), [
            new CorrelationStamp('c-1'),
            new HandledStamp($result, 'handler'),
        ]));
        $rereadCalled = false;

        $state = new HandlerResultFreshness()->freshState(
            $receipt,
            new ReadAfterWrite(command: ShelveBook::class),
            static function () use (&$rereadCalled): ?object {
                $rereadCalled = true;

                return null;
            },
        );

        self::assertSame($result, $state);
        self::assertFalse($rereadCalled, 'the command-result strategy never re-reads');
    }

    #[Test]
    public function a_void_handler_makes_electing_this_strategy_a_wiring_fault(): void
    {
        $receipt = WriteReceipt::fromEnvelope(new Envelope(new ShelveBook('b1'), [new HandledStamp(null, 'handler')]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('return the result DTO');

        new HandlerResultFreshness()->freshState($receipt, new ReadAfterWrite(command: ShelveBook::class), static fn (): ?object => null);
    }
}
