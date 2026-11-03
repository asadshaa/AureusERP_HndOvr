<?php

namespace Webkul\Account\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Http\Requests\Concerns\ValidatesCompanyReferences;
use Webkul\Account\Models\Account;

class AccountRequest extends FormRequest
{
    use ValidatesCompanyReferences;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $requiredRule = $isUpdate ? ['sometimes', 'required'] : ['required'];

        return [
            'name'         => [...$requiredRule, 'string', 'max:255'],
            'code'         => [...$requiredRule, 'string', 'max:64', $this->uniqueCodeInCompanies(...)],
            'account_type' => [...$requiredRule, 'string', Rule::enum(AccountType::class)],
            'currency_id'  => ['nullable', 'integer', 'exists:currencies,id'],
            'note'         => ['nullable', 'string'],
            'deprecated'   => ['nullable', 'boolean'],
            'reconcile'    => ['nullable', 'boolean'],
            'non_trade'    => ['nullable', 'boolean'],
        ];
    }

    /**
     * Account codes are unique per company: a new account takes the active
     * company, an existing one is checked in every company it is linked to.
     */
    protected function uniqueCodeInCompanies(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $accountId = $this->isUpdateRequest() ? $this->routeRecordId() : null;

        $companyIds = $accountId !== null
            ? Account::query()->whereKey($accountId)->first()?->companies()->pluck('companies.id')->map(fn ($id): int => (int) $id)->all() ?? []
            : array_filter([$this->activeCompanyId()]);

        if ($companyIds !== [] && Account::codeExistsInCompanies($value, $companyIds, $accountId)) {
            $fail('The code has already been taken in this company\'s chart of accounts.');
        }
    }

    /**
     * Get body parameters for Scribe documentation.
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Account name',
                'example'     => 'Cash and Bank',
            ],
            'code' => [
                'description' => 'Account code',
                'example'     => '1000',
            ],
            'account_type' => [
                'description' => 'Account type',
                'example'     => AccountType::ASSET_RECEIVABLE->value,
            ],
            'currency_id' => [
                'description' => 'Currency ID',
                'example'     => 1,
            ],
            'note' => [
                'description' => 'Account notes',
                'example'     => 'Primary cash account',
            ],
            'deprecated' => [
                'description' => 'Mark account as deprecated',
                'example'     => false,
            ],
            'reconcile' => [
                'description' => 'Enable reconciliation',
                'example'     => true,
            ],
            'non_trade' => [
                'description' => 'Non-trade account flag',
                'example'     => false,
            ],
        ];
    }
}
