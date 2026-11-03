<?php

namespace Webkul\Account\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Http\Requests\Concerns\ValidatesCompanyReferences;
use Webkul\Account\Models\Journal;
use Webkul\Security\Services\CompanyScopedRules;

/**
 * A journal belongs to one of the token user's companies (the active company
 * when none is sent, as on the Journals screen); its accounts must be linked to
 * that company and its bank account must be one of that company's partners'.
 */
class JournalRequest extends FormRequest
{
    use ValidatesCompanyReferences;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** A new journal without a company goes to the active company (as on the Journals screen). */
    protected function prepareForValidation(): void
    {
        if (! $this->isUpdateRequest() && blank($this->input('company_id')) && $this->activeCompanyId() !== null) {
            $this->merge(['company_id' => $this->activeCompanyId()]);
        }
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
        $companyId = $this->targetCompanyId(Journal::class);
        $account = fn (): array => ['nullable', 'integer', $this->companyAccountRule($companyId)];

        return [
            'name'                      => [...$requiredRule, 'string', 'max:255'],
            'code'                      => [...$requiredRule, 'string', 'max:5'],
            'type'                      => [...$requiredRule, 'string', Rule::enum(JournalType::class)],
            'company_id'                => [...($isUpdate ? ['sometimes', 'required'] : ['nullable']), 'integer', CompanyScopedRules::company(user: $this->apiUser())],
            'currency_id'               => ['nullable', 'integer', 'exists:currencies,id'],
            'default_account_id'        => $account(),
            'suspense_account_id'       => $account(),
            'profit_account_id'         => $account(),
            'loss_account_id'           => $account(),
            'bank_account_id'           => ['nullable', 'integer', CompanyScopedRules::existsThrough('partners_bank_accounts', 'partner_id', 'partners_partners', [(int) $companyId])],
            'color'                     => ['nullable', 'string', 'max:7'],
            'show_on_dashboard'         => ['nullable', 'boolean'],
            'refund_order'              => ['nullable', 'boolean'],
            'payment_order'             => ['nullable', 'boolean'],

            // Inbound payment method lines
            'inbound_payment_method_lines'                       => ['nullable', 'array'],
            'inbound_payment_method_lines.*.id'                  => ['sometimes', 'integer', 'exists:accounts_payment_method_lines,id'],
            'inbound_payment_method_lines.*.payment_method_id'   => ['required', 'integer', 'exists:accounts_payment_methods,id'],
            'inbound_payment_method_lines.*.name'                => ['required', 'string', 'max:255'],
            'inbound_payment_method_lines.*.payment_account_id'  => $account(),

            // Outbound payment method lines
            'outbound_payment_method_lines'                      => ['nullable', 'array'],
            'outbound_payment_method_lines.*.id'                 => ['sometimes', 'integer', 'exists:accounts_payment_method_lines,id'],
            'outbound_payment_method_lines.*.payment_method_id'  => ['required', 'integer', 'exists:accounts_payment_methods,id'],
            'outbound_payment_method_lines.*.name'               => ['required', 'string', 'max:255'],
            'outbound_payment_method_lines.*.payment_account_id' => $account(),
        ];
    }

    /**
     * Get body parameters for Scribe documentation.
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Journal name',
                'example'     => 'Bank Journal',
            ],
            'code' => [
                'description' => 'Journal code (max 5 chars)',
                'example'     => 'BNK',
            ],
            'type' => [
                'description' => 'Journal type',
                'example'     => JournalType::BANK->value,
            ],
            'company_id' => [
                'description' => 'Company ID',
                'example'     => 1,
            ],
            'currency_id' => [
                'description' => 'Currency ID',
                'example'     => 1,
            ],
            'default_account_id' => [
                'description' => 'Default account ID',
                'example'     => 1,
            ],
            'suspense_account_id' => [
                'description' => 'Suspense account ID',
                'example'     => 2,
            ],
            'profit_account_id' => [
                'description' => 'Profit account ID',
                'example'     => 3,
            ],
            'loss_account_id' => [
                'description' => 'Loss account ID',
                'example'     => 4,
            ],
            'bank_account_id' => [
                'description' => 'Bank account ID',
                'example'     => 1,
            ],
            'color' => [
                'description' => 'Journal color (hex)',
                'example'     => '#3b82f6',
            ],
            'show_on_dashboard' => [
                'description' => 'Show on dashboard',
                'example'     => true,
            ],
            'refund_order' => [
                'description' => 'Refund order flag',
                'example'     => false,
            ],
            'payment_order' => [
                'description' => 'Payment order flag',
                'example'     => false,
            ],
            'inbound_payment_method_lines' => [
                'description' => 'Inbound payment method lines (for BANK, CASH, CREDIT_CARD journals). Include id for update, omit for create.',
                'example'     => [
                    [
                        'payment_method_id'  => 1,
                        'name'               => 'Manual',
                        'payment_account_id' => 5,
                    ],
                ],
            ],
            'outbound_payment_method_lines' => [
                'description' => 'Outbound payment method lines (for BANK, CASH, CREDIT_CARD journals). Include id for update, omit for create.',
                'example'     => [
                    [
                        'payment_method_id'  => 2,
                        'name'               => 'Electronic',
                        'payment_account_id' => 6,
                    ],
                ],
            ],
        ];
    }
}
