@php
    use Webkul\Recruitment\Models\JobOffer;
    use Webkul\Recruitment\Services\OfferLetterRenderer;

    $companyName = $offer?->company?->name;
    $title = match ($state) {
        'open'     => __('Your offer from :company', ['company' => $companyName]),
        'accepted' => __('Offer accepted'),
        'declined' => __('Offer declined'),
        'expired'  => __('This offer has expired'),
        'closed'   => __('This offer is no longer available'),
        default    => __('This link is not valid'),
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; color: #1f2937; font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        main { max-width: 680px; margin: 32px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; }
        .company { font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin: 0 0 4px; }
        h1 { font-size: 22px; margin: 0 0 16px; }
        dl { display: grid; grid-template-columns: minmax(140px, 38%) 1fr; gap: 8px 16px; margin: 16px 0; }
        dt { color: #6b7280; }
        dd { margin: 0; font-weight: 500; }
        .note { background: #f9fafb; border-radius: 8px; padding: 12px 14px; color: #4b5563; font-size: 14px; }
        .status { display: inline-block; border-radius: 999px; padding: 4px 12px; font-size: 13px; font-weight: 600; }
        .status.ok { background: #dcfce7; color: #166534; }
        .status.no { background: #fee2e2; color: #991b1b; }
        .status.off { background: #e5e7eb; color: #374151; }
        form { margin-top: 20px; }
        label { display: block; font-size: 14px; color: #4b5563; margin-bottom: 6px; }
        textarea { width: 100%; min-height: 84px; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; font: inherit; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 16px; }
        button { border: 0; border-radius: 8px; padding: 12px 20px; font: inherit; font-weight: 600; cursor: pointer; }
        .accept { background: #15803d; color: #fff; }
        .decline { background: #fff; color: #b91c1c; border: 1px solid #fca5a5; }
        a { color: #1d4ed8; }
        .errors { color: #b91c1c; font-size: 14px; }
        @media (max-width: 520px) { dl { grid-template-columns: 1fr; } dt { margin-top: 6px; } }
    </style>
</head>
<body>
<main>
    <div class="card">
        @if ($companyName)
            <p class="company">{{ $companyName }}</p>
        @endif
        <h1>{{ $title }}</h1>

        @if ($state === 'invalid')
            <p>{{ __('This offer link is not valid. It may have been changed, or a newer email replaced it. Please use the link in the most recent offer email, or reply to that email.') }}</p>
        @elseif ($state === 'expired')
            <p>{{ __('The offer was valid until :date and can no longer be accepted. If you are still interested, please reply to the offer email.', ['date' => OfferLetterRenderer::date($offer?->expiry_date)]) }}</p>
        @elseif ($state === 'closed')
            <p>{{ __('This offer has been withdrawn or replaced. Please reply to the offer email if you have any questions.') }}</p>
        @elseif ($state === 'accepted')
            <p><span class="status ok">{{ __('Accepted') }}</span></p>
            <p>{{ __('Thank you, :name. You accepted the offer on :date. Our HR team will contact you about your first day (:joining).', ['name' => $offer->candidateName(), 'date' => $offer->responded_at?->timezone(OfferLetterRenderer::timezone())->format('d F Y'), 'joining' => OfferLetterRenderer::date($offer->joining_date)]) }}</p>
        @elseif ($state === 'declined')
            <p><span class="status no">{{ __('Declined') }}</span></p>
            <p>{{ __('You declined the offer on :date. Thank you for letting us know, and for your time with us.', ['date' => $offer->responded_at?->timezone(OfferLetterRenderer::timezone())->format('d F Y')]) }}</p>
        @endif

        @if ($offer && in_array($state, ['open', 'accepted'], true))
            <dl>
                <dt>{{ __('Candidate') }}</dt><dd>{{ $offer->candidateName() }}</dd>
                <dt>{{ __('Position') }}</dt><dd>{{ $offer->positionLabel() }}</dd>
                @if ($offer->department)
                    <dt>{{ __('Department') }}</dt><dd>{{ $offer->department->name }}</dd>
                @endif
                @if ($offer->reportingManager)
                    <dt>{{ __('Reporting to') }}</dt><dd>{{ $offer->reportingManager->name }}</dd>
                @endif
                <dt>{{ __('Joining date') }}</dt><dd>{{ OfferLetterRenderer::date($offer->joining_date) }}</dd>
                <dt>{{ __('Monthly gross salary') }}</dt><dd>{{ OfferLetterRenderer::money($offer->salary_gross, $currency) }}</dd>
                @foreach ($offer->breakdown() as $component => $amount)
                    <dt>&nbsp;&nbsp;{{ __(JobOffer::BREAKDOWN_COMPONENTS[$component]) }}</dt><dd>{{ OfferLetterRenderer::money($amount, $currency) }}</dd>
                @endforeach
                @if ($offer->probation_months)
                    <dt>{{ __('Probation') }}</dt><dd>{{ trans_choice(':count month|:count months', $offer->probation_months, ['count' => $offer->probation_months]) }}</dd>
                @endif
                @if ($offer->working_hours)
                    <dt>{{ __('Working hours') }}</dt><dd>{{ $offer->working_hours }}</dd>
                @endif
                @if ($offer->benefits)
                    <dt>{{ __('Benefits') }}</dt><dd>{!! nl2br(e($offer->benefits)) !!}</dd>
                @endif
                <dt>{{ __('Valid until') }}</dt><dd>{{ OfferLetterRenderer::date($offer->expiry_date) }}</dd>
                <dt>{{ __('Reference') }}</dt><dd>{{ $offer->reference }}</dd>
            </dl>
        @endif

        @if ($state === 'open')
            @if (! empty($letterUrl))
                <p><a href="{{ $letterUrl }}">{{ __('Download the offer letter (PDF)') }}</a></p>
            @endif

            <p class="note">{{ __('The full terms are in the attached offer letter. Please accept or decline by :date.', ['date' => OfferLetterRenderer::date($offer->expiry_date)]) }}</p>

            @if ($errors->any())
                <p class="errors">{{ $errors->first() }}</p>
            @endif

            <form method="post" action="{{ $action }}">
                @csrf
                <label for="reason">{{ __('Message for HR (optional; tell us why if you decline)') }}</label>
                <textarea id="reason" name="reason" maxlength="2000">{{ old('reason') }}</textarea>
                <div class="actions">
                    <button type="submit" name="decision" value="accept" class="accept">{{ __('Accept offer') }}</button>
                    <button type="submit" name="decision" value="decline" class="decline">{{ __('Decline offer') }}</button>
                </div>
            </form>
        @endif
    </div>
</main>
</body>
</html>
