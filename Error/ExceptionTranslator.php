<?php

declare(strict_types=1);

namespace Storm\Api\Error;

use ApiPlatform\Validator\Exception\ValidationException;
use Storm\Contracts\Chronicler\ConcurrencyException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Throwable;

/**
 * The single exception boundary of the bridge, on `kernel.exception` above API Platform's own
 * listener: one place unwraps Messenger's envelope and translates the framework boundaries into
 * their public problem shape, never each provider or processor. Three gestures, in order:
 *
 *  - UNWRAP, recursively: on a sync dispatch a handler failure travels wrapped in
 *    HandlerFailedException, a handler that itself dispatches or asks synchronously re-wraps, and
 *    a multi-handler wrapper holds one failure PER handler, so the walk scans every wrapped leaf
 *    at every depth; a middleware validation failure arrives naked, also accepted. Same recursive
 *    gesture as `QueryBus::askAllSettled` and the recover middleware.
 *
 *  - TRANSLATE the framework boundaries, by marker, never by concrete class: the
 *    ConcurrencyException marker becomes {@see ApiProblem::conflict()}, a 409 whose underlying
 *    versions never reach the body; Messenger's ValidationFailedException becomes API
 *    Platform's ValidationException carrying the same violation list, a 422 with `violations[]`,
 *    rendered natively. A sync conflict arrives on its FIRST occurrence: the bus-level replay
 *    `RecoverConcurrencyConflict` only drives worker redeliveries and passes straight through
 *    in-process; the bridge never retries either.
 *
 *  - LEAVE the rest naked: the app's domain exceptions become visible to their declared
 *    `exceptionToStatus`; everything undeclared, StorageFailure included, falls to API
 *    Platform's opaque 500 whose detail is masked outside debug.
 */
#[AsEventListener(priority: self::PRIORITY)]
final readonly class ExceptionTranslator
{
    /**
     * Above API Platform's exception listener, tagged at -96, so it sees the translated exception;
     * and above the default 0 an app's own listener gets, so the relative order is declared here
     * rather than left to registration luck.
     */
    public const int PRIORITY = 32;

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof HandlerFailedException) {
            $throwable = $this->unwrap($throwable);
        }

        $translated = $this->translate($throwable);

        if ($translated !== $event->getThrowable()) {
            $event->setThrowable($translated);
        }
    }

    /**
     * The leaf this boundary judges. A translatable marker ANYWHERE in the wrapped set wins over
     * an untranslatable first leaf: when the second of two handlers lost the version race, the
     * response is still the 409, since the resource did move under the request. With no marker
     * anywhere, the first leaf is exposed, which is what makes an app domain exception two sync
     * dispatches deep visible to its declared `exceptionToStatus`; a one-level `getPrevious()`
     * read would leave the inner WRAPPER naked and downgrade every such status to a 500.
     */
    private function unwrap(HandlerFailedException $wrapper): Throwable
    {
        $leaves = $wrapper->getWrappedExceptions(recursive: true);

        foreach ($leaves as $leaf) {
            if ($leaf instanceof ConcurrencyException || $leaf instanceof ValidationFailedException) {
                return $leaf;
            }
        }

        return array_first($leaves) ?? $wrapper;
    }

    private function translate(Throwable $throwable): Throwable
    {
        if ($throwable instanceof ConcurrencyException) {
            return ApiProblem::conflict($throwable);
        }

        if ($throwable instanceof ValidationFailedException) {
            return new ValidationException($throwable->getViolations(), previous: $throwable);
        }

        return $throwable;
    }
}
