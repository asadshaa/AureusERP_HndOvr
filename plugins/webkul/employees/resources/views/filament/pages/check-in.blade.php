<x-filament-panels::page>
    @php
        $state = $this->getTodayState();
        $remote = ($state['mode'] ?? null) === 'remote';
        $checkedIn = ($state['state'] ?? null) === 'checked_in';
        $checkedOut = ($state['state'] ?? null) === 'checked_out';
        $noLocation = ($state['mode'] ?? null) === 'none' && ! $checkedIn;
        $today = now($state['timezone'] ?? config('attendance.monthly_report.timezone', 'Asia/Karachi'))->format('l, j F');
    @endphp

    <style>
        .ci-wrap { display: flex; flex-direction: column; align-items: center; gap: 1.25rem; padding: .5rem 0 2rem; text-align: center; }
        .ci-date { font-size: .85rem; opacity: .7; }
        .ci-chip { display: inline-flex; align-items: center; gap: .45rem; border-radius: 999px; padding: .3rem .8rem; font-size: .8rem; font-weight: 600; border: 1px solid rgba(127,127,127,.25); }
        .ci-dot { width: .55rem; height: .55rem; border-radius: 999px; background: #9ca3af; }
        .ci-chip-in .ci-dot { background: #10b981; box-shadow: 0 0 0 4px rgba(16,185,129,.18); }
        .ci-chip-out .ci-dot { background: #6b7280; }
        .ci-chip-none .ci-dot { background: #f59e0b; }
        .ci-btn { width: min(15rem, 70vw); height: min(15rem, 70vw); border-radius: 999px; border: 0; color: #fff; cursor: pointer;
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .35rem; font-weight: 700;
            font-size: 1.6rem; letter-spacing: .01em; -webkit-tap-highlight-color: transparent; touch-action: manipulation;
            transition: transform .15s ease, box-shadow .2s ease, opacity .2s ease; }
        .ci-btn:active { transform: scale(.97); }
        .ci-btn[disabled] { opacity: .75; cursor: progress; }
        .ci-btn-in { background: radial-gradient(circle at 30% 25%, #34d399, #059669 70%); box-shadow: 0 12px 32px rgba(5,150,105,.35); }
        .ci-btn-out { background: radial-gradient(circle at 30% 25%, #fbbf24, #d97706 70%); box-shadow: 0 12px 32px rgba(217,119,6,.35); }
        .ci-btn small { font-size: .78rem; font-weight: 500; opacity: .92; max-width: 80%; line-height: 1.25; }
        .ci-spin { width: 1.6rem; height: 1.6rem; border-radius: 999px; border: 3px solid rgba(255,255,255,.9); border-top-color: transparent; animation: ci-spin .8s linear infinite; }
        @keyframes ci-spin { to { transform: rotate(360deg); } }
        .ci-stats { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; width: 100%; max-width: 26rem; }
        .ci-stat { border: 1px solid rgba(127,127,127,.22); border-radius: .875rem; padding: .75rem; background: rgba(255,255,255,.6); }
        .dark .ci-stat { background: rgba(255,255,255,.03); }
        .ci-stat-label { font-size: .72rem; font-weight: 600; opacity: .65; text-transform: uppercase; letter-spacing: .04em; }
        .ci-stat-value { font-size: 1.35rem; font-weight: 700; margin-top: .2rem; font-variant-numeric: tabular-nums; }
        .ci-stat-sub { font-size: .72rem; opacity: .6; margin-top: .1rem; }
        .ci-msg { width: 100%; max-width: 26rem; border-radius: .75rem; padding: .7rem .9rem; font-size: .88rem; line-height: 1.35; }
        .ci-msg-ok { background: rgba(16,185,129,.12); color: #047857; }
        .ci-msg-bad { background: rgba(244,63,94,.12); color: #be123c; }
        .dark .ci-msg-ok { color: #6ee7b7; }
        .dark .ci-msg-bad { color: #fda4af; }
        .ci-note { font-size: .75rem; opacity: .6; max-width: 24rem; line-height: 1.35; }
        .ci-install { width: 100%; max-width: 26rem; display: flex; align-items: center; gap: .75rem; text-align: left; border: 1px dashed rgba(127,127,127,.4); border-radius: .875rem; padding: .7rem .9rem; font-size: .82rem; }
        .ci-install button { font: inherit; cursor: pointer; }
        .ci-install-go { border: 0; border-radius: .6rem; padding: .45rem .8rem; color: #fff; background: var(--primary-600, #2563eb); font-weight: 600; white-space: nowrap; }
        .ci-install-x { border: 0; background: transparent; opacity: .6; padding: .25rem; }
        [x-cloak] { display: none !important; }
    </style>

    <div
        class="ci-wrap"
        x-data="{
            @include('employees::filament.pages.partials.geo-attendance-alpine', ['remote' => $remote])
        }"
    >
        @if ($state === null)
            <p>{{ __('employees::attendance.results.employee_not_eligible') }}</p>
        @else
            <div class="ci-date">{{ $today }}</div>

            <div @class(['ci-chip', 'ci-chip-in' => $checkedIn, 'ci-chip-out' => $checkedOut, 'ci-chip-none' => ! $checkedIn && ! $checkedOut]) data-testid="check-in-status">
                <span class="ci-dot"></span>
                @if ($checkedIn)
                    Checked in since {{ $state['current_check_in'] ?? $state['check_in'] }}
                @elseif ($checkedOut)
                    Checked out at {{ $state['check_out'] }}
                @else
                    Not checked in
                @endif
            </div>

            @if ($noLocation)
                <p class="ci-msg ci-msg-bad">{{ __('employees::attendance.results.no_location_configured') }}</p>
            @else
                <button
                    type="button"
                    @class(['ci-btn', 'ci-btn-out' => $checkedIn, 'ci-btn-in' => ! $checkedIn])
                    x-on:click="attempt('{{ $checkedIn ? 'check_out' : 'check_in' }}')"
                    x-bind:disabled="busy"
                    data-testid="check-in-button"
                >
                    <span x-show="phase === 'idle'">{{ $checkedIn ? 'Check out' : ($checkedOut ? 'Check in again' : 'Check in') }}</span>
                    <span x-show="phase !== 'idle'" x-cloak class="ci-spin"></span>
                    <small x-show="phase === 'locating'" x-cloak>Finding your location…</small>
                    <small x-show="phase === 'verifying'" x-cloak>{{ $remote ? 'Saving…' : 'Checking your location…' }}</small>
                    <small x-show="phase === 'idle'">
                        @if ($remote)
                            Remote · no location needed
                        @elseif (! empty($state['location']))
                            {{ $state['location'] }}
                        @else
                            Uses your location once
                        @endif
                    </small>
                </button>
            @endif

            @if ($outcome)
                <div @class(['ci-msg', 'ci-msg-ok' => $outcome['ok'], 'ci-msg-bad' => ! $outcome['ok']]) role="status">{{ $outcome['message'] }}</div>
            @endif

            @if ($checkedIn && ($state['lockout_remaining_minutes'] ?? 0) > 0)
                <p class="ci-note">Check-out opens in {{ $state['lockout_remaining_minutes'] }} min (minimum shift length).</p>
            @endif

            <div class="ci-stats">
                <div
                    class="ci-stat"
                    wire:key="ci-worked-{{ $state['state'] }}-{{ $state['interval_count'] ?? 0 }}"
                    x-data="{
                        seconds: @js((int) ($state['elapsed_seconds'] ?? 0)),
                        running: @js($checkedIn),
                        timer: null,
                        init() { if (this.running) { this.timer = setInterval(() => { this.seconds += 30 }, 30000) } },
                        destroy() { if (this.timer) { clearInterval(this.timer) } },
                        get label() { const m = Math.floor(this.seconds / 60); return Math.floor(m / 60) + 'h ' + (m % 60) + 'm' },
                    }"
                >
                    <div class="ci-stat-label">Worked today</div>
                    @if ($checkedIn)
                        <div class="ci-stat-value" x-text="label">{{ $state['elapsed_formatted'] ?? '0h 0m' }}</div>
                        <div class="ci-stat-sub">and counting</div>
                    @else
                        <div class="ci-stat-value">{{ $checkedOut ? $state['worked'] : '0h 0m' }}</div>
                        <div class="ci-stat-sub">{{ ! empty($state['scheduled_hours']) ? 'of '.$state['scheduled_hours'].'h' : '' }}</div>
                    @endif
                </div>
                <div class="ci-stat">
                    <div class="ci-stat-label">Last punch</div>
                    @if ($checkedIn)
                        <div class="ci-stat-value">{{ $state['current_check_in'] ?? $state['check_in'] }}</div>
                        <div class="ci-stat-sub">Check in</div>
                    @elseif ($checkedOut)
                        <div class="ci-stat-value">{{ $state['check_out'] }}</div>
                        <div class="ci-stat-sub">Check out</div>
                    @else
                        <div class="ci-stat-value">—</div>
                        <div class="ci-stat-sub">{{ ! empty($state['scheduled_start']) ? 'Shift starts '.$state['scheduled_start'] : 'None yet today' }}</div>
                    @endif
                </div>
            </div>

            @if (! empty($state['intervals']) && ($state['interval_count'] ?? 0) > 1)
                <p class="ci-note">Today: {{ $state['intervals'] }}</p>
            @endif

            <p class="ci-note">{{ __('employees::attendance.privacy_note') }}</p>

            <div
                class="ci-install"
                wire:ignore
                x-cloak
                x-data="{
                    canInstall: !! window.__aureusInstallPrompt,
                    ios: /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1),
                    standalone: window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true,
                    hidden: false,
                    init() { try { this.hidden = localStorage.getItem('aureus-install-hint') === 'hidden' } catch (e) {} },
                    async install() {
                        const prompt = window.__aureusInstallPrompt
                        if (! prompt) { return }
                        prompt.prompt()
                        try { await prompt.userChoice } catch (e) {}
                        window.__aureusInstallPrompt = null
                        this.canInstall = false
                    },
                    hide() { this.hidden = true; try { localStorage.setItem('aureus-install-hint', 'hidden') } catch (e) {} },
                }"
                x-on:aureus-installable.window="canInstall = true"
                x-on:aureus-installed.window="canInstall = false; standalone = true"
                x-show="! standalone && ! hidden && (canInstall || ios)"
            >
                <x-filament::icon icon="heroicon-o-device-phone-mobile" style="width: 1.5rem; height: 1.5rem; flex-shrink: 0; opacity: .75;" />
                <div style="flex: 1;">
                    <template x-if="canInstall">
                        <span>Install Aureus on this device to check in with one tap from your home screen.</span>
                    </template>
                    <template x-if="! canInstall && ios">
                        <span>Add Aureus to your home screen: tap <strong>Share</strong> in Safari, then <strong>Add to Home Screen</strong>.</span>
                    </template>
                </div>
                <button type="button" class="ci-install-go" x-show="canInstall" x-on:click="install()">Install app</button>
                <button type="button" class="ci-install-x" x-on:click="hide()" aria-label="Hide">✕</button>
            </div>

            <x-filament::link :href="\Webkul\Employee\Filament\Pages\MyAttendance::getUrl()" icon="heroicon-m-calendar-days" size="sm">
                My attendance, calendar and corrections
            </x-filament::link>
        @endif
    </div>
</x-filament-panels::page>
