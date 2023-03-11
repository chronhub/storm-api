<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Exception;

use Throwable;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Chronhub\Storm\Http\Api\Response\ResponseFactory;
use Chronhub\Storm\Chronicler\Exceptions\StreamNotFound;
use Chronhub\Storm\Projector\Exceptions\ProjectionNotFound;
use Chronhub\Storm\Chronicler\Exceptions\StreamAlreadyExists;

final readonly class ApiExceptionHandler
{
    public function __construct(private ResponseFactory $response,
                                private bool $debug = false)
    {
    }

    public function handle(Throwable $exception, Request $request): ResponseFactory
    {
        if ($this->debug) {
            $this->response->withData($this->convertException($exception));
        }

        $statusCode = $this->determineStatusCode($exception);

        $messageName = 500 === $statusCode ? 'Something went wrong' : $exception->getMessage();

        return $this->response->withStatusCode($statusCode, $messageName);
    }

    private function determineStatusCode(Throwable $exception): int
    {
        return match ($exception::class) {
            AuthenticationException::class => 401,
            AuthorizationException::class => 403,
            ProjectionNotFound::class, StreamNotFound::class => 404,
            StreamAlreadyExists::class => 419,
            default => 500
        };
    }

    private function convertException(Throwable $exception): array
    {
        $trace = new Collection($exception->getTrace());

        return [
            'debug' => [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $trace->map(fn (array $trace): array => Arr::except($trace, ['args']))->all(),
            ],
        ];
    }
}
