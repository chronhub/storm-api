<?php

declare(strict_types=1);

use OpenApi\Attributes\Tag;
use OpenApi\Attributes\Info;
use OpenApi\Attributes\Server;
use OpenApi\Attributes\Contact;
use OpenApi\Attributes\ServerVariable;

#[
    Info(
        version: '1.0.0',
        description: 'Storm API',
        title: 'Storm API',
        contact: new Contact(name: 'Chronhub', email: 'chronhubgit@gmail.com')
    ),

    Server(
        url: '{scheme}://{url}/{base_path}',
        description: 'Local server',
        variables: [
            new ServerVariable(
                serverVariable: 'scheme',
                description: 'scheme url',
                default: 'http'
            ),

            new ServerVariable(
                serverVariable: 'url',
                description: 'server url',
                default: 'storm-api.dvl.to'
            ),

            new ServerVariable(
                serverVariable: 'base_path',
                description: 'base path',
                default: 'api/v1/storm'
            ),
        ],
    ),

    Tag(name: 'Stream', description: 'Stream operations'),
    Tag(name: 'Projection', description: 'Projection operations'),
]
final readonly class StormApi
{
    //
}
