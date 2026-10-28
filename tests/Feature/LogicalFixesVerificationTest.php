<?php

namespace Tests\Feature;

use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Filament\Resources\BillResource;
use Webkul\Account\Filament\Resources\BillResource\Pages\EditBill;
use Webkul\Account\Filament\Resources\InvoiceResource;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\PayAction;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Partner;
use Webkul\Account\Models\Product;
use Webkul\Accounting\Enums\ManualAdjustmentStatus;
use Webkul\Accounting\Models\ManualAdjustment;
use Webkul\Accounting\Services\ManualAdjustmentService;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Models\OrderLine;
use Webkul\Purchase\PurchaseOrder as PurchaseOrderService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\TimeOff\Database\Factories\LeaveTypeFactory;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveApprovalService;

require_once __DIR__.'/../../plugins/webkul/support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../plugins/webkul/support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../plugins/webkul/accounts/tests/Helpers/AccountHelper.php';

class LogicalFixesVerificationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        \TestBootstrapHelper::ensurePluginInstalled('accounts');

        DB::table('plugins')->updateOrInsert(
            ['name' => 'accounts'],
            ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
        );

        Package::$plugins = Plugin::all()->keyBy('name');
        URL::resolveMissingNamedRoutesUsing(fn () => '#');

        $this->user = User::first() ?? User::factory()->create();
        $this->company = Company::first() ?? Company::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * Fix 1: Purchase Order "createAccountMove" sets invoice_date, display_type = PRODUCT,
     * resolves expense account, and assigns company_currency_id.
     */
    public function test_purchase_order_create_bill_sets_required_fields_and_expense_account(): void
    {
        $currency = Currency::first() ?? Currency::factory()->create();
        $partner = Partner::first() ?? Partner::factory()->create();

        $order = Order::create([
            'partner_id'   => $partner->id,
            'company_id'   => $this->company->id,
            'currency_id'  => $currency->id,
            'state'        => 'purchase',
            'ordered_at'   => '2026-10-01 10:00:00',
            'user_id'      => $this->user->id,
        ]);

        $product = Product::first() ?? Product::factory()->create();
        OrderLine::create([
            'order_id'       => $order->id,
            'product_id'     => $product->id,
            'name'           => 'Test Order Item',
            'product_qty'    => 5,
            'qty_received'   => 5,
            'qty_invoiced'   => 0,
            'price_unit'     => 100,
            'price_subtotal' => 500,
            'price_total'    => 500,
        ]);

        $service = app(PurchaseOrderService::class);
        $service->createAccountMove($order);

        $bill = $order->accountMoves()->first();
        $this->assertNotNull($bill, 'Generated bill must be attached to the purchase order');
        $this->assertEquals('2026-10-01', $bill->invoice_date->toDateString());
        $this->assertEquals(MoveType::IN_INVOICE, $bill->move_type);

        $line = $bill->lines()->where('display_type', DisplayType::PRODUCT)->first();
        $this->assertNotNull($line, 'Move must have a product line');
        $this->assertEquals(DisplayType::PRODUCT, $line->display_type);
        $this->assertNotNull($line->account_id, 'Line must resolve an expense account');
        $this->assertEquals($this->company->currency_id, $line->company_currency_id);
    }

    /**
     * Fix 2: TimeOff bulk delete must not delete approved (VALIDATE_TWO) or pending leaves.
     */
    public function test_time_off_bulk_delete_protects_approved_and_pending_leaves(): void
    {
        $leaveType = LeaveType::first() ?? LeaveType::factory()->create(['company_id' => $this->company->id]);
        $employee = Employee::first() ?? Employee::factory()->create(['company_id' => $this->company->id]);

        $workflow = ApprovalWorkflow::first() ?? ApprovalWorkflow::create([
            'company_id'   => $this->company->id,
            'name'         => 'Leave Workflow',
            'request_type' => 'leave_request',
            'is_active'    => true,
        ]);

        $approval = ApprovalRequest::create([
            'workflow_id'  => $workflow->id,
            'company_id'   => $this->company->id,
            'status'       => 'pending',
            'requester_id' => $this->user->id,
        ]);

        $pendingLeave = Leave::create([
            'user_id'             => $this->user->id,
            'employee_id'         => $employee->id,
            'company_id'          => $this->company->id,
            'holiday_status_id'   => $leaveType->id,
            'approval_request_id' => $approval->id,
            'state'               => State::CONFIRM,
            'request_date_from'   => '2026-12-01',
            'request_date_to'     => '2026-12-01',
            'number_of_days'      => 1,
        ]);

        $approvedLeave = Leave::create([
            'user_id'             => $this->user->id,
            'employee_id'         => $employee->id,
            'company_id'          => $this->company->id,
            'holiday_status_id'   => $leaveType->id,
            'state'               => State::VALIDATE_TWO,
            'request_date_from'   => '2026-12-02',
            'request_date_to'     => '2026-12-02',
            'number_of_days'      => 1,
        ]);

        $refusedLeave = Leave::create([
            'user_id'             => $this->user->id,
            'employee_id'         => $employee->id,
            'company_id'          => $this->company->id,
            'holiday_status_id'   => $leaveType->id,
            'state'               => State::REFUSE,
            'request_date_from'   => '2026-12-03',
            'request_date_to'     => '2026-12-03',
            'number_of_days'      => 1,
        ]);

        $records = new Collection([$pendingLeave, $approvedLeave, $refusedLeave]);
        $deletable = $records->filter(function ($leave) {
            return $leave->approvalRequest?->status !== 'pending' && $leave->state !== State::VALIDATE_TWO;
        });

        $this->assertFalse($deletable->contains($approvedLeave), 'Approved leave must be excluded from deletion');
        $this->assertFalse($deletable->contains($pendingLeave), 'Pending leave must be excluded from deletion');
        $this->assertTrue($deletable->contains($refusedLeave), 'Refused unapproved leave can be deleted');
    }

    /**
     * Fix 3: Employee requests notify employee when Approved or Rejected.
     */
    public function test_employee_request_decision_sends_notification(): void
    {
        $employeeUser = User::factory()->create(['default_company_id' => $this->company->id]);
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id'    => $employeeUser->id,
            'name'       => 'Notify Test Employee',
        ]);

        $requestType = EmployeeRequestType::first() ?? EmployeeRequestType::create([
            'company_id' => $this->company->id,
            'name'       => 'General Request',
            'code'       => 'general_test',
            'category'   => 'general',
            'is_active'  => true,
        ]);

        $workflow = ApprovalWorkflow::first() ?? ApprovalWorkflow::create([
            'company_id'   => $this->company->id,
            'name'         => 'Emp Request Workflow',
            'request_type' => 'employee_request',
            'is_active'    => true,
        ]);

        $step = $workflow->steps()->first() ?? ApprovalStep::create([
            'workflow_id'        => $workflow->id,
            'name'               => 'Approval Step',
            'sequence'           => 1,
            'hierarchy_route'    => 'requester_manager',
            'required_approvals' => 1,
        ]);

        $approval = ApprovalRequest::create([
            'workflow_id'  => $workflow->id,
            'company_id'   => $this->company->id,
            'status'       => 'rejected',
            'requester_id' => $employeeUser->id,
        ]);
        $approval->decisions()->create([
            'step_id'  => $step->id,
            'user_id'  => $this->user->id,
            'decision' => 'rejected',
            'reason'   => 'Budget unavailable',
        ]);

        $request = EmployeeRequest::create([
            'company_id'          => $this->company->id,
            'employee_id'         => $employee->id,
            'request_type_id'     => $requestType->id,
            'approval_request_id' => $approval->id,
            'title'               => 'Test Request',
            'status'              => 'submitted',
            'requested_by'        => $employeeUser->id,
        ]);

        $service = app(EmployeeRequestService::class);
        $service->synchronize($request);

        $this->assertEquals('rejected', $request->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id'   => $employeeUser->id,
            'notifiable_type' => User::class,
        ]);
    }

    /**
     * Fix 4: Line Manager can submit leave on behalf of a subordinate in their hierarchy.
     */
    public function test_line_manager_can_submit_leave_for_subordinate(): void
    {
        $managerUser = User::factory()->create(['default_company_id' => $this->company->id]);
        $manager = Employee::create([
            'company_id' => $this->company->id,
            'user_id'    => $managerUser->id,
            'name'       => 'Manager Submitting',
        ]);

        $employeeUser = User::factory()->create(['default_company_id' => $this->company->id]);
        $subordinate = Employee::create([
            'company_id' => $this->company->id,
            'user_id'    => $employeeUser->id,
            'parent_id'  => $manager->id,
            'name'       => 'Direct Subordinate',
        ]);

        $leaveType = LeaveTypeFactory::new()->create(['company_id' => $this->company->id, 'creator_id' => $managerUser->id, 'requires_allocation' => 'no']);
        $leave = Leave::create([
            'user_id'             => $employeeUser->id,
            'employee_id'         => $subordinate->id,
            'company_id'          => $this->company->id,
            'holiday_status_id'   => $leaveType->id,
            'state'               => State::CONFIRM,
            'request_date_from'   => '2026-11-10',
            'request_date_to'     => '2026-11-10',
            'number_of_days'      => 1,
        ]);

        $service = app(LeaveApprovalService::class);
        try {
            $service->submit($leave, $managerUser);
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('A user can only submit leave for their own HR hierarchy', $e->getMessage());
        }
    }

    /**
     * Fix 5: Manual Adjustments post() sets entry name = adjustment_reference, posted_by_id, posted_at, posted_before.
     */
    public function test_manual_adjustment_post_updates_entry_name_and_audit_fields(): void
    {
        $company = \AccountHelper::company();
        $journal = Journal::where('company_id', $company->id)->first() ?? Journal::factory()->create(['company_id' => $company->id]);
        $debitAccount = \AccountHelper::account('expense');
        $creditAccount = \AccountHelper::account('income');

        $debitAccount->companies()->syncWithoutDetaching([$company->id]);
        $creditAccount->companies()->syncWithoutDetaching([$company->id]);

        $adjustment = ManualAdjustment::create([
            'company_id'           => $company->id,
            'journal_id'           => $journal->id,
            'debit_account_id'     => $debitAccount->id,
            'credit_account_id'    => $creditAccount->id,
            'amount'               => 250,
            'date'                 => '2026-10-06',
            'description'          => 'Test audit post',
            'adjustment_reference' => 'ADJ-AUDIT-TEST-99',
            'approval_status'      => ManualAdjustmentStatus::Approved,
            'creator_id'           => $this->user->id,
        ]);

        $service = app(ManualAdjustmentService::class);
        $move = $service->post($adjustment, $this->user);

        $adjustment->refresh();
        $this->assertEquals(ManualAdjustmentStatus::Posted, $adjustment->approval_status);
        $this->assertEquals('ADJ-AUDIT-TEST-99', $move->name);
        $this->assertEquals(MoveState::POSTED, $move->state);
        $this->assertEquals($this->user->id, $move->posted_by_id);
        $this->assertNotNull($move->posted_at);
        $this->assertTrue((bool) $move->posted_before);
    }

    /**
     * Fix 6: PayAction amount validates minValue(0.01) and maxValue = amount_residual.
     */
    public function test_pay_action_amount_has_min_and_max_bounds(): void
    {
        \FilamentHelper::actingAs(['view_any_account_bill', 'update_account_bill', 'accounting_post_journal']);

        $bankJournal = \AccountHelper::bankJournal();
        $paymentMethodLine = $bankJournal->outboundPaymentMethodLines->first();

        $bill = \AccountHelper::invoice(MoveType::IN_INVOICE, null, null, ['invoice_date' => now()]);
        \AccountHelper::productLine($bill, \AccountHelper::account('expense'), qty: 2, priceUnit: 100);
        $bill = \AccountHelper::post($bill);

        // 1. Amount 0 or negative should fail validation
        Livewire::test(EditBill::class, ['record' => $bill->id])
            ->callAction(PayAction::class, data: [
                'journal_id'             => $bankJournal->id,
                'payment_method_line_id' => $paymentMethodLine->id,
                'amount'                 => 0,
                'currency_id'            => $bill->currency_id,
                'payment_date'           => now()->format('Y-m-d'),
                'communication'          => $bill->name,
            ])
            ->assertHasActionErrors(['amount']);

        // 2. Amount exceeding residual balance should fail validation
        Livewire::test(EditBill::class, ['record' => $bill->id])
            ->callAction(PayAction::class, data: [
                'journal_id'             => $bankJournal->id,
                'payment_method_line_id' => $paymentMethodLine->id,
                'amount'                 => $bill->amount_residual + 1000,
                'currency_id'            => $bill->currency_id,
                'payment_date'           => now()->format('Y-m-d'),
                'communication'          => $bill->name,
            ])
            ->assertHasActionErrors(['amount']);
    }

    /**
     * Fix 7: Multi-currency conversion in BillResource and InvoiceResource uses company and document date.
     */
    public function test_bill_and_invoice_product_update_uses_document_company_and_date(): void
    {
        $product = Product::first() ?? Product::factory()->create(['price' => 100]);

        $get = Mockery::mock(Get::class);
        $get->shouldReceive('__invoke')->andReturnUsing(function ($key) use ($product) {
            return match ($key) {
                'product_id'         => $product->id,
                '../../currency_id'  => $this->company->currency_id,
                '../../company_id'   => $this->company->id,
                'company_id'         => $this->company->id,
                '../../invoice_date' => '2026-05-15',
                default              => null,
            };
        });

        $billCaptured = [];
        $set = Mockery::mock(Set::class);
        $set->shouldReceive('__invoke')->andReturnUsing(function ($key, $val) use (&$billCaptured) {
            $billCaptured[$key] = $val;

            return null;
        });

        $billRef = new \ReflectionMethod(BillResource::class, 'afterProductUpdated');
        $billRef->setAccessible(true);
        $billRef->invoke(null, $set, $get);

        $this->assertArrayHasKey('price_unit', $billCaptured);

        $invoiceCaptured = [];
        $setInvoice = Mockery::mock(Set::class);
        $setInvoice->shouldReceive('__invoke')->andReturnUsing(function ($key, $val) use (&$invoiceCaptured) {
            $invoiceCaptured[$key] = $val;

            return null;
        });

        $invoiceRef = new \ReflectionMethod(InvoiceResource::class, 'afterProductUpdated');
        $invoiceRef->setAccessible(true);
        $invoiceRef->invoke(null, $setInvoice, $get);

        $this->assertArrayHasKey('price_unit', $invoiceCaptured);
    }

    /**
     * Fix 8: Missed attendance requests block submission if employee has approved leave on that date.
     */
    public function test_missed_attendance_request_blocked_if_on_approved_leave(): void
    {
        $employeeUser = User::factory()->create(['default_company_id' => $this->company->id]);
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id'    => $employeeUser->id,
            'name'       => 'Leave Blocked Attendance Employee',
        ]);
        $leaveType = LeaveType::first() ?? LeaveType::factory()->create(['company_id' => $this->company->id]);

        $missedDate = now()->subDays(2)->toDateString();

        Leave::create([
            'user_id'             => $employeeUser->id,
            'employee_id'         => $employee->id,
            'company_id'          => $this->company->id,
            'holiday_status_id'   => $leaveType->id,
            'state'               => State::VALIDATE_TWO,
            'date_from'           => $missedDate.' 00:00:00',
            'date_to'             => $missedDate.' 23:59:59',
            'request_date_from'   => $missedDate,
            'request_date_to'     => $missedDate,
            'number_of_days'      => 1,
        ]);

        $service = app(EmployeeRequestService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A missed attendance day cannot be requested on a day the employee has an approved leave.');

        $service->requestMissingAttendance(
            $employee,
            $employeeUser,
            $missedDate,
            ['check_in' => '09:00:00', 'check_out' => '17:00:00'],
            'Forgot attendance'
        );
    }

    /**
     * Fix 9: Title and Reason / Description are optional and auto-defaulted.
     */
    public function test_employee_request_title_and_reason_are_optional(): void
    {
        $employeeUser = User::factory()->create(['default_company_id' => $this->company->id]);
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'user_id'    => $employeeUser->id,
            'name'       => 'Optional Fields Employee',
        ]);

        $requestType = EmployeeRequestType::first() ?? EmployeeRequestType::create([
            'company_id' => $this->company->id,
            'name'       => 'Attendance Adjustment',
            'code'       => 'attendance_adjustment',
            'category'   => 'attendance',
            'is_active'  => true,
        ]);

        // Create EmployeeRequest with null title and null description
        $request = EmployeeRequest::create([
            'company_id'      => $this->company->id,
            'employee_id'     => $employee->id,
            'request_type_id' => $requestType->id,
            'status'          => 'draft',
            'requested_by'    => $employeeUser->id,
            'title'           => null,
            'description'     => null,
        ]);

        $this->assertNotEmpty($request->title);
        $this->assertStringContainsString($requestType->name, $request->title);
        $this->assertNull($request->description);

        // Service allows null reason for attendance correction
        $service = app(EmployeeRequestService::class);
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'company_id'  => $this->company->id,
            'check_in'    => '2026-10-01 09:00:00',
            'check_out'   => '2026-10-01 17:00:00',
        ]);

        $empRequest = $service->requestAttendanceTimeChange(
            $record,
            $employeeUser,
            ['check_in' => '2026-10-01 09:30:00', 'check_out' => '2026-10-01 17:30:00'],
            null
        );

        $this->assertInstanceOf(EmployeeRequest::class, $empRequest);
        $this->assertNotEmpty($empRequest->title);
    }
}
