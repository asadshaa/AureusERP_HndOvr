@php
    $sheet = $this->sheet();
    $review = $sheet['review'];
    $cycle = $sheet['cycle'];
    $editsSelf = $this->editsSelf();
    $editsManager = $this->editsManager();
    $num = fn ($value): string => $value === null ? '—' : number_format((float) $value, 2);
    $weight = fn ($value): string => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $grid = 'display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));';
    $label = 'font-size: .75rem; opacity: .7; margin-bottom: .15rem;';
    $big = 'font-size: 1.5rem; font-weight: 700; font-variant-numeric: tabular-nums;';
    $table = 'width: 100%; border-collapse: collapse; font-size: .875rem;';
    $th = 'text-align: left; padding: .5rem .75rem; font-weight: 600; border-bottom: 1px solid rgba(127,127,127,.25);';
    $td = 'padding: .5rem .75rem; border-bottom: 1px solid rgba(127,127,127,.12); vertical-align: top;';
    $muted = 'font-size: .8rem; opacity: .75;';
    $due = \Webkul\Employee\Filament\Appraisals\AppraisalTable::dueText($review);
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div style="{{ $grid }}">
            <div>
                <div style="{{ $label }}">Employee</div>
                <div style="font-weight: 600;">{{ $sheet['employee']?->name }}</div>
                <div style="{{ $muted }}">{{ collect([$sheet['employee']?->job_title ?: $sheet['employee']?->job?->name, $sheet['employee']?->department?->name])->filter()->implode(' · ') }}</div>
            </div>
            <div>
                <div style="{{ $label }}">Reviewer</div>
                <div>{{ $sheet['reviewer_label'] }}</div>
                @if ($sheet['manager_reviewed_by'] && $sheet['manager_reviewed_by'] !== $sheet['reviewer_label'])
                    <div style="{{ $muted }}">Reviewed by {{ $sheet['manager_reviewed_by'] }}</div>
                @endif
            </div>
            <div>
                <div style="{{ $label }}">Status</div>
                <x-filament::badge :color="$sheet['status_color']" style="display: inline-flex;">{{ $sheet['status_label'] }}</x-filament::badge>
                @if ($due)
                    <div style="{{ $muted }} margin-top: .25rem;">{{ $due }}</div>
                @endif
            </div>
            @if ($sheet['self_score'] !== null)
                <div>
                    <div style="{{ $label }}">Self score</div>
                    <div style="{{ $big }}">{{ $num($sheet['self_score']) }}</div>
                </div>
            @endif
            @if ($sheet['final_score'] !== null)
                <div>
                    <div style="{{ $label }}">Final score</div>
                    <div style="{{ $big }}">{{ $num($sheet['final_score']) }}</div>
                    @if ($sheet['band'])
                        <x-filament::badge :color="$sheet['band']['color']" style="display: inline-flex;">{{ $sheet['band']['label'] }}</x-filament::badge>
                    @endif
                </div>
            @endif
        </div>

        @if ($sheet['results_pending'])
            <p style="margin-top: 1rem; {{ $muted }}">Your manager has completed the review. You will see it here when HR closes the cycle.</p>
        @endif

        @if ($sheet['calibration'])
            <div style="margin-top: 1rem; padding: .75rem; border-radius: .5rem; background: rgba(217, 119, 6, .08);">
                <strong>Calibrated by HR</strong> from {{ $num($sheet['calibration']['original']) }} to {{ $num($sheet['calibration']['rating']) }}
                <span style="{{ $muted }}">— {{ $sheet['calibration']['by'] }}, {{ $sheet['calibration']['at'] }}</span>
                <div style="margin-top: .25rem;">{{ $sheet['calibration']['reason'] }}</div>
            </div>
        @elseif ($sheet['own'] && $sheet['calibrated'])
            <p style="margin-top: 1rem; {{ $muted }}">The final rating was confirmed by HR at calibration.</p>
        @endif

        @if ($sheet['acknowledged_at'])
            <div style="margin-top: 1rem; padding: .75rem; border-radius: .5rem; background: rgba(22, 163, 74, .08);">
                <strong>Acknowledged</strong> by the employee on {{ $sheet['acknowledged_at'] }}
                @if ($sheet['acknowledgement_comment'])
                    <div style="margin-top: .25rem; white-space: pre-line;">“{{ $sheet['acknowledgement_comment'] }}”</div>
                @endif
            </div>
        @endif
    </x-filament::section>

    @if ($editsSelf)
        <x-filament::section heading="Your self review" :description="'Rate each item from 1 to '.count($sheet['scale']).' and add comments where useful. Save a draft any time; once you submit, your line manager reviews it.'">
            <form wire:submit.prevent="saveSelf(false)">
                {{ $this->selfForm }}

                <div style="display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1.5rem;">
                    <x-filament::button color="gray" icon="heroicon-m-bookmark" wire:click="saveSelf(false)">Save draft</x-filament::button>
                    <x-filament::button icon="heroicon-m-paper-airplane" wire:click="saveSelf(true)" wire:confirm="Submit your self review? You cannot change it afterwards.">Submit self review</x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @elseif ($editsManager)
        <x-filament::section heading="Manager review" :description="$sheet['self_skipped'] ? 'There is no self review for this appraisal. Rate each item from 1 to 5.' : 'The employee\'s self rating is shown beside each item (their comment as the placeholder). Rate each item from 1 to 5.'">
            @if ($sheet['self_comments'])
                <div style="margin-bottom: 1rem; padding: .75rem; border-radius: .5rem; background: rgba(59, 130, 246, .08);">
                    <div style="{{ $label }}">Employee's overall comments (self score {{ $num($sheet['self_score']) }})</div>
                    <div style="white-space: pre-line;">{{ $sheet['self_comments'] }}</div>
                </div>
            @endif

            <form wire:submit.prevent="saveManager(false)">
                {{ $this->managerForm }}

                <div style="display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1.5rem;">
                    <x-filament::button color="gray" icon="heroicon-m-bookmark" wire:click="saveManager(false)">Save draft</x-filament::button>
                    <x-filament::button icon="heroicon-m-paper-airplane" wire:click="saveManager(true)" wire:confirm="Submit the manager review? The score is calculated and the employee is told.">Submit manager review</x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @endif

    @if (! $editsSelf && ! $editsManager)
        @if (! $sheet['show_self'] && ! $sheet['manager_started'])
            <x-filament::section>
                <p style="{{ $muted }}">The self review has not been submitted yet.</p>
            </x-filament::section>
        @else
            @foreach ($sheet['sections'] as $section)
                <x-filament::section :heading="$section['title']" :description="$section['description']">
                    <div style="overflow-x: auto;">
                        <table style="{{ $table }}">
                            <thead>
                                <tr>
                                    <th style="{{ $th }}">Item</th>
                                    <th style="{{ $th }} text-align: right;">Weight</th>
                                    @if ($sheet['show_self'])
                                        <th style="{{ $th }}">Self</th>
                                    @endif
                                    @if ($sheet['manager_started'])
                                        <th style="{{ $th }}">Manager</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($section['items'] as $item)
                                    <tr>
                                        <td style="{{ $td }} width: 30%;">
                                            <div style="font-weight: 600;">{{ $item['title'] }}</div>
                                            @if ($item['description'])
                                                <div style="{{ $muted }}">{{ $item['description'] }}</div>
                                            @endif
                                        </td>
                                        <td style="{{ $td }} text-align: right;">{{ $weight($item['weight']) }}</td>
                                        @foreach (array_filter(['self' => $sheet['show_self'], 'manager' => $sheet['manager_started']]) as $side => $shown)
                                            <td style="{{ $td }}">
                                                @if ($item[$side])
                                                    <div style="font-weight: 600;">{{ $item[$side]['rating'] ?? '—' }}@if ($item[$side]['label']) <span style="font-weight: 400; {{ $muted }}">· {{ $item[$side]['label'] }}</span>@endif</div>
                                                    @if ($item[$side]['comment'])
                                                        <div style="white-space: pre-line;">{{ $item[$side]['comment'] }}</div>
                                                    @endif
                                                @else
                                                    <span style="{{ $muted }}">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endforeach

            @if ($sheet['self_comments'] || $sheet['manager_comments'] || $sheet['improvement_plan'] || $sheet['promotion_recommendation'] || $sheet['self_skipped'])
                <x-filament::section heading="Comments">
                    <div style="display: grid; gap: 1rem;">
                        @if ($sheet['self_skipped'])
                            <p style="{{ $muted }}">No self review (moved on by HR or no login).</p>
                        @endif
                        @foreach (['self_comments' => 'Employee', 'manager_comments' => 'Manager', 'improvement_plan' => 'Development / improvement plan', 'promotion_recommendation' => 'Promotion / increment recommendation (HR only)'] as $key => $title)
                            @if ($sheet[$key])
                                <div>
                                    <div style="{{ $label }}">{{ $title }}</div>
                                    <div style="white-space: pre-line;">{{ $sheet[$key] }}</div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            @if ($sheet['goals'] !== [])
                <x-filament::section heading="Goals for the next period">
                    <table style="{{ $table }}">
                        <thead>
                            <tr>
                                <th style="{{ $th }}">Goal</th>
                                <th style="{{ $th }}">Due</th>
                                <th style="{{ $th }} text-align: right;">Weight</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sheet['goals'] as $goal)
                                <tr>
                                    <td style="{{ $td }}">
                                        <div style="font-weight: 600;">{{ $goal['title'] }}</div>
                                        @if ($goal['description'])
                                            <div style="{{ $muted }}">{{ $goal['description'] }}</div>
                                        @endif
                                    </td>
                                    <td style="{{ $td }}">{{ $goal['due_date'] ?? '—' }}</td>
                                    <td style="{{ $td }} text-align: right;">{{ $goal['weight'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @endif
        @endif
    @endif
</x-filament-panels::page>
