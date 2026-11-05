<?php

/**
 * Errors are shown to users in plain language: branded error pages instead of
 * stack traces, business-rule exceptions from Filament/Livewire actions as a
 * notification (the user keeps the page and their input), unexpected errors as
 * a generic logged message, and API clients still get JSON.
 */

use App\Exceptions\FriendlyExceptionPresenter;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Livewire;

class FriendlyErrorsProbe extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public string $note = '';

    public function failingAction(): Action
    {
        return Action::make('failing')
            ->action(fn () => throw new RuntimeException('The timesheet has not been submitted.'));
    }

    public function forbiddenAction(): Action
    {
        return Action::make('forbidden')
            ->action(fn () => throw new AuthorizationException);
    }

    public function crashingAction(): Action
    {
        return Action::make('crashing')
            ->action(fn () => throw new TypeError('Argument #1 ($id) must be of type int, string given in C:\\app\\Secret.php'));
    }

    public function databaseAction(): Action
    {
        return Action::make('database')
            ->action(fn () => throw new QueryException(
                'mysql',
                'select * from `employees` where `salary` > ?',
                [1000],
                new PDOException('SQLSTATE[42S22]: Column not found'),
            ));
    }

    public function save(): void
    {
        throw new InvalidArgumentException('The payroll month is already locked.');
    }

    public function validateNote(): void
    {
        $this->validate(['note' => 'required|min:20']);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <input wire:model="note">
                {{ $this->failingAction }}
                <x-filament-actions::modals />
            </div>
        BLADE;
    }
}

function friendlyErrorsGenericNotification(): Notification
{
    return Notification::make()
        ->danger()
        ->title('Something went wrong.')
        ->body('It has been logged; please try again or contact the administrator.')
        ->persistent();
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

describe('error pages', function () {
    beforeEach(function () {
        config(['app.debug' => false]);

        Route::middleware('web')->group(function () {
            Route::get('/_friendly-errors/forbidden', fn () => abort(403));
            Route::get('/_friendly-errors/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
            Route::get('/_friendly-errors/crash', fn () => throw new TypeError('Undefined index in /var/www/html/app/Secret.php'));
            Route::get('/_friendly-errors/rule', fn () => throw new RuntimeException('The payroll month is already locked.'));
        });
    });

    it('shows a plain-language 404 page', function () {
        $this->get('/_friendly-errors/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Go to dashboard')
            ->assertSee(route('filament.admin.pages.dashboard'), false);
    });

    it('shows a plain-language 403 page', function () {
        $this->get('/_friendly-errors/forbidden')
            ->assertForbidden()
            ->assertSee('have access to this page', false)
            ->assertSee('ask your administrator', false);
    });

    it('tells the user their session expired and links to the admin login', function () {
        $this->get('/_friendly-errors/expired')
            ->assertStatus(419)
            ->assertSee('Your session expired. Log in again to continue.')
            ->assertSee('Log in again')
            ->assertSee(route('filament.admin.auth.login'), false);
    });

    it('shows a calm 500 page without technical details', function () {
        $this->get('/_friendly-errors/crash')
            ->assertStatus(500)
            ->assertSee('Something went wrong')
            ->assertSee('contact the administrator')
            ->assertDontSee('Secret.php')
            ->assertDontSee('TypeError');
    });

    it('shows a business-rule message on its own page, even with debug on', function () {
        config(['app.debug' => true]);

        $this->get('/_friendly-errors/rule')
            ->assertStatus(422)
            ->assertSee('The payroll month is already locked.')
            ->assertSee('Go to dashboard');
    });

    it('registers plain-language Livewire error notifications on the panel', function () {
        $notifications = Filament::getPanel('admin')->getErrorNotifications();

        expect($notifications[419]['title'])->toBe('Your session expired.')
            ->and($notifications['']['title'])->toBe('Something went wrong.');
    });
});

describe('Livewire and Filament actions', function () {
    it('turns a business-rule exception into a danger notification and keeps the input', function () {
        Exceptions::fake();

        Livewire::test(FriendlyErrorsProbe::class)
            ->set('note', 'Half-typed comment')
            ->callAction('failing')
            ->assertOk()
            ->assertSet('note', 'Half-typed comment')
            ->assertNotified(Notification::make()->danger()->title('The timesheet has not been submitted.')->persistent());

        Exceptions::assertNothingReported();
    });

    it('handles business-rule exceptions from plain component methods regardless of debug mode', function (bool $debug) {
        config(['app.debug' => $debug]);

        Livewire::test(FriendlyErrorsProbe::class)
            ->call('save')
            ->assertOk()
            ->assertNotified('The payroll month is already locked.');
    })->with([true, false]);

    it('replaces the default unauthorized message with a friendly one', function () {
        Livewire::test(FriendlyErrorsProbe::class)
            ->callAction('forbidden')
            ->assertOk()
            ->assertNotified(FriendlyExceptionPresenter::NOT_ALLOWED);
    });

    it('logs an unexpected error and shows only the generic message', function () {
        config(['app.debug' => false]);
        Exceptions::fake();

        Livewire::test(FriendlyErrorsProbe::class)
            ->set('note', 'Keep this')
            ->callAction('crashing')
            ->assertOk()
            ->assertSet('note', 'Keep this')
            ->assertNotified(friendlyErrorsGenericNotification());

        Exceptions::assertReported(TypeError::class);
    });

    it('never shows SQL to the user', function () {
        config(['app.debug' => false]);
        Exceptions::fake();

        Livewire::test(FriendlyErrorsProbe::class)
            ->callAction('database')
            ->assertOk()
            ->assertNotified(friendlyErrorsGenericNotification())
            ->assertDontSee('SQLSTATE');

        Exceptions::assertReported(QueryException::class);
    });

    it('keeps validation errors on the fields', function () {
        Livewire::test(FriendlyErrorsProbe::class)
            ->set('note', 'short')
            ->call('validateNote')
            ->assertHasErrors(['note' => 'min']);

        Notification::assertNotNotified();
    });
});

describe('the presenter', function () {
    it('only trusts plain-language messages', function () {
        $presenter = app(FriendlyExceptionPresenter::class);

        expect($presenter->domainMessage(new RuntimeException('GL code 1000 already exists for this company.')))
            ->toBe('GL code 1000 already exists for this company.')
            ->and($presenter->domainMessage(new RuntimeException('SQLSTATE[23000]: Integrity constraint violation')))->toBeNull()
            ->and($presenter->domainMessage(new RuntimeException('Failed to open C:\\Intern\\file.php')))->toBeNull()
            ->and($presenter->domainMessage(new ErrorException('Undefined array key "x"')))->toBeNull()
            ->and($presenter->domainMessage(new Symfony\Component\Process\Exception\RuntimeException('Process failed')))->toBeNull();
    });
});

describe('API', function () {
    beforeEach(function () {
        config(['app.debug' => false]);

        Route::get('/api/_friendly-errors/crash', fn () => throw new RuntimeException('Disk path /var/www/storage failed'));
    });

    it('keeps returning JSON for API errors, without technical details', function () {
        $this->get('/api/_friendly-errors/crash')
            ->assertStatus(500)
            ->assertExactJson([
                'message'   => 'Server error occurred.',
                'exception' => null,
                'file'      => null,
                'line'      => null,
            ]);
    });

    it('returns JSON for admin API routes even without a JSON Accept header', function () {
        $this->get('/admin/api/v1/devices')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->getJson('/admin/api/v1/this-does-not-exist')
            ->assertNotFound()
            ->assertJson(['message' => 'The requested resource was not found.']);
    });
});
