@php
    $details = $payload['interview'];
    $kind = $payload['kind'];
    $audience = $payload['audience'];
    $key = "recruitments::mails/interview.{$audience}.{$kind}";
    $calendarNote = match ($kind) {
        'invitation'           => 'calendar_add',
        'rescheduled'          => 'calendar_update',
        'cancelled', 'removed' => 'calendar_cancel',
        default                => null,
    };
@endphp

<x-support::emails.layout>
    <div class="breadcrumb">
        {{ $details['title'] }}
    </div>

    <div class="notification">
        <p>{{ __('recruitments::mails/interview.greeting', ['name' => $payload['to']['name']]) }}</p>
        <p>{{ __($key.'.intro', $payload['replace']) }}</p>

        @if ($payload['show_details'])
            <table class="details">
                <tr>
                    <th>{{ __('recruitments::mails/interview.details.when') }}</th>
                    <td>{{ $details['when'] }}</td>
                </tr>
                <tr>
                    <th>{{ __('recruitments::mails/interview.details.duration') }}</th>
                    <td>{{ __('recruitments::mails/interview.details.minutes', ['count' => $details['duration']]) }}</td>
                </tr>
                <tr>
                    <th>{{ __('recruitments::mails/interview.details.mode') }}</th>
                    <td>{{ $details['mode'] }}</td>
                </tr>
                @if (filled($details['location']))
                    <tr>
                        <th>{{ $details['location_label'] }}</th>
                        <td>
                            @if ($details['location_is_link'])
                                <a href="{{ $details['location'] }}">{{ $details['location'] }}</a>
                            @else
                                {{ $details['location'] }}
                            @endif
                        </td>
                    </tr>
                @elseif ($audience === 'candidate' && $details['mode_value'] === 'phone')
                    <tr>
                        <th>{{ $details['location_label'] }}</th>
                        <td>{{ __('recruitments::mails/interview.phone_call_you') }}</td>
                    </tr>
                @endif
                @if (filled($details['interviewers']))
                    <tr>
                        <th>{{ __('recruitments::mails/interview.details.interviewers') }}</th>
                        <td>{{ $details['interviewers'] }}</td>
                    </tr>
                @endif
                @if (filled($details['organizer']))
                    <tr>
                        <th>{{ __('recruitments::mails/interview.details.organizer') }}</th>
                        <td>{{ $details['organizer'] }}</td>
                    </tr>
                @endif
            </table>
        @endif

        @if (filled($payload['cancel_reason'] ?? null))
            <p><strong>{{ __('recruitments::mails/interview.details.reason') }}:</strong> {{ $payload['cancel_reason'] }}</p>
        @endif

        @if ($calendarNote)
            <p>{{ __('recruitments::mails/interview.'.$calendarNote) }}</p>
        @endif

        @if ($audience === 'candidate' && in_array($kind, ['invitation', 'rescheduled', 'reminder'], true))
            <p>{{ __('recruitments::mails/interview.candidate_reply') }}</p>
        @endif

        @if ($audience === 'interviewer')
            <hr class="separator">
            <p class="internal-note">{{ __('recruitments::mails/interview.internal_note') }}</p>

            @if (filled($payload['action_url'] ?? null))
                <div class="view-button-container">
                    <a href="{{ $payload['action_url'] }}" class="view-button">{{ $payload['action_label'] }}</a>
                </div>
            @endif
        @endif
    </div>

    @isset($payload['from']['company'])
        <div class="company-info">
            <div class="company-name">{{ $payload['from']['company']['name'] }}</div>
            <p class="company-details">
                {{ $payload['from']['company']['phone'] }} | {{ $payload['from']['company']['email'] }}
                @if (filled($payload['from']['company']['website']))
                    |
                    <a href="{{ $payload['from']['company']['website'] }}">
                        {{ str_replace(['https://', 'http://'], '', $payload['from']['company']['website']) }}
                    </a>
                @endif
            </p>
        </div>
    @endisset
</x-support::emails.layout>

<style>
    .breadcrumb {
        font-size: 14px;
        font-weight: bold;
        margin-bottom: 20px;
        border-bottom: 1px solid rgb(204, 204, 204);
        padding-bottom: 10px;
    }

    .notification {
        margin: 15px 0;
        color: #555;
        font-size: 13px;
    }

    .details {
        border-collapse: collapse;
        margin: 12px 0;
    }

    .details th {
        text-align: left;
        padding: 4px 16px 4px 0;
        color: #333;
        vertical-align: top;
        white-space: nowrap;
    }

    .details td {
        padding: 4px 0;
    }

    .internal-note {
        background-color: #4394eb;
        padding: 5px;
        color: #ffffff;
        margin-bottom: 16px;
        font-size: 13px;
    }

    .view-button-container {
        margin-top: 10px;
    }

    .view-button {
        display: inline-block;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: bold;
        color: #ffffff;
        background-color: #007bff;
        border-radius: 3px;
        text-decoration: none;
    }

    .separator {
        background-color: rgb(204, 204, 204);
        border: none;
        display: block;
        font-size: 0px;
        height: 1px;
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
