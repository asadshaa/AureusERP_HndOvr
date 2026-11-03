<?php

/**
 * Bank mapping rules decide which GL a bank line is booked to, so every
 * change to one is kept in the configuration audit (as for FS tags, import
 * profiles and business rules).
 */

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Models\BankMappingRule;
use Webkul\Accounting\Models\ConfigurationAudit;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

it('audits creating, changing and deleting a bank mapping rule', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    test()->actingAs($user);

    $offset = Account::factory()->create(['account_type' => AccountType::EXPENSE]);
    $other = Account::factory()->create(['account_type' => AccountType::EXPENSE]);

    $rule = BankMappingRule::query()->create(['company_id' => $company->id, 'name' => 'Bank fees', 'offset_account_id' => $offset->id]);
    $rule->update(['offset_account_id' => $other->id]);
    $rule->delete();

    $audits = ConfigurationAudit::query()
        ->where('auditable_type', $rule->getMorphClass())
        ->where('auditable_id', $rule->id)
        ->orderBy('id')
        ->get();

    expect($audits->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($audits->pluck('company_id')->unique()->all())->toBe([$company->id])
        ->and($audits->pluck('actor_id')->unique()->all())->toBe([$user->id])
        ->and((int) $audits[1]->before['offset_account_id'])->toBe($offset->id)
        ->and((int) $audits[1]->after['offset_account_id'])->toBe($other->id);
});
