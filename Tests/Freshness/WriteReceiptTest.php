<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Freshness;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Api\Freshness\WriteReceipt;
use Storm\Api\Tests\Fixture\ShelveBook;
use Storm\Story\Stamp\CorrelationStamp;
use Storm\Story\Stamp\MessageIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class WriteReceiptTest extends TestCase
{
    #[Test]
    public function harvests_everything_the_returned_envelope_carries(): void
    {
        $result = new stdClass;
        $command = new ShelveBook('b1');
        $envelope = new Envelope($command, [
            new MessageIdStamp('msg-7'),
            new CorrelationStamp('corr-7'),
            new HandledStamp($result, 'handler'),
        ]);

        $receipt = WriteReceipt::fromEnvelope($envelope);

        self::assertSame($command, $receipt->command);
        self::assertSame('msg-7', $receipt->messageId);
        self::assertSame('corr-7', $receipt->correlationId);
        self::assertSame($result, $receipt->handlerResult);
    }

    #[Test]
    public function a_bare_envelope_yields_a_receipt_of_nulls(): void
    {
        $command = new ShelveBook('b1');
        $receipt = WriteReceipt::fromEnvelope(new Envelope($command));

        self::assertSame($command, $receipt->command);
        self::assertNull($receipt->messageId);
        self::assertNull($receipt->correlationId);
        self::assertNull($receipt->handlerResult);
    }

    #[Test]
    public function two_handlers_on_one_command_is_a_wiring_fault(): void
    {
        // two HandledStamps means Messenger's registration order silently decides which result the API
        // serves; a freshness-declared command must have exactly one handler: refused, never guessed.
        $envelope = new Envelope(new ShelveBook('b1'), [
            new HandledStamp(new stdClass, 'handler-a'),
            new HandledStamp(new stdClass, 'handler-b'),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('handled by 2 handlers');

        WriteReceipt::fromEnvelope($envelope);
    }
}
