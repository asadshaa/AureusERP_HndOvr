<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Offer of employment {{ $reference }}</title>
    <style>
        @page { margin: 48px 56px 64px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color: #1f2937; line-height: 1.55; }
        .letterhead { border-bottom: 2px solid #1f2937; padding-bottom: 12px; margin-bottom: 24px; }
        .letterhead td { vertical-align: middle; }
        .logo { max-height: 56px; max-width: 160px; }
        .company { font-size: 18px; font-weight: bold; }
        .company-meta { font-size: 10px; color: #6b7280; }
        h1, h2, h3 { color: #111827; }
        h3 { font-size: 12.5px; margin: 16px 0 4px; }
        p { margin: 0 0 8px; }
        ul, ol { margin: 0 0 8px 18px; padding: 0; }
        table { border-collapse: collapse; }
        .footer { position: fixed; bottom: -36px; left: 0; right: 0; font-size: 9px; color: #9ca3af; text-align: center; }
        .acceptance { margin-top: 36px; border-top: 1px dashed #9ca3af; padding-top: 12px; font-size: 10.5px; }
        .acceptance td { padding: 10px 24px 0 0; }
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
                    @if ($company?->website) &middot; {{ $company->website }} @endif
                </div>
            </td>
        </tr>
    </table>

    {!! $body !!}

    <div class="acceptance">
        <strong>Acceptance</strong><br>
        I accept this offer of employment on the terms above.
        <table>
            <tr>
                <td>Name: ____________________________</td>
                <td>Signature: ______________________</td>
                <td>Date: ______________</td>
            </tr>
        </table>
    </div>

    <div class="footer">{{ $company?->name }} &middot; Offer of employment &middot; {{ $reference }} &middot; Private and confidential</div>
</body>
</html>
