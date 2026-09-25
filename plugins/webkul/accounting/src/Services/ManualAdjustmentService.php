<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Services\PeriodLockService;
use Webkul\Accounting\Enums\ManualAdjustmentStatus;
use Webkul\Accounting\Models\ManualAdjustment;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;

class ManualAdjustmentService
{
    public function requiresConfiguredApproval(ManualAdjustment $adjustment): bool
    {
        return app(ApprovalEngine::class)->matchingWorkflow(
            (int) $adjustment->company_id,
            'manual_adjustment',
            null,
            ['amount' => (float) $adjustment->amount],
        ) !== null;
    }

    public function submit(ManualAdjustment $adjustment, User $requester): ApprovalRequest
    {
        return app(ApprovalEngine::class)->submit(
            $adjustment,
            $requester,
            'manual_adjustment',
            null,
            ['amount' => (float) $adjustment->amount],
        );
    }

    public function approve(ManualAdjustment $adjustment, User $reviewer): ManualAdjustment
    {
        if ($this->requiresConfiguredApproval($adjustment) && ! ApprovalRequest::query()
            ->where('company_id', $adjustment->company_id)
            ->where('request_type', 'manual_adjustment')
            ->where('subject_type', $adjustment->getMorphClass())
            ->where('subject_id', $adjustment->id)
            ->where('status', 'approved')
            ->exists()) {
            throw new RuntimeException('This manual adjustment requires a completed approval workflow before approval.');
        }

        $adjustment->update([
            'approval_status' => ManualAdjustmentStatus::Approved,
            'reviewer_id'     => $reviewer->id,
            'reviewed_at'     => now(),
        ]);

        return $adjustment->fresh();
    }

    public function createDraft(ManualAdjustment $adjustment): Move
    {
        return DB::transaction(function () use ($adjustment): Move {
            $adjustment = ManualAdjustment::query()->with(['company', 'debitAccount.companies', 'creditAccount.companies'])->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->move_id) {
                return Move::query()->findOrFail($adjustment->move_id);
            }

            if ($adjustment->approval_status !== ManualAdjustmentStatus::Approved) {
                throw new RuntimeException('The manual adjustment must be approved before journal generation.');
            }

            foreach ([$adjustment->debitAccount, $adjustment->creditAccount] as $account) {
                if ($account->is_group || $account->deprecated || ! $account->companies->contains('id', $adjustment->company_id)) {
                    throw new RuntimeException('Adjustment accounts must be active postable accounts for the selected company.');
                }
            }

            if ((float) $adjustment->amount <= 0 || $adjustment->debit_account_id === $adjustment->credit_account_id) {
                throw new RuntimeException('Manual adjustment amount/accounts are invalid.');
            }

            $journalId = $adjustment->journal_id ?? Journal::query()
                ->where('company_id', $adjustment->company_id)
                ->where('type', 'general')
                ->value('id');
            if (! $journalId) {
                throw new RuntimeException('No general journal is available for the selected company.');
            }

            $currencyId = $adjustment->company->currency_id;
            $now = now();
            $moveId = DB::table('accounts_account_moves')->insertGetId([
                'journal_id'           => $journalId, 'company_id' => $adjustment->company_id, 'currency_id' => $currencyId,
                'date'                 => $adjustment->date, 'name' => 'Draft '.$adjustment->adjustment_reference,
                'reference'            => $adjustment->supporting_reference, 'move_type' => MoveType::ENTRY->value,
                'state'                => MoveState::DRAFT->value, 'accounting_source_type' => 'manual_adjustment',
                'accounting_source_id' => $adjustment->id, 'cash_flow_category' => 'Non-cash',
                'tax_treatment'        => $adjustment->tax_treatment, 'review_status' => 'approved',
                'created_at'           => $now, 'updated_at' => $now,
            ]);

            $amount = (float) $adjustment->amount;
            DB::table('accounts_account_move_lines')->insert([
                [
                    'move_id'      => $moveId, 'journal_id' => $journalId, 'account_id' => $adjustment->debit_account_id,
                    'company_id'   => $adjustment->company_id, 'company_currency_id' => $currencyId, 'currency_id' => $currencyId,
                    'date'         => $adjustment->date, 'debit' => $amount, 'credit' => 0, 'balance' => $amount,
                    'parent_state' => MoveState::DRAFT->value, 'name' => $adjustment->description, 'sort' => 0,
                    'created_at'   => $now, 'updated_at' => $now,
                ],
                [
                    'move_id'      => $moveId, 'journal_id' => $journalId, 'account_id' => $adjustment->credit_account_id,
                    'company_id'   => $adjustment->company_id, 'company_currency_id' => $currencyId, 'currency_id' => $currencyId,
                    'date'         => $adjustment->date, 'debit' => 0, 'credit' => $amount, 'balance' => -$amount,
                    'parent_state' => MoveState::DRAFT->value, 'name' => $adjustment->description, 'sort' => 1,
                    'created_at'   => $now, 'updated_at' => $now,
                ],
            ]);

            $adjustment->update(['journal_id' => $journalId, 'move_id' => $moveId]);

            return Move::query()->with('lines')->findOrFail($moveId);
        });
    }

    public function post(ManualAdjustment $adjustment, User $reviewer): Move
    {
        return DB::transaction(function () use ($adjustment, $reviewer): Move {
            $adjustment = ManualAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            $move = $adjustment->move_id ? Move::query()->find($adjustment->move_id) : null;
            $move ??= $this->createDraft($adjustment);

            if ($move->state !== MoveState::POSTED) {
                app(PeriodLockService::class)->assertNotLocked((int) $adjustment->company_id, $move->date);

                $totals = DB::table('accounts_account_move_lines')->where('move_id', $move->id)
                    ->selectRaw('ROUND(SUM(debit), 2) debit, ROUND(SUM(credit), 2) credit')->first();
                if (! $totals || abs((float) $totals->debit - (float) $totals->credit) > 0.005) {
                    throw new RuntimeException('Manual adjustment journal is unbalanced.');
                }

                DB::table('accounts_account_moves')->where('id', $move->id)->update(['state' => MoveState::POSTED->value, 'review_status' => 'posted', 'updated_at' => now()]);
                DB::table('accounts_account_move_lines')->where('move_id', $move->id)->update(['parent_state' => MoveState::POSTED->value, 'updated_at' => now()]);
            }

            $adjustment->update([
                'approval_status' => ManualAdjustmentStatus::Posted,
                'reviewer_id'     => $reviewer->id,
                'reviewed_at'     => $adjustment->reviewed_at ?? now(),
                'posted_at'       => now(),
            ]);

            return $move->fresh('lines');
        });
    }
}
