<?php

namespace Webkul\Account\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Webkul\Account\Http\Requests\Concerns\ValidatesCompanyReferences;
use Webkul\Account\Models\Move;
use Webkul\Security\Services\CompanyScopedRules;

/**
 * Registering a payment on a move: the payment journal and payment method line
 * must belong to the move's company, the bank account to a partner of one of
 * the token user's companies.
 */
class MovePaymentRequest extends FormRequest
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
        $moveCompany = [(int) $this->routeRecordCompanyId(Move::class)];

        return [
            'journal_id'             => ['sometimes', 'required', 'integer', CompanyScopedRules::exists('accounts_journals', $moveCompany)],
            'payment_method_line_id' => ['sometimes', 'required', 'integer', CompanyScopedRules::existsThrough('accounts_payment_method_lines', 'journal_id', 'accounts_journals', $moveCompany)],
            'partner_bank_id'        => ['nullable', 'integer', CompanyScopedRules::existsThrough('partners_bank_accounts', 'partner_id', 'partners_partners', user: $this->apiUser())],
            'currency_id'            => ['sometimes', 'required', 'integer', 'exists:currencies,id'],
            'payment_date'           => ['sometimes', 'required', 'date'],
            'communication'          => ['sometimes', 'required', 'string', 'max:255'],
            'installments_mode'      => ['nullable', 'string', 'in:full,overdue,before_date,next'],
            'amount'                 => ['sometimes', 'required', 'numeric', 'min:0.0001'],
        ];
    }

    /**
     * Get body parameters for API documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'journal_id' => [
                'description' => 'Payment journal ID. If omitted, the first available journal is used.',
                'example'     => 1,
            ],
            'payment_method_line_id' => [
                'description' => 'Payment method line ID for selected journal. If omitted, default method is auto-detected.',
                'example'     => 1,
            ],
            'partner_bank_id' => [
                'description' => 'Partner bank account ID when required by payment method.',
                'example'     => 3,
            ],
            'currency_id' => [
                'description' => 'Payment currency ID. If omitted, journal currency or document currency is used.',
                'example'     => 1,
            ],
            'payment_date' => [
                'description' => 'Payment date.',
                'example'     => '2026-02-17',
            ],
            'communication' => [
                'description' => 'Payment reference/communication.',
                'example'     => 'INV/2026/0001',
            ],
            'installments_mode' => [
                'description' => 'Installment mode.',
                'example'     => 'full',
            ],
            'amount' => [
                'description' => 'Payment amount.',
                'example'     => 250.75,
            ],
        ];
    }
}
