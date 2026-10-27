<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages;

use Filament\Actions\Action;
use Illuminate\Support\HtmlString;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages\ViewJobPosition as BaseViewJobPosition;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource;
use Webkul\Recruitment\Filament\Schemas\CvAnalysisComponents;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Services\JobPublicProfile;
use Webkul\Recruitment\Traits\HasJobPublicationActions;

class ViewJobPosition extends BaseViewJobPosition
{
    use HasJobPublicationActions;

    protected static string $resource = JobPositionResource::class;

    protected function getHeaderActions(): array
    {
        return JobPositionResource::actionsForRecordCompany([
            $this->websitePreviewAction(),
            CvAnalysisComponents::rescoreJobAction(),
            ...$this->getJobPublicationActions(),
            ...parent::getHeaderActions(),
        ], $this->getRecord(), ['websitePreview']);
    }

    /** Read-only: what the careers website and Google for Jobs will receive for this job. */
    protected function websitePreviewAction(): Action
    {
        return Action::make('websitePreview')
            ->label('Website preview')
            ->icon('heroicon-o-globe-alt')
            ->color('gray')
            ->modalHeading('What the website and Google will receive')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalWidth('4xl')
            ->modalContent(function (JobPosition $record): HtmlString {
                $profile = app(JobPublicProfile::class);
                $missing = $profile->missingForWebsite($record);
                $json = fn (array $data): string => e(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                $readiness = $missing === []
                    ? '<p style="color: rgb(22 163 74)">✔ Ready for the website and Google for Jobs.</p>'
                    : '<p><strong>Still needed:</strong></p><ul style="list-style: disc; padding-left: 1.25rem">'
                        .collect($missing)->map(fn (string $item): string => '<li>'.e($item).'</li>')->implode('').'</ul>';

                return new HtmlString($readiness
                    .'<p style="margin-top: 1rem"><strong>Website payload</strong> (sent to the careers website)</p>'
                    .'<pre style="font-size: 12px; overflow: auto; max-height: 20rem; padding: .75rem; border-radius: .5rem; background: rgba(127,127,127,.1)">'.$json($profile->payload($record)).'</pre>'
                    .'<p style="margin-top: 1rem"><strong>Google for Jobs</strong> (JobPosting structured data on the job page)</p>'
                    .'<pre style="font-size: 12px; overflow: auto; max-height: 20rem; padding: .75rem; border-radius: .5rem; background: rgba(127,127,127,.1)">'.$json($profile->structuredData($record)).'</pre>');
            });
    }
}
