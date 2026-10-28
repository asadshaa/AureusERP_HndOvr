<x-filament-panels::page>
    {{ $this->table }}

    <x-filament::section heading="Imported recently" description="The latest applications that arrived from the careers website. Open one to see the CV, screening answers and CV score.">
        @if ($recent->isEmpty())
            <p style="opacity:.7">Nothing imported from the careers website yet.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%; border-collapse:collapse; font-size:.875rem">
                    <thead>
                        <tr>
                            @foreach (['Applicant', 'Job', 'Came from', 'Received', 'CV', 'Screening', 'CV score'] as $heading)
                                <th style="text-align:left; padding:.5rem .75rem; border-bottom:1px solid rgba(127,127,127,.25); white-space:nowrap">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $row)
                            <tr>
                                <td style="padding:.5rem .75rem; border-bottom:1px solid rgba(127,127,127,.12)">
                                    <a href="{{ $row['url'] }}" style="color:rgb(var(--primary-500, 59 130 246)); font-weight:600">{{ $row['name'] }}</a>
                                    @if ($row['email'])<div style="opacity:.7">{{ $row['email'] }}</div>@endif
                                </td>
                                <td style="padding:.5rem .75rem; border-bottom:1px solid rgba(127,127,127,.12)">{{ $row['job'] }}</td>
                                <td style="padding:.5rem .75rem; border-bottom:1px solid rgba(127,127,127,.12)">{{ $row['via'] }}</td>
                                <td style="padding:.5rem .75rem; border-bottom:1px solid rgba(127,127,127,.12); white-space:nowrap">{{ $row['received'] }}</td>
                                <td style="padding:.5rem .75rem; border-bottom:1px solid rgba(127,127,127,.12)">{{ $row['cv'] ? '✔' : '—' }}</td>
                                <td style="padding:.5rem .75rem; border-bottom:1px solid rgba(127,127,127,.12)">{{ $row['screening'] ?? 'No questions' }}</td>
                                <td style="padding:.5rem .75rem; border-bottom:1px solid rgba(127,127,127,.12)">{{ $row['score'] ?? 'Not scored yet' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>