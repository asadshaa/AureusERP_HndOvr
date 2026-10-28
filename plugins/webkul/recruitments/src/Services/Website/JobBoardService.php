<?php

namespace Webkul\Recruitment\Services\Website;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\JobBoardRegistration;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;
use Webkul\Recruitment\Support\JobPostingChannel;
use Webkul\Security\Models\User;

/**
 * Free job boards of one company (Recruitment → Configurations → Job boards):
 * each board's feed URL on the company website, whether HR has registered it
 * with the board, and what it brings in. Needs hr_manage_recruitment_integrations
 * within the company, like the Careers website page.
 */
class JobBoardService
{
    public function __construct(protected JobPostingChannelRegistry $channels) {}

    public static function canManage(?User $user, mixed $companyId): bool
    {
        return WebsiteConnectionService::canManage($user, $companyId);
    }

    /**
     * One row per free job board, for the company only.
     *
     * @return array<int, array{key: string, label: string, automatic: bool, icon: ?string, description: ?string, board_url: ?string, how_to: array<int, string>, feed_url: ?string, website_active: bool, is_registered: bool, registered_at: ?string, notes: ?string, jobs_live: int, applications_this_month: int}>
     */
    public function overview(int $companyId, ?Carbon $now = null): array
    {
        $boards = $this->channels->freeJobBoards();
        $keys = array_keys($boards);
        $connection = WebsiteConnection::query()->forCompany($companyId)->first();
        $registrations = JobBoardRegistration::query()->forCompany($companyId)->whereIn('channel', $keys)->get()->keyBy('channel');

        $live = JobPosting::query()
            ->where('company_id', $companyId)
            ->whereIn('channel', $keys)
            ->whereNull('dropped_at')
            ->where('status', ChannelPostingStatus::LIVE->value)
            ->groupBy('channel')
            ->selectRaw('channel, count(*) as total')
            ->pluck('total', 'channel');

        $since = ($now ?? now())->copy()->startOfMonth();
        $applications = Applicant::query()
            ->join('recruitments_job_postings as board_posting', 'board_posting.id', '=', 'recruitments_applicants.job_posting_id')
            ->where('recruitments_applicants.company_id', $companyId)
            ->where('board_posting.company_id', $companyId)
            ->whereIn('board_posting.channel', $keys)
            ->where('recruitments_applicants.created_at', '>=', $since)
            ->groupBy('board_posting.channel')
            ->selectRaw('board_posting.channel as board_channel, count(*) as total')
            ->pluck('total', 'board_channel');

        return array_values(array_map(function (JobPostingChannel $channel) use ($connection, $registrations, $live, $applications): array {
            $registration = $registrations->get($channel->key);

            return [
                'key'                     => $channel->key,
                'label'                   => $channel->label,
                'automatic'               => $channel->isFeedBoard(),
                'icon'                    => $channel->icon,
                'description'             => $channel->description,
                'board_url'               => $channel->boardUrl,
                'how_to'                  => $channel->howTo,
                'feed_url'                => $channel->isFeedBoard() ? WebsiteDerivedChannelPublisher::feedUrl($connection, (string) $channel->feedBoard) : null,
                'website_active'          => (bool) $connection?->is_active,
                'is_registered'           => (bool) $registration?->is_registered,
                'registered_at'           => $registration?->registered_at?->toDateString(),
                'notes'                   => $registration?->notes,
                'jobs_live'               => (int) ($live[$channel->key] ?? 0),
                'applications_this_month' => (int) ($applications[$channel->key] ?? 0),
            ];
        }, $boards));
    }

    /**
     * Records whether the company has registered with a board.
     *
     * @param  array{is_registered?: bool, registered_at?: ?string, notes?: ?string}  $data
     */
    public function saveRegistration(User $actor, int $companyId, string $channel, array $data): JobBoardRegistration
    {
        if (! static::canManage($actor, $companyId)) {
            throw new RuntimeException('You are not allowed to manage the job boards of this company.');
        }

        if (! array_key_exists($channel, $this->channels->freeJobBoards())) {
            throw new RuntimeException("Unknown job board [{$channel}].");
        }

        $input = [
            'is_registered' => (bool) ($data['is_registered'] ?? false),
            'registered_at' => filled($data['registered_at'] ?? null) ? (string) $data['registered_at'] : null,
            'notes'         => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];

        Validator::make($input, [
            'is_registered' => ['boolean'],
            'registered_at' => ['nullable', 'date', 'before_or_equal:today'],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ])->validate();

        if ($input['is_registered']) {
            $input['registered_at'] ??= now()->toDateString();
        } else {
            $input['registered_at'] = null;
        }

        return DB::transaction(function () use ($actor, $companyId, $channel, $input): JobBoardRegistration {
            $registration = JobBoardRegistration::query()->forCompany($companyId)->where('channel', $channel)->lockForUpdate()->first()
                ?? new JobBoardRegistration(['company_id' => $companyId, 'channel' => $channel]);

            $registration->fill($input + ['updated_by' => $actor->id])->save();

            return $registration;
        });
    }
}
