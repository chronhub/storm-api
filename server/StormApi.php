<?php

declare(strict_types=1);

use OpenApi\Attributes\Tag;
use OpenApi\Attributes\Info;
use OpenApi\Attributes\Server;
use OpenApi\Attributes\Contact;

#[
    Info(
        version: '1.0.0',
        description: 'Storm API',
        title: 'Storm API',
        contact: new Contact(name: 'Chronhub', email: 'chronhubgit@gmail.com')
    ),
    Server(url: 'http://storm-api.dvl.to:80', description: 'Local server'),
    Tag(name: 'Stream', description: 'Stream operations'),
    Tag(name: 'Projection', description: 'Projection operations'),
]
final readonly class StormApi
{
    //
}
