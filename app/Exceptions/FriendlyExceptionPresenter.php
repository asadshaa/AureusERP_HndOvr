<?php

namespace App\Exceptions;

use ErrorException;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Decides what a non-technical user may be told about an exception.
 *
 * Business-rule exceptions thrown by the application's own code (the many
 * RuntimeException / InvalidArgumentException / LogicException throw sites in
 * app/ and plugins/webkul) carry messages written for people, so they are shown
 * as they are. Everything else — database errors, PHP errors, exceptions raised
 * inside vendor packages — is "unexpected": the user only gets a generic
 * sentence, never SQL, class names or file paths.
 */
class FriendlyExceptionPresenter
{
    public const GENERIC_TITLE = 'Something went wrong.';

    public const GENERIC_BODY = 'It has been logged; please try again or contact the administrator.';

    public const NOT_ALLOWED = 'You do not have permission to do that.';

    public const RECORD_MISSING = 'That record could not be found. It may have been deleted, or you may not have access to it.';

    /**
     * First-party namespaces whose exceptions are written for end users.
     *
     * @var list<string>
     */
    protected const FIRST_PARTY_NAMESPACES = ['App\\', 'Webkul\\'];

    /**
     * Exceptions the framework, Livewire or Filament already turn into the right
     * response (field errors, redirects, halted actions, the login page, ...).
     */
    public function shouldPassThrough(Throwable $exception): bool
    {
        if (
            $exception instanceof ValidationException
            || $exception instanceof Halt
            || $exception instanceof Cancel
            || $exception instanceof HttpResponseException
            || $exception instanceof AuthenticationException
            || $exception instanceof TokenMismatchException
        ) {
            return true;
        }

        return $exception instanceof HttpExceptionInterface
            && ! in_array($exception->getStatusCode(), [403, 404], true);
    }

    /**
     * The sentence to show the user, or null when the exception is unexpected
     * and only the generic message may be shown.
     */
    public function userMessage(Throwable $exception): ?string
    {
        if ($exception instanceof ModelNotFoundException) {
            return self::RECORD_MISSING;
        }

        if ($exception instanceof AuthorizationException) {
            return $this->authorizationMessage($exception->getMessage());
        }

        if ($exception instanceof HttpExceptionInterface) {
            return match ($exception->getStatusCode()) {
                403     => $this->authorizationMessage($exception->getMessage()),
                404     => $this->safeMessage($exception->getMessage()) ?? self::RECORD_MISSING,
                default => null,
            };
        }

        return $this->domainMessage($exception);
    }

    /**
     * The message of a business-rule exception thrown by first-party code, or
     * null for anything else.
     */
    public function domainMessage(Throwable $exception): ?string
    {
        if (
            ! $exception instanceof \Exception
            || $exception instanceof PDOException
            || $exception instanceof ErrorException
            || $this->shouldPassThrough($exception)
            || ! $this->isFirstPartyClass($exception)
            || $this->wasThrownInsideVendor($exception)
        ) {
            return null;
        }

        return $this->safeMessage($exception->getMessage());
    }

    /**
     * Returns the message only when it is plain language: never SQL, file
     * paths, stack traces or overly long technical dumps.
     */
    public function safeMessage(?string $message): ?string
    {
        $message = trim((string) $message);

        if ($message === '' || mb_strlen($message) > 500) {
            return null;
        }

        $technicalPatterns = '/SQLSTATE|\bselect\s.+\sfrom\b|\binsert\s+into\b|\bdelete\s+from\b|\bupdate\s+`|'
            .'[A-Za-z]:\\\\|(^|[\s(\'"])\/(var|home|usr|srv|opt|tmp|app)\/|\.php\b|stack trace|vendor[\\\\\/]|'
            .'Call to (undefined|a member)|Undefined (array key|variable|property)/i';

        if (preg_match($technicalPatterns, $message) === 1) {
            return null;
        }

        return $message;
    }

    protected function authorizationMessage(?string $message): string
    {
        $message = $this->safeMessage($message);

        if ($message === null || in_array($message, ['This action is unauthorized.', 'Forbidden'], true)) {
            return self::NOT_ALLOWED;
        }

        return $message;
    }

    /**
     * SPL exceptions (RuntimeException, InvalidArgumentException, ...) live in
     * the global namespace; the application's own ones under App\ or Webkul\.
     */
    protected function isFirstPartyClass(Throwable $exception): bool
    {
        $class = $exception::class;

        if (! str_contains($class, '\\')) {
            return true;
        }

        foreach (self::FIRST_PARTY_NAMESPACES as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    protected function wasThrownInsideVendor(Throwable $exception): bool
    {
        $file = str_replace('\\', '/', $exception->getFile());
        $vendorPath = str_replace('\\', '/', base_path('vendor')).'/';

        return str_starts_with(strtolower($file), strtolower($vendorPath));
    }
}
