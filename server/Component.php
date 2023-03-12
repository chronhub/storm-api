<?php

declare(strict_types=1);

use OpenApi\Attributes\Items;
use OpenApi\Attributes\Schema;
use OpenApi\Attributes\Property;
use OpenApi\Attributes\Response;
use OpenApi\Attributes\Components;
use OpenApi\Attributes\JsonContent;

#[Components(
    schemas: [

        // StreamEvents

        new Schema(
            schema: 'StreamEventsData',
            required: ['data'],
            properties: [
                new Property(
                    property: 'data',
                    ref: '#/components/schemas/StreamEvents',
                    type: 'object',
                ),
            ],
            type: 'object',
        ),

        new Schema(
            schema: 'StreamEvents',
            required: ['no', 'headers', 'content'],
            properties: [
                new Property(
                    property: 'no',
                    ref: '#/components/schemas/StreamEventNo',
                    type: 'object',
                ),
                new Property(
                    property: 'headers',
                    ref: '#/components/schemas/StreamEventHeaders',
                    type: 'object',
                ),
                new Property(
                    property: 'content',
                    ref: '#/components/schemas/StreamEventContent',
                    type: 'object',
                ),
            ],
            type: 'object',
        ),

        new Schema(
            schema: 'StreamEventNo',
            required: ['no'],
            properties: [
                new Property(property: 'no', type: 'integer'),
            ],
            type: 'object',
            additionalProperties: true,
        ),
        new Schema(
            schema: 'StreamEventHeaders',
            required: [
                '__event_id',
                '__event_time',
                '__event_type',
                '__aggregate_id',
                '__aggregate_id_type',
                '__aggregate_type',
                '__aggregate_version',
            ],
            properties: [
                new Property(property: '__event_id', type: 'string', format: 'uuid'),
                new Property(property: '__event_time', type: 'string', format: 'date-time'),
                new Property(property: '__event_type', type: 'string'),
                new Property(property: '__aggregate_id', type: 'string', format: 'uuid'),
                new Property(property: '__aggregate_id_type', type: 'string'),
                new Property(property: '__aggregate_type', type: 'string'),
                new Property(property: '__aggregate_version', type: 'integer', minimum: 1),
                new Property(property: '__internal_position', type: 'integer', minimum: 1),
                new Property(property: '__event_causation_id', type: 'string', format: 'uuid'),
                new Property(property: '__event_causation_type', type: 'string'),
            ],
            type: 'object',
            additionalProperties: true,
        ),

        new Schema(
            schema: 'StreamEventContent',
            type: 'object',
        ),

        // Errors

        new Schema(
            schema: 'Error',
            properties: [
                new Property(property: 'message', type: 'string'),
                new Property(property: 'code', type: 'integer'),
            ],
            type: 'object',
        ),

        new Schema(
            schema: 'ValidationError',
            properties: [
                new Property(property: 'message', type: 'string'),
                new Property(property: 'errors', type: 'array', items: new Items(type: 'string')),
                new Property(property: 'code', type: 'integer'),
            ],
            type: 'object',
        ),
    ],
    responses: [
        new Response(
            response: 400,
            description: 'Bad request',
            content: new JsonContent(ref: '#/components/schemas/ValidationError')
        ),
        new Response(
            response: 401,
            description: 'Authentication failed',
            content: new JsonContent(ref: '#/components/schemas/Error')
        ),
        new Response(
            response: 403,
            description: 'Authorization failed',
            content: new JsonContent(ref: '#/components/schemas/Error')
        ),
        new Response(
            response: 'StreamNotFound',
            description: 'Stream not found, either stream name does not exists or no more stream events to retrieve',
            content: new JsonContent(ref: '#/components/schemas/Error')
        ),
        new Response(
            response: 'ProjectionNotFound',
            description: 'Projection not found',
            content: new JsonContent(ref: '#/components/schemas/Error')
        ),
        new Response(
            response: 'StreamAlreadyExists',
            description: 'Stream already exists',
            content: new JsonContent(ref: '#/components/schemas/Error')
        ),
        new Response(
            response: 500,
            description: 'Internal error',
            content: new JsonContent(ref: '#/components/schemas/Error')
        ),
    ]
)]
final readonly class Component
{
}
