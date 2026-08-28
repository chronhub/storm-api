<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Error;

use ApiPlatform\Validator\Exception\ValidationException;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Storm\Api\Error\ApiProblem;
use Storm\Api\Error\ExceptionTranslator;
use Storm\Api\Tests\Fixture\StaleShelfVersion;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Throwable;

use function str_contains;

final class ExceptionTranslatorTest extends TestCase
{
    #[Test]
    public function a_wrapped_handler_failure_is_unwrapped_to_its_own_exception(): void
    {
        $boom = new DomainException('the shelf is closed');

        $event = $this->eventFor(new HandlerFailedException(new Envelope(new stdClass), [$boom]));
        (new ExceptionTranslator)($event);

        self::assertSame($boom, $event->getThrowable());
    }

    #[Test]
    public function a_naked_exception_passes_through_untouched(): void
    {
        $boom = new DomainException('the shelf is closed');

        $event = $this->eventFor($boom);
        (new ExceptionTranslator)($event);

        self::assertSame($boom, $event->getThrowable());
    }

    #[Test]
    public function the_concurrency_marker_becomes_the_safe_conflict_problem(): void
    {
        $conflict = new StaleShelfVersion('expected version 5, stream is at 7');

        $event = $this->eventFor(new HandlerFailedException(new Envelope(new stdClass), [$conflict]));
        (new ExceptionTranslator)($event);

        $problem = $event->getThrowable();
        self::assertInstanceOf(ApiProblem::class, $problem);
        self::assertSame(409, $problem->getStatus());
        self::assertSame('/errors/conflict', $problem->getType());
        self::assertSame('Conflict', $problem->getTitle());

        // the versions of the underlying conflict never reach the public shape
        self::assertFalse(str_contains($problem->getDetail(), 'version'));

        // but the original rides as previous, for the server-side log only
        self::assertSame($conflict, $problem->getPrevious());
    }

    #[Test]
    public function a_wrapped_messenger_validation_failure_becomes_the_api_platform_validation_exception(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('The title must not be cursed.', null, [], null, 'title', 'cursed'),
        ]);
        $failed = new ValidationFailedException(new stdClass, $violations);

        $event = $this->eventFor(new HandlerFailedException(new Envelope(new stdClass), [$failed]));
        (new ExceptionTranslator)($event);

        $translated = $event->getThrowable();
        self::assertInstanceOf(ValidationException::class, $translated);
        self::assertSame($violations, $translated->getConstraintViolationList());
    }

    #[Test]
    public function a_naked_messenger_validation_failure_is_translated_the_same_way(): void
    {
        // the validation middleware throws BEFORE any handler runs; no envelope to unwrap
        $violations = new ConstraintViolationList([
            new ConstraintViolation('The title must not be cursed.', null, [], null, 'title', 'cursed'),
        ]);

        $event = $this->eventFor(new ValidationFailedException(new stdClass, $violations));
        (new ExceptionTranslator)($event);

        $translated = $event->getThrowable();
        self::assertInstanceOf(ValidationException::class, $translated);
        self::assertSame($violations, $translated->getConstraintViolationList());
    }

    #[Test]
    public function a_conflict_nested_under_a_sync_dispatch_still_becomes_the_409(): void
    {
        // the nested shape a handler produces by dispatching or asking synchronously: the failure
        // arrives double-wrapped, and a one-level unwrap would leave the INNER wrapper naked, a
        // 500 where the contract documents a 409
        $conflict = new StaleShelfVersion('expected version 5, stream is at 7');
        $inner = new HandlerFailedException(new Envelope(new stdClass), [$conflict]);

        $event = $this->eventFor(new HandlerFailedException(new Envelope(new stdClass), [$inner]));
        (new ExceptionTranslator)($event);

        $problem = $event->getThrowable();
        self::assertInstanceOf(ApiProblem::class, $problem);
        self::assertSame(409, $problem->getStatus());
        self::assertSame($conflict, $problem->getPrevious());
    }

    #[Test]
    public function a_domain_exception_nested_under_a_sync_dispatch_is_exposed_to_its_declared_status(): void
    {
        // no marker anywhere: the first LEAF is exposed, never the inner wrapper, so an app
        // domain exception two dispatches deep keeps its declared exceptionToStatus mapping
        $boom = new DomainException('the shelf is closed');
        $inner = new HandlerFailedException(new Envelope(new stdClass), [$boom]);

        $event = $this->eventFor(new HandlerFailedException(new Envelope(new stdClass), [$inner]));
        (new ExceptionTranslator)($event);

        self::assertSame($boom, $event->getThrowable());
    }

    #[Test]
    public function a_conflict_raised_by_the_second_of_two_handlers_is_still_the_409(): void
    {
        // getPrevious() on a multi-handler wrapper is only the FIRST failure; the scan reads every
        // wrapped leaf, and the translatable marker wins over the untranslatable first leaf, since
        // the resource did move under the request whichever handler saw it
        $conflict = new StaleShelfVersion('expected version 5, stream is at 7');

        $event = $this->eventFor(new HandlerFailedException(
            new Envelope(new stdClass),
            [new DomainException('the shelf is closed'), $conflict],
        ));
        (new ExceptionTranslator)($event);

        $problem = $event->getThrowable();
        self::assertInstanceOf(ApiProblem::class, $problem);
        self::assertSame(409, $problem->getStatus());
        self::assertSame($conflict, $problem->getPrevious());
    }

    #[Test]
    public function an_unmapped_infrastructure_failure_stays_naked_for_the_opaque_500(): void
    {
        $boom = new RuntimeException('SQLSTATE[08006] connection refused at pg://storm:secret@db');

        $event = $this->eventFor(new HandlerFailedException(new Envelope(new stdClass), [$boom]));
        (new ExceptionTranslator)($event);

        self::assertSame($boom, $event->getThrowable());
    }

    private function eventFor(Throwable $throwable): ExceptionEvent
    {
        $kernel = new class() implements HttpKernelInterface
        {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
            {
                throw new RuntimeException('the fake kernel never handles');
            }
        };

        return new ExceptionEvent($kernel, Request::create('/books/b1'), HttpKernelInterface::MAIN_REQUEST, $throwable);
    }
}
