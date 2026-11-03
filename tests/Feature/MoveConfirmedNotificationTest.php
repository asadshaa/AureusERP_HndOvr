<?php

/**
 * J5-03: App\Listeners\NotifyUsersOnMoveConfirmed was registered twice (an
 * explicit Event::listen in AppServiceProvider and event discovery), so every
 * posting notified each recipient twice. Posting once must notify each other
 * company user exactly once, after the posting transaction commits, and a
 * posting that rolls back must notify no one.
 */

use App\Listeners\NotifyUsersOnMoveConfirmed;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Events\MoveConfirmed;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/** @return array{company: Company, poster: User, colleague: User, invoice: Move} */
function postingNoticeFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);

    $member = function (string $name) use ($company): User {
        $user = User::factory()->create(['name' => $name, 'default_company_id' => $company->id, 'is_active' => true]);
        $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

        return $user;
    };
    $poster = $member('Poster Pat');
    $colleague = $member('Colleague Cam');

    $account = function (string $code, AccountType $type, bool $reconcile = false) use ($company, $currency, $poster): Account {
        $account = Account::factory()->create([
            'code'        => $code.$company->id, 'name' => $code, 'account_type' => $type,
            'currency_id' => $currency->id, 'creator_id' => $poster->id, 'reconcile' => $reconcile,
            'is_group'    => false, 'deprecated' => false,
        ]);
        $account->companies()->attach($company->id);

        return $account;
    };
    $receivable = $account('MCAR-', AccountType::ASSET_RECEIVABLE, true);
    $revenue = $account('MCREV-', AccountType::INCOME);

    $journal = Journal::factory()->create([
        'company_id'         => $company->id, 'currency_id' => $currency->id, 'creator_id' => $poster->id,
        'default_account_id' => $revenue->id, 'code' => 'MCS'.$company->id, 'name' => 'Sales', 'type' => JournalType::SALE,
    ]);
    $customer = Partner::query()->create([
        'company_id'                     => $company->id, 'account_type' => 'company', 'sub_type' => 'customer',
        'reference'                      => 'MC-CUSTOMER-'.$company->id, 'name' => 'Notice customer', 'customer_rank' => 1,
        'property_account_receivable_id' => $receivable->id,
    ]);

    $invoice = Move::query()->create([
        'company_id'  => $company->id, 'journal_id' => $journal->id, 'partner_id' => $customer->id,
        'currency_id' => $currency->id, 'move_type' => MoveType::OUT_INVOICE, 'state' => MoveState::DRAFT,
        'date'        => '2026-11-02', 'invoice_date' => '2026-11-02', 'invoice_date_due' => '2026-11-30',
    ]);
    MoveLine::query()->create([
        'move_id'      => $invoice->id, 'account_id' => $revenue->id, 'partner_id' => $customer->id,
        'currency_id'  => $currency->id, 'display_type' => DisplayType::PRODUCT,
        'name'         => 'Freight', 'quantity' => 1, 'price_unit' => 1200,
    ]);

    return ['company' => $company, 'poster' => $poster, 'colleague' => $colleague, 'invoice' => $invoice];
}

function postingNoticesFor(User $user): int
{
    return DatabaseNotification::query()
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', $user->id)
        ->where('data->title', 'Customer Invoice Posted')
        ->count();
}

it('registers the posting-notification listener once', function () {
    $listeners = collect(Event::getRawListeners()[MoveConfirmed::class] ?? [])
        ->filter(fn ($listener): bool => is_string($listener) && str_starts_with($listener, NotifyUsersOnMoveConfirmed::class));

    expect($listeners)->toHaveCount(1);
});

it('notifies each other company user exactly once when a move is posted once', function () {
    $f = postingNoticeFixture();
    Auth::login($f['poster']);

    $posted = AccountFacade::confirmMove($f['invoice']->fresh());

    expect($posted->state)->toBe(MoveState::POSTED)
        ->and(postingNoticesFor($f['colleague']))->toBe(1)
        ->and(postingNoticesFor($f['poster']))->toBe(0);
});

it('notifies only after the posting commits, and no one when it rolls back', function () {
    $f = postingNoticeFixture();
    Auth::login($f['poster']);

    try {
        DB::transaction(function () use ($f): void {
            AccountFacade::confirmMove($f['invoice']->fresh());

            // Still inside the outer transaction: nothing sent yet.
            expect(postingNoticesFor($f['colleague']))->toBe(0);

            throw new RuntimeException('roll the posting back');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('roll the posting back');
    }

    expect($f['invoice']->fresh()->state)->toBe(MoveState::DRAFT)
        ->and(postingNoticesFor($f['colleague']))->toBe(0);
});
