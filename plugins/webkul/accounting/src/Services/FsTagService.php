<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Account\CanonicalAccountCreationService;
use Webkul\Support\Models\Company;

final class FsTagService
{
    public function __construct(private readonly CanonicalAccountCreationService $accounts) {}

    /** @param array<string, mixed> $data */
    public function create(Company $company, array $data): FsTag
    {
        return Cache::lock("accounting-fs-tag-code:{$company->id}", 10)->block(5, function () use ($company, $data): FsTag {
            return DB::transaction(function () use ($company, $data): FsTag {
                $account = $this->resolveAccount($company, $data);
                $code = trim((string) ($data['code'] ?? '')) ?: $this->nextCode($company);
                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('The FS Tag name is required.');
                }

                $normalizedCode = mb_strtoupper($code);
                if (FsTag::query()->where('company_id', $company->id)->whereRaw('UPPER(code) = ?', [$normalizedCode])->exists()) {
                    throw new RuntimeException("An FS Tag with code '{$code}' already exists in this company.");
                }

                return FsTag::query()->create([
                    'company_id'        => $company->id,
                    'account_id'        => $account?->id,
                    'creator_id'        => auth()->id(),
                    'code'              => $code,
                    'name'              => $name,
                    'cash_flow_category'=> $data['cash_flow_category'] ?? null,
                    'tax_treatment'     => $data['tax_treatment'] ?? null,
                    'is_active'         => $data['is_active'] ?? true,
                ]);
            });
        });
    }

    /** @param array<string, mixed> $data */
    private function resolveAccount(Company $company, array $data): ?Account
    {
        if (! empty($data['account_id'])) {
            $account = Account::query()
                ->postable()
                ->where('deprecated', false)
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
                ->find($data['account_id']);
            if (! $account) {
                throw new RuntimeException('The mapped GL must be an active postable account owned by this company.');
            }

            return $account;
        }

        if (! ($data['create_account'] ?? false)) {
            return null;
        }

        $accountCode = trim((string) ($data['account_code'] ?? '')) ?: $this->nextAccountCode($company);

        return $this->accounts->createOffsetAccount($company, [
            'code'             => $accountCode,
            'name'             => trim((string) ($data['account_name'] ?? $data['name'] ?? '')),
            'account_type'     => $data['account_type'] ?? AccountType::EXPENSE->value,
            'currency_id'      => $data['currency_id'] ?? null,
            'parent_id'        => $data['parent_id'] ?? null,
            'active'           => true,
            'is_group'         => false,
            'nature'           => $data['nature'] ?? null,
            'classification_1' => $data['classification_1'] ?? null,
            'description'      => $data['description'] ?? null,
        ]);
    }

    /**
     * Resolve an FS Tag by its code within a company, case-insensitively (codes are
     * normalized to uppercase on save — see FsTag::booted()). Centralizes the lookup
     * previously duplicated across ImportPreviewService, ImportExecutionService and
     * BankStatementImportService.
     */
    public function resolve(int $companyId, string $code, bool $activeOnly = true): ?FsTag
    {
        $normalized = mb_strtoupper(trim($code));
        if ($normalized === '') {
            return null;
        }

        return FsTag::query()
            ->where('company_id', $companyId)
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->first();
    }

    /**
     * Whether a code exists for any company, used to distinguish "unknown code" from
     * "belongs to another company" in validation messages.
     */
    public function existsForAnyCompany(string $code): bool
    {
        $normalized = mb_strtoupper(trim($code));
        if ($normalized === '') {
            return false;
        }

        return FsTag::query()->whereRaw('UPPER(code) = ?', [$normalized])->exists();
    }

    /**
     * Explain, in plain language, why a raw FS Tag code from an imported file
     * did not resolve to an active tag for this company. Returns null only
     * when the code is blank — there is nothing to explain.
     */
    public function diagnose(int $companyId, string $code): ?string
    {
        $normalized = mb_strtoupper(trim($code));
        if ($normalized === '') {
            return null;
        }

        $retiredForThisCompany = FsTag::query()
            ->where('company_id', $companyId)
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->where('is_active', false)
            ->exists();

        if ($retiredForThisCompany) {
            return "Tag code \"{$code}\" has been retired for this company. Reactivate it under FS Tag settings, or use a different code.";
        }

        if ($this->existsForAnyCompany($code)) {
            return "Tag code \"{$code}\" belongs to a different company. Add a matching tag for this company under FS Tag settings.";
        }

        return "Tag code \"{$code}\" isn't set up yet. Check the spelling, or add it under FS Tag settings.";
    }

    private function nextCode(Company $company): string
    {
        $next = ((int) FsTag::query()->where('company_id', $company->id)->max('id')) + 1;

        return sprintf('FS-%06d', $next);
    }

    private function nextAccountCode(Company $company): string
    {
        $next = Account::query()
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
            ->count() + 1;

        do {
            $code = sprintf('FSGL-%06d', $next++);
        } while (Account::query()->where('code', $code)->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))->exists());

        return $code;
    }
}
