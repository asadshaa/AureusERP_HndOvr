@php
    $review = $sheet['review'];
    $cycle = $sheet['cycle'];
    $employee = $sheet['employee'];
    $company = $sheet['company'];
    $num = fn ($value): string => $value === null ? '—' : number_format((float) $value, 2);
    $weight = fn ($value): string => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Appraisal — {{ $employee?->name }}</title>
    <style>
        @page { margin: 40px 44px 56px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; line-height: 1.45; }
        .letterhead { border-bottom: 2px solid #1f2937; padding-bottom: 10px; margin-bottom: 16px; width: 100%; }
        .letterhead td { vertical-align: middle; }
        .logo { max-height: 48px; max-width: 150px; }
        .company { font-size: 16px; font-weight: bold; }
        .muted { color: #6b7280; }
        h1 { font-size: 14px; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 10px; }
        h2 { font-size: 12px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.meta td { padding: 2px 8px 2px 0; vertical-align: top; }
        table.meta td.label { color: #6b7280; width: 18%; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { text-align: left; background: #f3f4f6; padding: 5px 6px; border: 1px solid #e5e7eb; font-size: 10px; }
        table.grid td { padding: 5px 6px; border: 1px solid #e5e7eb; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .score { font-size: 18px; font-weight: bold; }
        .band { display: inline-block; padding: 2px 8px; border-radius: 8px; background: #eef2ff; font-weight: bold; }
        .note { padding: 6px 8px; background: #f9fafb; border: 1px solid #e5e7eb; margin-top: 6px; white-space: pre-line; }
        .signatures { width: 100%; margin-top: 36px; }
        .signatures td { width: 33%; padding-right: 18px; vertical-align: bottom; }
        .line { border-top: 1px solid #6b7280; padding-top: 3px; }
        .footer { position: fixed; bottom: -36px; left: 0; right: 0; font-size: 8.5px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="footer">
        {{ $company?->name }} · Appraisal of {{ $employee?->name }} · {{ $cycle?->name }} · printed {{ $printedOn->format('j M Y H:i') }} by {{ $printedBy }} · Confidential
    </div>

    <table class="letterhead">
        <tr>
            @if ($logo)
                <td width="160"><img class="logo" src="{{ $logo }}" alt=""></td>
            @endif
            <td>
                <div class="company">{{ $company?->name }}</div>
                <div class="muted">Performance appraisal</div>
            </td>
        </tr>
    </table>

    <h1>{{ $cycle?->name }}</h1>

    <table class="meta">
        <tr>
            <td class="label">Employee</td>
            <td><strong>{{ $employee?->name }}</strong>@if ($employee?->employee_number) ({{ $employee->employee_number }})@endif</td>
            <td class="label">Period</td>
            <td>{{ $cycle?->periodLabel() }}</td>
        </tr>
        <tr>
            <td class="label">Job</td>
            <td>{{ $employee?->job_title ?: $employee?->job?->name ?: '—' }}</td>
            <td class="label">Department</td>
            <td>{{ $employee?->department?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Reviewer</td>
            <td>{{ $sheet['reviewer_label'] }}@if ($sheet['manager_reviewed_by'] && $sheet['manager_reviewed_by'] !== $sheet['reviewer_label']) (reviewed by {{ $sheet['manager_reviewed_by'] }})@endif</td>
            <td class="label">Status</td>
            <td>{{ $sheet['status_label'] }}</td>
        </tr>
    </table>

    <table class="meta" style="margin-top: 6px;">
        <tr>
            @if ($sheet['self_score'] !== null)
                <td style="width: 25%;"><div class="muted">Self score</div><div class="score">{{ $num($sheet['self_score']) }}</div></td>
            @endif
            @if ($sheet['manager_score'] !== null)
                <td style="width: 25%;"><div class="muted">Manager score</div><div class="score">{{ $num($sheet['manager_score']) }}</div></td>
            @endif
            @if ($sheet['final_score'] !== null)
                <td style="width: 25%;"><div class="muted">Final score</div><div class="score">{{ $num($sheet['final_score']) }}</div></td>
            @endif
            @if ($sheet['band'])
                <td><div class="muted">Rating</div><span class="band">{{ $sheet['band']['label'] }}</span></td>
            @endif
        </tr>
    </table>

    @if ($sheet['calibration'])
        <div class="note"><strong>Calibrated by HR</strong> from {{ $num($sheet['calibration']['original']) }} to {{ $num($sheet['calibration']['rating']) }} ({{ $sheet['calibration']['by'] }}, {{ $sheet['calibration']['at'] }}): {{ $sheet['calibration']['reason'] }}</div>
    @endif

    @foreach ($sheet['sections'] as $section)
        <h2>{{ $section['title'] }} <span class="muted" style="font-weight: normal;">(weight {{ $weight($section['weight']) }})</span></h2>
        <table class="grid">
            <thead>
                <tr>
                    <th style="width: 30%;">Item</th>
                    <th class="num" style="width: 7%;">Weight</th>
                    @if ($sheet['show_self'])
                        <th>Self</th>
                    @endif
                    @if ($sheet['manager_started'])
                        <th>Manager</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($section['items'] as $item)
                    <tr>
                        <td><strong>{{ $item['title'] }}</strong>@if ($item['description'])<br><span class="muted">{{ $item['description'] }}</span>@endif</td>
                        <td class="num">{{ $weight($item['weight']) }}</td>
                        @foreach (array_filter(['self' => $sheet['show_self'], 'manager' => $sheet['manager_started']]) as $side => $shown)
                            <td>
                                @if ($item[$side])
                                    <strong>{{ $item[$side]['rating'] ?? '—' }}</strong>@if ($item[$side]['label']) · {{ $item[$side]['label'] }}@endif
                                    @if ($item[$side]['comment'])<br>{{ $item[$side]['comment'] }}@endif
                                @else
                                    —
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    @foreach (['self_comments' => 'Employee comments', 'manager_comments' => 'Manager comments', 'improvement_plan' => 'Development / improvement plan', 'promotion_recommendation' => 'Promotion / increment recommendation (HR only)'] as $key => $title)
        @if ($sheet[$key])
            <h2>{{ $title }}</h2>
            <div class="note">{{ $sheet[$key] }}</div>
        @endif
    @endforeach

    @if ($sheet['goals'] !== [])
        <h2>Goals for the next period</h2>
        <table class="grid">
            <thead>
                <tr><th>Goal</th><th style="width: 18%;">Due</th><th class="num" style="width: 10%;">Weight</th></tr>
            </thead>
            <tbody>
                @foreach ($sheet['goals'] as $goal)
                    <tr>
                        <td><strong>{{ $goal['title'] }}</strong>@if ($goal['description'])<br><span class="muted">{{ $goal['description'] }}</span>@endif</td>
                        <td>{{ $goal['due_date'] ?? '—' }}</td>
                        <td class="num">{{ $goal['weight'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Rating bands</h2>
    <p class="muted">
        Final score = weighted average of the item ratings (1-5).
        @foreach ($sheet['bands'] as $band)
            {{ $band['label'] }} {{ $loop->last ? 'below '.$num($sheet['bands'][$loop->index - 1]['min'] ?? 5) : 'from '.$num($band['min']) }}{{ $loop->last ? '.' : ';' }}
        @endforeach
    </p>

    @if ($sheet['acknowledged_at'])
        <h2>Acknowledgement</h2>
        <div class="note">Acknowledged by {{ $employee?->name }} on {{ $sheet['acknowledged_at'] }}.@if ($sheet['acknowledgement_comment']) Comment: {{ $sheet['acknowledgement_comment'] }}@endif</div>
    @endif

    <table class="signatures">
        <tr>
            <td><div class="line">Employee</div></td>
            <td><div class="line">Line manager</div></td>
            <td><div class="line">HR</div></td>
        </tr>
    </table>
</body>
</html>
