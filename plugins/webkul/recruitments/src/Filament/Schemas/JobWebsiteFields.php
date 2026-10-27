<?php

namespace Webkul\Recruitment\Filament\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Webkul\Recruitment\Enums\JobLocationType;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Services\JobPublicProfile;

/**
 * Job form and view sections for what the careers website, Google for Jobs and
 * job boards show (Recruitment automation plan, phase A2), and the criteria
 * the CV analyser scores against (phase A5).
 */
class JobWebsiteFields
{
    public static function formSection(): Section
    {
        return Section::make('Website & job boards')
            ->description('What candidates see on the careers website and job boards, and what the CV analyser looks for.')
            ->icon('heroicon-o-globe-alt')
            ->collapsible()
            ->schema([
                TextInput::make('public_title')
                    ->label('Public job title')
                    ->helperText('Leave empty to use the job position name.')
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label('Web address (slug)')
                    ->helperText('Filled in automatically. Changing it breaks links that were already shared.')
                    ->maxLength(180)
                    ->rule('nullable')
                    ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'),
                Select::make('location_type')
                    ->label('Work location')
                    ->options(JobLocationType::options())
                    ->native(false)
                    ->live(),
                DatePicker::make('valid_through')
                    ->label('Apply before')
                    ->helperText('Google for Jobs requires it. Falls back to the recruitment end date.')
                    ->native(false)
                    ->afterOrEqual('today'),
                TextInput::make('salary_min')
                    ->label('Salary from')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('salary_max')
                    ->label('Salary to')
                    ->numeric()
                    ->minValue(0)
                    ->gte('salary_min'),
                Select::make('salary_currency_id')
                    ->label('Salary currency')
                    ->relationship('salaryCurrency', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('active', true))
                    ->helperText('Defaults to the company currency.')
                    ->searchable()
                    ->preload(),
                Select::make('salary_period')
                    ->label('Salary per')
                    ->options(['HOUR' => 'Hour', 'DAY' => 'Day', 'WEEK' => 'Week', 'MONTH' => 'Month', 'YEAR' => 'Year'])
                    ->default('MONTH')
                    ->native(false)
                    ->selectablePlaceholder(false),
                Toggle::make('salary_visible')
                    ->label('Show the salary publicly')
                    ->helperText('Off: the salary stays inside Aureus and is never sent to the website or Google.')
                    ->columnSpanFull(),
                TextInput::make('min_experience_years')
                    ->label('Minimum years of experience')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(50)
                    ->integer(),
                TagsInput::make('screening_keywords')
                    ->label('CV keywords')
                    ->helperText('Words the CV analyser looks for (e.g. "dispatch", "fleet", "Excel"). The job\'s skills are checked too.')
                    ->splitKeys(['Enter', ','])
                    ->reorderable(),
            ])
            ->columns(2);
    }

    public static function infolistSection(): Section
    {
        return Section::make('Website & job boards')
            ->icon('heroicon-o-globe-alt')
            ->collapsible()
            ->schema([
                TextEntry::make('public_title_display')
                    ->label('Public job title')
                    ->state(fn (JobPosition $record): string => app(JobPublicProfile::class)->title($record)),
                TextEntry::make('slug')->label('Web address (slug)')->placeholder('—'),
                TextEntry::make('location_type')->label('Work location')->badge()->placeholder('—'),
                TextEntry::make('valid_through_display')
                    ->label('Apply before')
                    ->state(fn (JobPosition $record): ?string => app(JobPublicProfile::class)->validThrough($record)?->toDateString())
                    ->placeholder('—'),
                TextEntry::make('salary_display')
                    ->label('Salary')
                    ->state(fn (JobPosition $record): ?string => self::salaryText($record))
                    ->placeholder('—'),
                TextEntry::make('min_experience_years')->label('Minimum experience (years)')->placeholder('—'),
                TextEntry::make('screening_keywords')->label('CV keywords')->badge()->placeholder('—')->columnSpanFull(),
                TextEntry::make('website_readiness')
                    ->label('Ready for the website?')
                    ->state(fn (JobPosition $record): HtmlString => self::readiness($record))
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function salaryText(JobPosition $record): ?string
    {
        if ($record->salary_min === null && $record->salary_max === null) {
            return null;
        }

        $currency = $record->salaryCurrency?->code ?? $record->company?->currency?->code ?? '';
        $range = collect([$record->salary_min, $record->salary_max])
            ->filter(fn ($value): bool => $value !== null)
            ->map(fn ($value): string => number_format((float) $value))
            ->implode(' – ');

        return trim("{$currency} {$range} / ".strtolower((string) ($record->salary_period ?: 'MONTH')))
            .($record->salary_visible ? '' : ' (hidden from the public)');
    }

    private static function readiness(JobPosition $record): HtmlString
    {
        $missing = app(JobPublicProfile::class)->missingForWebsite($record);

        if ($missing === []) {
            return new HtmlString('<span style="color: rgb(22 163 74)">✔ Everything the website and Google need is filled in.</span>');
        }

        return new HtmlString('Still needed:<ul style="list-style: disc; padding-left: 1.25rem">'
            .collect($missing)->map(fn (string $item): string => '<li>'.e($item).'</li>')->implode('')
            .'</ul>');
    }
}
