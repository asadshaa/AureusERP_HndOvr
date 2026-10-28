<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $letterTitle }}</title>
    <style>
        @page { margin: 48px 56px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; line-height: 1.6; }
        .letterhead { border-bottom: 2px solid #1f2937; padding-bottom: 12px; margin-bottom: 28px; }
        .letterhead td { vertical-align: middle; }
        .logo { max-height: 56px; max-width: 160px; }
        .company { font-size: 18px; font-weight: bold; }
        .company-meta { font-size: 10px; color: #6b7280; }
        .meta { margin-bottom: 24px; }
        .meta td { padding: 2px 12px 2px 0; }
        h1 { font-size: 15px; text-align: center; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 24px; }
        .details { margin: 16px 0 24px; border-collapse: collapse; }
        .details td { padding: 4px 16px 4px 0; }
        .details td.label { color: #6b7280; }
        .signature { margin-top: 56px; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 9px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <table class="letterhead" width="100%">
        <tr>
            @if ($logo)
                <td width="170"><img class="logo" src="{{ $logo }}" alt=""></td>
            @endif
            <td>
                <div class="company">{{ $company?->name }}</div>
                <div class="company-meta">
                    {{ collect([$company?->street1, $company?->city, $company?->country?->name])->filter()->implode(', ') }}
                    @if ($company?->email) &middot; {{ $company->email }} @endif
                    @if ($company?->phone) &middot; {{ $company->phone }} @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr><td>Date:</td><td>{{ $issuedOn->format('d F Y') }}</td></tr>
        @if ($reference)
            <tr><td>Ref:</td><td>{{ $reference }}</td></tr>
        @endif
        <tr><td>To:</td><td>{{ $addressedTo }}</td></tr>
    </table>

    <h1>{{ $letterTitle }}</h1>

    <p>
        This is to certify that <strong>{{ $employee?->name }}</strong>
        @if ($designation) is employed with {{ $company?->name }} as <strong>{{ $designation }}</strong>@else is employed with {{ $company?->name }}@endif
        @if ($employee?->joining_date) since {{ $employee->joining_date->format('d F Y') }}@endif.
    </p>

    <table class="details">
        <tr><td class="label">Employee name</td><td>{{ $employee?->name }}</td></tr>
        @if ($designation)
            <tr><td class="label">Designation</td><td>{{ $designation }}</td></tr>
        @endif
        @if ($employee?->joining_date)
            <tr><td class="label">Date of joining</td><td>{{ $employee->joining_date->format('d F Y') }}</td></tr>
        @endif
        @if ($salary !== null)
            <tr><td class="label">Monthly salary</td><td>{{ $salary }} {{ $currency }}</td></tr>
        @endif
    </table>

    @if ($purpose)
        <p>This letter is issued on the employee's request for the purpose of: {{ $purpose }}.</p>
    @endif

    <p>This letter is issued without any liability on the part of {{ $company?->name }}.</p>

    <div class="signature">
        <p>For {{ $company?->name }}</p>
        <br><br>
        <p>________________________<br>{{ $issuer?->name }}<br>Human Resources</p>
    </div>

    <div class="footer">{{ $company?->name }} &middot; {{ $letterTitle }} &middot; {{ $reference }}</div>
</body>
</html>
