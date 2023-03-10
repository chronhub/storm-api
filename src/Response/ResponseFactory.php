<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Response;

use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Contracts\Support\Responsable;

class ResponseFactory implements Responsable
{
    private ?MessageBag $errors = null;

    private ?string $message = null;

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

        return new JsonResponse($payload, $this->status, $this->headers);
    }
}
