<x-support::emails.layout>
    <div class="breadcrumb">
        {{ __('Offer of employment') }} <p>{{ $payload['reference'] }}</p>
    </div>

    <div class="notification">
        <p>{{ __('Dear :name,', ['name' => $payload['candidate_name']]) }}</p>
        <p>
            {!! __('We are pleased to offer you the position of :position at :company. Your offer letter is attached.', [
                'position' => '<strong class="highlighted-text">'.e($payload['position']).'</strong>',
                'company'  => '<strong>'.e($payload['company_name']).'</strong>',
            ]) !!}
        </p>
        <p>{{ __('Proposed joining date: :date.', ['date' => $payload['joining_date']]) }}</p>
        <p>{{ __('Please read the letter, then accept or decline the offer by :date using the button below.', ['date' => $payload['expiry_date']]) }}</p>
        <p>
            <a href="{{ $payload['respond_url'] }}" class="view-button">{{ __('View and respond to the offer') }}</a>
        </p>
        <p class="small">{{ __('If the button does not work, copy this link into your browser:') }}<br>{{ $payload['respond_url'] }}</p>
        <hr class="separator">
        <p>{{ __('If you have any questions, simply reply to this email.') }}</p>
        <p>{{ __('Kind regards,') }}<br>{{ $payload['sender_name'] }}<br>{{ __('Human Resources') }}, {{ $payload['company_name'] }}</p>
    </div>

    @isset($payload['from']['company'])
        <div class="company-info">
            <div class="company-name">{{ $payload['from']['company']['name'] }}</div>
            <p class="company-details">
                {{ collect([$payload['from']['company']['phone'] ?? null, $payload['from']['company']['email'] ?? null, $payload['from']['company']['website'] ?? null])->filter()->implode(' | ') }}
            </p>
        </div>
    @endisset
</x-support::emails.layout>

<style>
    .breadcrumb {
        font-size: 14px;
        margin-bottom: 20px;
        border-bottom: 1px solid rgb(204, 204, 204);
        padding-bottom: 10px;
        display: flex;
        align-items: center;
    }

    .breadcrumb p {
        font-weight: bold;
        margin: 0;
        margin-left: 10px;
    }

    .view-button {
        display: inline-block;
        padding: 10px 18px;
        font-size: 14px;
        font-weight: bold;
        color: #fff;
        background-color: #15803d;
        border-radius: 4px;
        text-decoration: none;
    }

    .notification {
        margin: 15px 0;
        color: #555;
        font-size: 13px;
    }

    .small {
        font-size: 11px;
        color: #777;
        word-break: break-all;
    }

    .highlighted-text {
        color: #0056b3;
    }

    .separator {
        background-color: rgb(204, 204, 204);
        border: none;
        display: block;
        font-size: 0px;
        height: 1px;
        line-height: 0;
        margin: 16px 0;
    }

    .company-info {
        font-size: 13px;
        color: #666;
        border-top: 1px solid rgb(204, 204, 204);
        padding-top: 10px;
    }

    .company-name {
        font-weight: bold;
        margin-bottom: 5px;
    }

    .company-details {
        margin: 0;
    }
</style>
