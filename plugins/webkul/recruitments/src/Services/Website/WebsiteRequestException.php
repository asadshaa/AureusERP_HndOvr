<?php

namespace Webkul\Recruitment\Services\Website;

use RuntimeException;
use Throwable;

/**
 * A call to the careers website failed. The message is safe to show and to
 * store (no credentials) and says what to check.
 */
class WebsiteRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
