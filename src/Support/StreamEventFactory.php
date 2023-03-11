<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Support;

use Chronhub\Storm\Message\Message;
use Chronhub\Storm\Contracts\Message\MessageFactory;
use Chronhub\Storm\Contracts\Serializer\StreamEventSerializer;
use function is_array;

final readonly class StreamEventFactory implements MessageFactory
{
    public function __construct(private StreamEventSerializer $eventSerializer)
    {
    }

    public function __invoke(object|array $message): Message
    {
        if (is_array($message)) {
            $message = $this->eventSerializer->unserializeContent($message)->current();
        }

        if ($message instanceof Message) {
            return new Message($message->event(), $message->headers());
        }

        return new Message($message);
    }
}
