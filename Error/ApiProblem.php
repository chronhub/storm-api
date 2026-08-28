<?php

declare(strict_types=1);

namespace Storm\Api\Error;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Override;
use RuntimeException;
use Throwable;

/**
 * The bridge's own RFC 9457 problem: an exception that CARRIES its public shape of type,
 * title, status, and detail, so API Platform's error machinery renders it through
 * {@see \ApiPlatform\Metadata\Exception\ProblemExceptionInterface} instead of leaking `getMessage()`
 * into `detail` as it does for a plain exception. Only the bridge throws it, and the domain never learns HTTP;
 * the original domain exception rides as `previous` for server-side logs, never the body.
 *
 * Named factories are the whole public surface: each is one problem genre with a stable `type`
 * URI and a body vetted so nothing sensitive ever reaches the client.
 *
 * Sealed on purpose, and named for the FAMILY rather than its one current genre: the vetting is
 * only verifiable while construction stays behind the factories, and the n-th genre is one more
 * named factory, never a subclass. An app-authored genre implements `ProblemExceptionInterface`
 * itself; that interface is API Platform's, so the extension seam already exists outside this class.
 */
final class ApiProblem extends RuntimeException implements ProblemExceptionInterface
{
    private function __construct(
        private readonly string $type,
        private readonly string $title,
        private readonly int $status,
        private readonly string $detail,
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail, 0, $previous);
    }

    /**
     * The optimistic-concurrency conflict, publicly: "replay against the current state". The
     * expected and actual versions of the underlying conflict never reach the body.
     */
    public static function conflict(?Throwable $previous = null): self
    {
        return new self(
            type: '/errors/conflict',
            title: 'Conflict',
            status: 409,
            detail: 'The resource changed while the request was being decided; replay the request against its current state.',
            previous: $previous,
        );
    }

    #[Override]
    public function getType(): string
    {
        return $this->type;
    }

    #[Override]
    public function getTitle(): string
    {
        return $this->title;
    }

    #[Override]
    public function getStatus(): int
    {
        return $this->status;
    }

    #[Override]
    public function getDetail(): string
    {
        return $this->detail;
    }

    #[Override]
    public function getInstance(): ?string
    {
        return null;
    }
}
