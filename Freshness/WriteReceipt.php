<?php

declare(strict_types=1);

namespace Storm\Api\Freshness;

use LogicException;
use Storm\Story\Stamp\CorrelationStamp;
use Storm\Story\Stamp\MessageIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The write witness, harvested at the bus edge right after a sync dispatch: everything the
 * returned envelope actually carries, namely the command's class and trace identity, and the
 * handler's return when it produced one. Deliberately WITHOUT a position or version token: the
 * append surfaces none today, which is why the at-head strategy waits on the head instead of a
 * token. Extensible by construction; a token lands here the day the append surfaces one,
 * breaking nothing.
 */
final readonly class WriteReceipt
{
    private function __construct(
        /** The dispatched command itself, the identity source telling a re-read which row to fetch, not just a class name. */
        public object $command,
        public ?string $messageId,
        public ?string $correlationId,
        public mixed $handlerResult,
    ) {}

    /**
     * @throws LogicException when the envelope carries more than one `HandledStamp`; two handlers
     *                        on one command means Messenger's registration order silently decides
     *                        which result the API serves, a wiring bug refused
     */
    public static function fromEnvelope(Envelope $envelope): self
    {
        $handled = $envelope->all(HandledStamp::class);

        if (count($handled) > 1) {
            throw new LogicException(sprintf(
                'Command %s was handled by %d handlers — the API cannot know which result to serve; a freshness-declared command must have exactly one handler.',
                $envelope->getMessage()::class,
                count($handled),
            ));
        }

        return new self(
            $envelope->getMessage(),
            $envelope->last(MessageIdStamp::class)?->id,
            $envelope->last(CorrelationStamp::class)?->id,
            $envelope->last(HandledStamp::class)?->getResult(),
        );
    }
}
