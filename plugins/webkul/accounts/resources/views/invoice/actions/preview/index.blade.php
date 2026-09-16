<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style type="text/css">
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 13px;
            color: #222222;
            line-height: 1.5;
            margin: 0;
        }

        .agreement {
            margin-bottom: 50px;
            page-break-after: always;
        }

        .agreement:last-child {
            page-break-after: auto;
        }

        .clearfix {
            clear: both;
        }

        /* Letterhead */
        .header {
            width: 100%;
            margin-bottom: 20px;
        }

        .company-info {
            width: 60%;
            float: left;
        }

        .company-info .company-name {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .company-logo {
            width: 35%;
            float: right;
            text-align: right;
        }

        .company-logo img {
            max-width: 180px;
            max-height: 80px;
        }

        .registration-line {
            clear: both;
            padding-top: 10px;
            color: #555555;
        }

        /* Title */
        .document-title {
            font-size: 20px;
            color: #1a4587;
            margin: 25px 0 15px;
        }

        /* Bill-to / metadata */
        .bill-meta {
            width: 100%;
            margin-bottom: 20px;
        }

        .bill-to {
            width: 55%;
            float: left;
        }

        .bill-to .label {
            color: #888888;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        .bill-to .customer-name {
            font-weight: bold;
            margin: 4px 0;
        }

        .meta-table {
            width: 40%;
            float: right;
            border-collapse: collapse;
        }

        .meta-table td {
            padding: 3px 0;
            font-size: 12px;
        }

        .meta-table td.meta-label {
            color: #888888;
            padding-right: 12px;
            white-space: nowrap;
        }

        .meta-table td.meta-value {
            font-weight: bold;
            text-align: right;
        }

        /* Items table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .items-table th {
            background: #d7ecec;
            color: #1a4587;
            padding: 10px;
            text-align: left;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        .items-table th.numeric,
        .items-table td.numeric {
            text-align: right;
        }

        .items-table td {
            padding: 10px;
            border-bottom: 1px solid #e9ecef;
        }

        /* Totals */
        .summary {
            width: 100%;
            margin-top: 10px;
        }

        .summary-notes {
            width: 55%;
            float: left;
            color: #555555;
            font-size: 12px;
        }

        .summary table {
            float: right;
            width: 280px;
        }

        .summary table td {
            padding: 4px 0;
        }

        .summary table td:first-child {
            color: #666666;
        }

        .summary table td:last-child {
            text-align: right;
        }

        .summary table tr.total-row td {
            border-top: 1px dashed #999999;
            padding-top: 8px;
            font-weight: bold;
        }

        .balance-due {
            clear: both;
            padding-top: 15px;
            text-align: right;
        }

        .balance-due .label {
            color: #888888;
            font-size: 11px;
        }

        .balance-due .amount {
            font-size: 16px;
            font-weight: bold;
        }

        /* Tax summary */
        .tax-summary-title {
            color: #1a4587;
            font-size: 14px;
            margin: 25px 0 10px;
            clear: both;
        }

        .tax-summary-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tax-summary-table th {
            background: #d7ecec;
            color: #1a4587;
            padding: 8px 10px;
            text-align: right;
            font-size: 11px;
        }

        .tax-summary-table th:first-child,
        .tax-summary-table td:first-child {
            text-align: left;
        }

        .tax-summary-table td {
            padding: 6px 10px;
            text-align: right;
            border-bottom: 1px solid #e9ecef;
        }

        .consolidated-number {
            margin-top: 15px;
            color: #888888;
            font-size: 11px;
        }

        .payment-info {
            clear: both;
            margin-top: 20px;
            padding: 15px 0;
            border-top: 1px solid #e9ecef;
        }

        .payment-info-title {
            font-weight: 600;
            margin-bottom: 8px;
        }
    </style>
</head>

<body>
    <div class="agreement">
        <!-- Letterhead -->
        <div class="header">
            <div class="company-info">
                <div class="company-name">{{ $record->company->name }}</div>

                @if ($record->company->partner)
                    @php
                        $companyStreetLine = collect([$record->company->partner->street1, $record->company->partner->street2])->filter()->implode(', ');
                        $companyLocalityLine = collect([$record->company->partner->city, $record->company->partner->state?->name, $record->company->partner->zip])->filter()->implode(', ');
                        $companyLocalityLine = collect([$companyLocalityLine, $record->company->partner->country?->name])->filter()->implode(', ');
                    @endphp
                    @if ($companyStreetLine)
                        <div>{{ $companyStreetLine }}</div>
                    @endif
                    @if ($companyLocalityLine)
                        <div>{{ $companyLocalityLine }}</div>
                    @endif
                @endif

                @if ($record->company->phone)
                    <div>{{ $record->company->phone }}</div>
                @endif

                @if ($record->company->website)
                    <div>{{ $record->company->website }}</div>
                @endif
            </div>

            @if ($record->company->partner?->avatar)
                <div class="company-logo">
                    <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->path($record->company->partner->avatar) }}" alt="{{ $record->company->name }}">
                </div>
            @endif

            <div class="clearfix"></div>

            @if ($record->company->tax_id || ($record->company->is_sales_tax_registered && $record->company->strn))
                <div class="registration-line">
                    @if ($record->company->tax_id)
                        {{ __('accounts::account-manager.documents.labels.tax-id') }}: {{ $record->company->tax_id }}
                    @endif
                    @if ($record->company->is_sales_tax_registered && $record->company->strn)
                        @if ($record->company->tax_id) &nbsp;|&nbsp; @endif
                        STRN: {{ $record->company->strn }}
                    @endif
                </div>
            @endif
        </div>

        <!-- Title -->
        <div class="document-title">
            {{ __('accounts::account-manager.documents.titles.invoice', ['name' => $record->name]) }}
        </div>

        <!-- Bill To / Metadata -->
        <div class="bill-meta">
            <div class="bill-to">
                <div class="label">{{ __('accounts::account-manager.documents.labels.bill-to') }}</div>
                <div class="customer-name">{{ $record->partner->name }}</div>
                @php
                    $customerStreetLine = collect([$record->partner->street1, $record->partner->street2])->filter()->implode(', ');
                    $customerLocalityLine = collect([$record->partner->city, $record->partner->state?->name, $record->partner->zip])->filter()->implode(', ');
                    $customerLocalityLine = collect([$customerLocalityLine, $record->partner->country?->name])->filter()->implode(', ');
                @endphp
                @if ($customerStreetLine)
                    <div>{{ $customerStreetLine }}</div>
                @endif
                @if ($customerLocalityLine)
                    <div>{{ $customerLocalityLine }}</div>
                @endif
            </div>

            <table class="meta-table">
                <tr>
                    <td class="meta-label">{{ __('accounts::account-manager.documents.labels.invoice') }}</td>
                    <td class="meta-value">{{ $record->name }}</td>
                </tr>
                @if ($record->invoice_date)
                    <tr>
                        <td class="meta-label">{{ __('accounts::account-manager.documents.labels.invoice-date') }}</td>
                        <td class="meta-value">{{ $record->invoice_date->format('d M Y') }}</td>
                    </tr>
                @endif
                @if ($record->invoicePaymentTerm)
                    <tr>
                        <td class="meta-label">{{ __('accounts::account-manager.documents.labels.terms') }}</td>
                        <td class="meta-value">{{ $record->invoicePaymentTerm->name }}</td>
                    </tr>
                @endif
                @if ($record->invoice_date_due)
                    <tr>
                        <td class="meta-label">{{ __('accounts::account-manager.documents.labels.due-date') }}</td>
                        <td class="meta-value">{{ $record->invoice_date_due->format('d M Y') }}</td>
                    </tr>
                @endif
            </table>

            <div class="clearfix"></div>
        </div>

        <!-- Items Table -->
        @if (! $record->invoiceLines->isEmpty())
            <table class="items-table">
                <thead>
                    <tr>
                        <th>{{ __('accounts::account-manager.documents.labels.description') }}</th>
                        <th class="numeric">{{ __('accounts::account-manager.documents.labels.quantity') }}</th>
                        <th class="numeric">{{ __('accounts::account-manager.documents.labels.rate') }}</th>
                        <th class="numeric">{{ __('accounts::account-manager.documents.labels.amount') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($record->invoiceLines as $item)
                        <tr>
                            <td>{{ $item->name ?: $item->product?->name }}</td>
                            <td class="numeric">{{ number_format($item->quantity, $item->quantity == (int) $item->quantity ? 0 : 2) }}</td>
                            <td class="numeric">{{ money($item->price_unit, $record->currency->name) }}</td>
                            <td class="numeric">{{ money($item->price_subtotal, $record->currency->name) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <!-- Summary -->
        <div class="summary">
            @if ($record->narration)
                <div class="summary-notes">
                    {!! $record->narration !!}
                </div>
            @endif

            <table>
                <tr>
                    <td>{{ __('accounts::account-manager.documents.labels.subtotal') }}</td>
                    <td>{{ money($record->amount_untaxed, $record->currency->name) }}</td>
                </tr>
                @if ($record->total_discount > 0)
                    <tr>
                        <td>{{ __('accounts::account-manager.documents.labels.discount') }}</td>
                        <td>-{{ money($record->total_discount, $record->currency->name) }}</td>
                    </tr>
                @endif
                <tr>
                    <td>{{ __('accounts::account-manager.documents.labels.tax') }}</td>
                    <td>{{ money($record->amount_tax, $record->currency->name) }}</td>
                </tr>
                <tr class="total-row">
                    <td>{{ __('accounts::account-manager.documents.labels.grand-total') }}</td>
                    <td>{{ money($record->amount_total, $record->currency->name) }}</td>
                </tr>
            </table>

            <div class="clearfix"></div>
        </div>

        <div class="balance-due">
            <span class="label">{{ __('accounts::account-manager.documents.labels.balance-due') }}:</span>
            <span class="amount">{{ money($record->amount_residual, $record->currency->name) }}</span>
        </div>

        <!-- Tax Summary -->
        @if ($record->taxLines->isNotEmpty())
            <div class="tax-summary-title">{{ __('accounts::account-manager.documents.labels.tax-summary') }}</div>

            <table class="tax-summary-table">
                <thead>
                    <tr>
                        <th>{{ __('accounts::account-manager.documents.labels.rate') }}</th>
                        <th>{{ __('accounts::account-manager.documents.labels.tax') }}</th>
                        <th>{{ __('accounts::account-manager.documents.labels.net') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($record->taxLines->groupBy('name') as $taxName => $lines)
                        <tr>
                            <td>{{ $taxName }}</td>
                            <td>{{ money($lines->sum(fn ($line) => abs($line->balance)), $record->currency->name) }}</td>
                            <td>{{ money($lines->sum(fn ($line) => abs($line->tax_base_amount)), $record->currency->name) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($record->consolidated_number)
            <div class="consolidated-number">
                {{ __('accounts::account-manager.documents.labels.consolidated-number') }}: {{ $record->consolidated_number }}
            </div>
        @endif

        <!-- Payment Information -->
        @if ($record->name)
            <div class="payment-info">
                <div class="payment-info-title">{{ __('accounts::account-manager.documents.labels.payment-information') }}</div>
                <div>
                    {{ __('accounts::account-manager.documents.labels.payment-communication') }}: {{ $record->name }}
                    @if ($record?->partnerBank?->bank?->name || $record?->partnerBank?->account_number)
                        <br>
                        <span>{{ __('accounts::account-manager.documents.labels.account-details') }}</span>
                        {{ $record?->partnerBank?->bank?->name ?? 'N/A' }}
                        ({{ $record?->partnerBank?->account_number ?? 'N/A' }})
                    @endif
                </div>
            </div>
        @endif
    </div>
</body>
</html>
