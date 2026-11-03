<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Database\Seeders\DemoAccountingSeeder;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\BankTransferMatch;
use Webkul\Accounting\Models\BusinessRule;
use Webkul\Accounting\Models\ExchangeRate;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Models\ManualAdjustment;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

class SeedAccountingDemo extends Command
{
    protected $signature = 'accounting:seed-demo {--fresh : Delete existing demo records and re-seed from scratch}';

    protected $description = 'Seed an idempotent, production-grade demo accounting dataset for end-to-end testing';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->warn('Clearing only demo records created by DemoAccountingSeeder...');
            $this->cleanDemoData();
            $this->info('Demo records cleaned successfully.');
        }

        $this->info('Seeding Aureus ERP demo accounting data...');
        $seeder = app(DemoAccountingSeeder::class);
        $seeder->setCommand($this);
        $seeder->run();

        $this->info('Demo accounting dataset seeding completed successfully!');

        return self::SUCCESS;
    }

    protected function cleanDemoData(): void
    {
        $demoCompanies = Company::query()
            ->whereIn('name', ['Truck It In (Demo)', 'Rider Demo'])
            ->get();

        if ($demoCompanies->isEmpty()) {
            return;
        }

        $coIds = $demoCompanies->pluck('id')->all();

        DB::transaction(function () use ($coIds) {
            // 1. Bank mappings, matches, statements
            BankTransactionMapping::query()->whereIn('company_id', $coIds)->delete();
            BankTransferMatch::query()->whereIn('company_id', $coIds)->delete();
            BankStatement::query()->whereIn('company_id', $coIds)->delete();

            // 2. Manual adjustments
            ManualAdjustment::query()->whereIn('company_id', $coIds)->delete();

            // 3. Approval workflows & steps
            $wfs = ApprovalWorkflow::query()->whereIn('company_id', $coIds)->get();
            foreach ($wfs as $wf) {
                $wf->steps()->delete();
                $wf->delete();
            }

            // 4. Import profiles & rules
            $profiles = ImportProfile::query()->whereIn('company_id', $coIds)->get();
            foreach ($profiles as $profile) {
                $profile->mappings()->delete();
                $profile->delete();
            }
            BusinessRule::query()->whereIn('company_id', $coIds)->delete();

            // 5. Invoices, bills, moves & move lines. The demo companies'
            // moves include posted ones, which the Move/MoveLine `deleting`
            // guards normally refuse; this wipe of a throw-away demo dataset
            // is the one explicit, narrow exception (see
            // Move::withoutPostedGuard()). Scoped to the demo company ids
            // resolved above.
            Move::withoutPostedGuard(function () use ($coIds): void {
                $moves = Move::query()->whereIn('company_id', $coIds)->get();
                foreach ($moves as $move) {
                    $move->lines()->delete();
                    $move->delete();
                }
            });

            // 6. FS Tags & Exchange rates
            FsTag::query()->whereIn('company_id', $coIds)->delete();
            ExchangeRate::query()->whereIn('company_id', $coIds)->delete();

            // 7. Partners
            Partner::query()->whereIn('company_id', $coIds)->delete();

            // 8. Journals
            Journal::query()->whereIn('company_id', $coIds)->delete();

            // 9. Accounts attached to demo companies
            $accounts = Account::query()->whereHas('companies', fn ($q) => $q->whereIn('companies.id', $coIds))->get();
            foreach ($accounts as $acc) {
                $acc->companies()->detach($coIds);
                if ($acc->companies()->count() === 0) {
                    $acc->delete();
                }
            }

            // 10. Demo users
            User::query()->whereIn('email', [
                'demo.accountant@aureus.test',
                'demo.approver@aureus.test',
            ])->delete();

            // 11. Demo companies
            Company::query()->whereIn('id', $coIds)->delete();
        });
    }
}
