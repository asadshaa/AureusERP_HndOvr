<?php

namespace App\Providers;

use App\Exceptions\FriendlyExceptionPresenter;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Livewire\Livewire;
use Throwable;
use WeakMap;

use function Livewire\after;

/**
 * Keeps errors readable for non-technical users.
 *
 * - Business-rule exceptions thrown while a Livewire/Filament action runs are
 *   shown as a danger notification; the user stays on the page with their form
 *   data (Filament has already rolled back the action's transaction).
 * - Unexpected exceptions are reported and, with debug off, replaced by a
 *   generic notification. With debug on they propagate so developers still
 *   get the full error page.
 * - Livewire requests that still fail (e.g. a crash while rendering) get the
 *   plain-language panel error notifications registered below.
 */
class FriendlyErrorsServiceProvider extends ServiceProvider
{
    /**
     * How many method calls / property updates are in progress per component.
     * Only exceptions raised inside these are handled here: an exception while
     * mounting or rendering must still abort the request.
     *
     * @var WeakMap<Component, int>
     */
    protected WeakMap $activePhases;

    public function register(): void
    {
        $this->app->singleton(FriendlyExceptionPresenter::class);

        Panel::configureUsing(function (Panel $panel): void {
            $panel
                ->registerErrorNotification(
                    title: __('Something went wrong.'),
                    body: __('It has been logged; please try again, or contact the administrator if it keeps happening.'),
                )
                ->registerErrorNotification(
                    title: __('Your session expired.'),
                    body: __('Please refresh the page and log in again.'),
                    statusCode: 419,
                )
                ->registerErrorNotification(
                    title: __('You do not have permission to do that.'),
                    body: __('Ask your administrator if you need access.'),
                    statusCode: 403,
                )
                ->registerErrorNotification(
                    title: __('That record could not be found.'),
                    body: __('It may have been deleted. Refresh the page to see the latest list.'),
                    statusCode: 404,
                )
                ->registerErrorNotification(
                    title: __('Too many attempts.'),
                    body: __('Please wait a minute and try again.'),
                    statusCode: 429,
                )
                ->registerErrorNotification(
                    title: __('The system is being updated.'),
                    body: __('Please try again in a few minutes.'),
                    statusCode: 503,
                );
        });
    }

    public function boot(): void
    {
        $this->activePhases = new WeakMap;

        after('call', fn (mixed $component): ?\Closure => $this->enterPhase($component));
        after('update', fn (mixed $component): ?\Closure => $this->enterPhase($component));

        Livewire::listen('exception', function (mixed $target, Throwable $exception, callable $stopPropagation): void {
            $this->handleLivewireException($target, $exception, $stopPropagation);
        });
    }

    /**
     * @return (\Closure(mixed): mixed)|null The finisher Livewire runs once the call/update is done.
     */
    protected function enterPhase(mixed $component): ?\Closure
    {
        if (! $component instanceof Component) {
            return null;
        }

        $this->activePhases[$component] = ($this->activePhases[$component] ?? 0) + 1;

        return function (mixed $forward = null) use ($component): mixed {
            $this->activePhases[$component] = max(0, ($this->activePhases[$component] ?? 1) - 1);

            return $forward;
        };
    }

    protected function handleLivewireException(mixed $target, Throwable $exception, callable $stopPropagation): void
    {
        if (
            ! $target instanceof Component
            || ! Livewire::isLivewireRequest()
            || ($this->activePhases[$target] ?? 0) < 1
        ) {
            return;
        }

        $presenter = $this->app->make(FriendlyExceptionPresenter::class);

        if ($presenter->shouldPassThrough($exception)) {
            return;
        }

        $message = $presenter->userMessage($exception);

        if ($message !== null) {
            Log::info('Action stopped with a message for the user.', [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
                'component' => $target::class,
            ]);

            Notification::make()
                ->danger()
                ->title($message)
                ->persistent()
                ->send();

            $stopPropagation();

            return;
        }

        if (config('app.debug')) {
            return;
        }

        report($exception);

        Notification::make()
            ->danger()
            ->title(__(FriendlyExceptionPresenter::GENERIC_TITLE))
            ->body(__(FriendlyExceptionPresenter::GENERIC_BODY))
            ->persistent()
            ->send();

        $stopPropagation();
    }
}
