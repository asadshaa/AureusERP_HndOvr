<?php

namespace Webkul\Security\Http\Controllers;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Webkul\Security\Services\CurrentCompany;

/** Topbar company switcher: makes another of the user's own companies the active one. */
class SwitchCompanyController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate(['company_id' => ['required', 'integer']]);

        try {
            CurrentCompany::switchTo((int) $validated['company_id']);
        } catch (RuntimeException $e) {
            abort(403, $e->getMessage());
        }

        Notification::make()->success()->title('Switched company')->send();

        return redirect()->to(Filament::getPanel('admin')->getUrl());
    }
}
