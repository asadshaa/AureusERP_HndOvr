<?php

namespace Tests\Feature;

use App\Listeners\NotifyUsersOnMoveConfirmed;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Events\MoveConfirmed;
use Webkul\Account\Models\Move;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../plugins/webkul/support/tests/Helpers/TestBootstrapHelper.php';

class InvoiceRealTimeCollaborationTest extends TestCase
{
    use DatabaseTransactions;

    protected Company $company;

    protected User $userA;

    protected User $userB;

    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        \TestBootstrapHelper::ensurePluginInstalled('accounts');

        $this->company = Company::first() ?? Company::factory()->create(['is_active' => true]);
        $this->currency = $this->company->currency ?? Currency::first();
        if (! $this->currency) {
            $this->currency = Currency::create([
                'name'           => 'USD',
                'code'           => 'USD',
                'symbol'         => '$',
                'decimal_places' => 2,
                'active'         => true,
            ]);
            $this->company->update(['currency_id' => $this->currency->id]);
        }

        $this->userA = User::factory()->create([
            'name'               => 'Accountant Alice',
            'default_company_id' => $this->company->id,
        ]);
        $this->userA->companies()->syncWithoutDetaching([$this->company->id]);

        $this->userB = User::factory()->create([
            'name'               => 'Accountant Bob',
            'default_company_id' => $this->company->id,
        ]);
        $this->userB->companies()->syncWithoutDetaching([$this->company->id]);
    }

    public function test_posting_invoice_notifies_other_company_users(): void
    {
        Auth::login($this->userA);

        $invoice = Move::create([
            'name'               => 'INV/2026/00099',
            'move_type'          => MoveType::OUT_INVOICE,
            'state'              => MoveState::POSTED,
            'company_id'         => $this->company->id,
            'currency_id'        => $this->currency->id,
            'amount_total'       => 1500.00,
            'amount_untaxed'     => 1500.00,
            'amount_residual'    => 1500.00,
            'creator_id'         => $this->userA->id,
        ]);

        $listener = new NotifyUsersOnMoveConfirmed;
        $listener->handle(new MoveConfirmed($invoice));

        // User B should receive database notification
        $userBNotifications = DatabaseNotification::query()
            ->where('notifiable_id', $this->userB->id)
            ->where('notifiable_type', User::class)
            ->get();

        $this->assertCount(1, $userBNotifications);

        $notifData = $userBNotifications->first()->data;
        $this->assertEquals('Customer Invoice Posted', $notifData['title']);
        $this->assertStringContainsString('Accountant Alice', $notifData['body']);
        $this->assertStringContainsString('INV/2026/00099', $notifData['body']);

        // User A (the poster) should NOT receive the notification
        $userANotifications = DatabaseNotification::query()
            ->where('notifiable_id', $this->userA->id)
            ->where('notifiable_type', User::class)
            ->get();

        $this->assertCount(0, $userANotifications);
    }

    public function test_posting_vendor_bill_notifies_with_bill_label(): void
    {
        Auth::login($this->userB);

        $bill = Move::create([
            'name'               => 'BILL/2026/00042',
            'move_type'          => MoveType::IN_INVOICE,
            'state'              => MoveState::POSTED,
            'company_id'         => $this->company->id,
            'currency_id'        => $this->currency->id,
            'amount_total'       => 750.50,
            'amount_untaxed'     => 750.50,
            'amount_residual'    => 750.50,
            'creator_id'         => $this->userB->id,
        ]);

        $listener = new NotifyUsersOnMoveConfirmed;
        $listener->handle(new MoveConfirmed($bill));

        $userANotifications = DatabaseNotification::query()
            ->where('notifiable_id', $this->userA->id)
            ->where('notifiable_type', User::class)
            ->get();

        $this->assertCount(1, $userANotifications);

        $notifData = $userANotifications->first()->data;
        $this->assertEquals('Vendor Bill Posted', $notifData['title']);
        $this->assertStringContainsString('Accountant Bob', $notifData['body']);
        $this->assertStringContainsString('BILL/2026/00042', $notifData['body']);
    }
}
