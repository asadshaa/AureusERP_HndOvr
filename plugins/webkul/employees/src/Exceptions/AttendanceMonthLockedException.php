<?php

namespace Webkul\Employee\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A write touched a finalised (locked) attendance month. A RuntimeException,
 * so the existing Filament actions that catch RuntimeException show its
 * message; JSON callers (API, imports over HTTP) get a 422 with the message
 * under "errors.attendance_date". Every other Livewire screen shows it as a
 * notification (EmployeeServiceProvider).
 */
class AttendanceMonthLockedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $companyId,
        public readonly string $month,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'message' => $this->getMessage(),
            'errors'  => ['attendance_date' => [$this->getMessage()]],
        ], 422);
    }
}
