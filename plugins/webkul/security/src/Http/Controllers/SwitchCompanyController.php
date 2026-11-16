<?php

namespace Webkul\Security\Http\Controllers;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Webkul\Security\Services\CurrentCompany;

/** Company switcher: makes another of the user's own companies the active one, then returns to the page it was used on (or Home). */
class SwitchCompanyController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
            'return_to'  => ['nullable', 'string', 'max:300'],
        ]);

        try {
            CurrentCompany::switchTo((int) $validated['company_id']);
        } catch (RuntimeException $e) {
            abort(403, $e->getMessage());
        }

        Notification::make()->success()->title('Switched company')->send();

        return redirect()->to(self::safeReturnTo($validated['return_to'] ?? null) ?? Filament::getPanel('admin')->getUrl());
    }

    /** Only a path inside the admin panel (no host, no protocol-relative link) may be returned to. */
    private static function safeReturnTo(?string $path): ?string
    {
        if ($path === null || ! preg_match('#^/admin(/[A-Za-z0-9._~\-/]*)?$#', $path) || str_contains($path, '//') || str_contains($path, '..')) {
            return null;
        }

        return url($path);
    }
}
