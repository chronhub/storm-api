<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Response;

use OpenApi\Attributes\Items;
use OpenApi\Attributes\Schema;
use OpenApi\Attributes\Property;
use OpenApi\Attributes\Response;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes\Components;
use OpenApi\Attributes\JsonContent;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

#[Components(
    schemas: [
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
            response: 500,
            description: 'Internal error',
            content: new JsonContent(ref: '#/components/schemas/Error')
        ),
    ]
)]
class ResponseFactory implements Responsable
{
    private ?string $message = null;

    private ?MessageBag $errors = null;

    private array $headers = [];

    private array $data = [];

    private int $status = 200;

    public function withStatusCode(int $status, ?string $message = null): self
    {
        $this->status = $status;
        $this->message = $message;

        return $this;
    }

    public function withHeader(string $header, mixed $value): self
    {
        $this->headers[$header] = $value;

        return $this;
    }

    public function withData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function withErrors(MessageBag $errors): self
    {
        $this->errors = $errors;

        return $this;
    }

    public function toResponse($request): JsonResponse
    {
        $payload = [];

        if (! empty($this->data)) {
            $payload = ['data' => $this->data];
        }

        if ($this->message) {
            $payload['message'] = $this->message;
        }

        if ($this->errors) {
            $payload['errors'] = $this->errors->toArray();
        }

        if ($this->isError()) {
            $payload['meta'] = $this->metadata();
        }

        return new JsonResponse($payload, $this->status, $this->headers);
    }

    private function isError(): bool
    {
        return $this->status >= 400 && $this->status < 600;
    }

    private function metadata(): array
    {
        return [
            'http_status' => $this->status.', '.SymfonyResponse::$statusTexts[$this->status],
            'log_reference' => 'todo',
            'links' => ['todo'],
        ];
    }
}
