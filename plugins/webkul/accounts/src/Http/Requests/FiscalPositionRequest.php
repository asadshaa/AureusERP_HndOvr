<?php

namespace Webkul\Account\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Account\Http\Requests\Concerns\ValidatesCompanyReferences;
use Webkul\Account\Models\FiscalPosition;
use Webkul\Security\Services\CompanyScopedRules;

/**
 * A fiscal position belongs to one of the token user's companies; the taxes
 * and accounts it maps must be that company's.
 */
class FiscalPositionRequest extends FormRequest
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
        $isUpdate = $this->isUpdateRequest();
        $requiredRule = $isUpdate ? ['sometimes', 'required'] : ['required'];
        $companyId = $this->targetCompanyId(FiscalPosition::class);
        $companyTax = Rule::exists('accounts_taxes', 'id')->where('company_id', $companyId ?? 0);
        $companyAccount = $this->companyAccountRule($companyId);

        return [
            'name'             => [...$requiredRule, 'string', 'max:255'],
            'company_id'       => [...$requiredRule, 'integer', CompanyScopedRules::company(user: $this->apiUser())],
            'country_id'       => ['nullable', 'integer', 'exists:countries,id'],
            'country_group_id' => ['nullable', 'integer', 'exists:countries,id'],
            'zip_from'         => ['nullable', 'string', 'max:10'],
            'zip_to'           => ['nullable', 'string', 'max:10'],
            'notes'            => ['nullable', 'string'],
            'auto_reply'       => ['nullable', 'boolean'],
            'vat_required'     => ['nullable', 'boolean'],
            'foreign_vat'      => ['nullable', 'string', 'max:255'],

            // Tax mappings
            'taxes'                          => ['nullable', 'array'],
            'taxes.*.id'                     => ['sometimes', 'integer', 'exists:accounts_fiscal_position_taxes,id'],
            'taxes.*.tax_source_id'          => ['required', 'integer', $companyTax],
            'taxes.*.tax_destination_id'     => ['nullable', 'integer', $companyTax],

            // Account mappings
            'accounts'                          => ['nullable', 'array'],
            'accounts.*.id'                     => ['sometimes', 'integer', 'exists:accounts_fiscal_position_accounts,id'],
            'accounts.*.account_source_id'      => ['required', 'integer', $companyAccount],
            'accounts.*.account_destination_id' => ['required', 'integer', $companyAccount],
        ];
    }

    /**
     * Get body parameters for Scribe documentation.
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Fiscal position name',
                'example'     => 'Domestic',
            ],
            'company_id' => [
                'description' => 'Company ID',
                'example'     => 1,
            ],
            'country_id' => [
                'description' => 'Country ID',
                'example'     => 233,
            ],
            'country_group_id' => [
                'description' => 'Country group ID',
                'example'     => null,
            ],
            'zip_from' => [
                'description' => 'ZIP code from',
                'example'     => '10000',
            ],
            'zip_to' => [
                'description' => 'ZIP code to',
                'example'     => '99999',
            ],
            'notes' => [
                'description' => 'Fiscal position notes',
                'example'     => 'For domestic transactions',
            ],
            'auto_reply' => [
                'description' => 'Auto reply enabled',
                'example'     => false,
            ],
            'vat_required' => [
                'description' => 'VAT required flag',
                'example'     => true,
            ],
            'foreign_vat' => [
                'description' => 'Foreign VAT',
                'example'     => null,
            ],
            'taxes' => [
                'description' => 'Tax mappings array. Include id for update, omit for create.',
                'example'     => [
                    [
                        'tax_source_id'      => 1,
                        'tax_destination_id' => 2,
                    ],
                ],
            ],
            'accounts' => [
                'description' => 'Account mappings array. Include id for update, omit for create.',
                'example'     => [
                    [
                        'account_source_id'      => 5,
                        'account_destination_id' => 6,
                    ],
                ],
            ],
        ];
    }
}
