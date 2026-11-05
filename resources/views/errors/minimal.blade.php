{{--
    Shared layout for every error page (it replaces Laravel's own errors::minimal,
    so framework pages such as 401 and 402 get the same look).

    Sections: title, code, message (what happened), hint (what to do next),
    actions (buttons; defaults to "Go to dashboard" + "Go back").
    Self-contained on purpose: no Vite build, no database, no session needed.
--}}
@php
    $dashboardUrl = \Illuminate\Support\Facades\Route::has('filament.admin.pages.dashboard')
        ? route('filament.admin.pages.dashboard')
        : url('/');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">

        <title>@yield('title') · {{ config('app.name', 'Aureus ERP') }}</title>

        <link rel="icon" href="{{ asset('images/favicon.ico') }}">

        <script>
            // Follow the theme the user picked in the panel (Filament stores it as "theme").
            try {
                const theme = localStorage.getItem('theme');

                if (theme === 'dark' || theme === 'light') {
                    document.documentElement.dataset.theme = theme;
                }
            } catch (error) {}
        </script>

        <style>
            :root {
                --page: #f9fafb;
                --card: #ffffff;
                --border: #e5e7eb;
                --text: #111827;
                --muted: #4b5563;
                --soft: #6b7280;
                --primary: #2563eb;
                --primary-hover: #1d4ed8;
                --primary-text: #ffffff;
                --badge-bg: #eff6ff;
                --badge-text: #1d4ed8;
                --ghost-hover: #f3f4f6;
                color-scheme: light;
            }

            @media (prefers-color-scheme: dark) {
                :root:not([data-theme="light"]) {
                    --page: #030712;
                    --card: #111827;
                    --border: #1f2937;
                    --text: #f9fafb;
                    --muted: #d1d5db;
                    --soft: #9ca3af;
                    --primary: #3b82f6;
                    --primary-hover: #60a5fa;
                    --primary-text: #ffffff;
                    --badge-bg: rgba(59, 130, 246, 0.15);
                    --badge-text: #93c5fd;
                    --ghost-hover: #1f2937;
                    color-scheme: dark;
                }
            }

            :root[data-theme="dark"] {
                --page: #030712;
                --card: #111827;
                --border: #1f2937;
                --text: #f9fafb;
                --muted: #d1d5db;
                --soft: #9ca3af;
                --primary: #3b82f6;
                --primary-hover: #60a5fa;
                --primary-text: #ffffff;
                --badge-bg: rgba(59, 130, 246, 0.15);
                --badge-text: #93c5fd;
                --ghost-hover: #1f2937;
                color-scheme: dark;
            }

            *, *::before, *::after {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px 16px;
                background: var(--page);
                color: var(--text);
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                line-height: 1.5;
                -webkit-font-smoothing: antialiased;
            }

            .card {
                width: 100%;
                max-width: 480px;
                padding: 40px 32px 32px;
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 12px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
                text-align: center;
            }

            .logo {
                display: block;
                height: 32px;
                width: auto;
                margin: 0 auto 28px;
            }

            .logo-dark {
                display: none;
            }

            @media (prefers-color-scheme: dark) {
                :root:not([data-theme="light"]) .logo-light { display: none; }
                :root:not([data-theme="light"]) .logo-dark { display: block; }
            }

            :root[data-theme="dark"] .logo-light { display: none; }
            :root[data-theme="dark"] .logo-dark { display: block; }

            .badge {
                display: inline-block;
                margin-bottom: 12px;
                padding: 2px 10px;
                border-radius: 999px;
                background: var(--badge-bg);
                color: var(--badge-text);
                font-size: 12px;
                font-weight: 600;
                letter-spacing: 0.04em;
            }

            h1 {
                margin: 0 0 8px;
                font-size: 22px;
                font-weight: 700;
                line-height: 1.3;
            }

            .message {
                margin: 0 0 6px;
                color: var(--muted);
                font-size: 15px;
                overflow-wrap: anywhere;
            }

            .hint {
                margin: 0;
                color: var(--soft);
                font-size: 14px;
            }

            .actions {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 8px;
                margin-top: 28px;
            }

            .button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 40px;
                padding: 8px 16px;
                border-radius: 8px;
                border: 1px solid transparent;
                font: inherit;
                font-size: 14px;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
            }

            .button-primary {
                background: var(--primary);
                color: var(--primary-text);
            }

            .button-primary:hover {
                background: var(--primary-hover);
            }

            .button-ghost {
                background: transparent;
                color: var(--muted);
                border-color: var(--border);
            }

            .button-ghost:hover {
                background: var(--ghost-hover);
            }

            .button:focus-visible {
                outline: 2px solid var(--primary);
                outline-offset: 2px;
            }
        </style>
    </head>
    <body>
        <main class="card" role="main">
            <img class="logo logo-light" src="{{ asset('images/logo-full-light.svg') }}" alt="{{ config('app.name', 'Aureus ERP') }}">
            <img class="logo logo-dark" src="{{ asset('images/logo-full-dark.svg') }}" alt="" aria-hidden="true">

            @hasSection('code')
                <div class="badge">{{ __('Error') }} @yield('code')</div>
            @endif

            <h1>@yield('title')</h1>

            <p class="message">@yield('message')</p>

            @hasSection('hint')
                <p class="hint">@yield('hint')</p>
            @else
                <p class="hint">{{ __('Use the button below to get back to work.') }}</p>
            @endif

            <div class="actions">
                @hasSection('actions')
                    @yield('actions')
                @else
                    <a class="button button-primary" href="{{ $dashboardUrl }}">{{ __('Go to dashboard') }}</a>
                    <a class="button button-ghost" href="javascript:history.back()">{{ __('Go back') }}</a>
                @endif
            </div>
        </main>
    </body>
</html>
