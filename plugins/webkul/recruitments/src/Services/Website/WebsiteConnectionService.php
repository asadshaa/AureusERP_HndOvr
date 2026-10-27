<?php

namespace Webkul\Recruitment\Services\Website;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Security\Models\User;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Saves and tests a company's careers website connection (automation plan
 * §6 A3). Every call authorises: hr_manage_recruitment_integrations within
 * the connection's own company.
 */
class WebsiteConnectionService
{
    public function __construct(protected ApprovalEngine $approvals) {}

    public static function canManage(?User $user, mixed $companyId): bool
    {
        return $user !== null
            && $user->is_active
            && $companyId
            && $user->can(HrPermissions::ManageRecruitmentIntegrations)
            && app(ApprovalEngine::class)->userCanAccessCompany($user, (int) $companyId);
    }

    /**
     * Create or update the company's connection. An empty password keeps the
     * stored one; the password is never returned.
     *
     * @param  array{base_url?: ?string, username?: ?string, app_password?: ?string, is_active?: bool}  $data
     */
    public function save(User $actor, int $companyId, array $data): WebsiteConnection
    {
        $this->authorize($actor, $companyId);

        return DB::transaction(function () use ($actor, $companyId, $data): WebsiteConnection {
            $connection = WebsiteConnection::query()->forCompany($companyId)->lockForUpdate()->first() ?? new WebsiteConnection(['company_id' => $companyId]);

            $input = [
                'base_url'     => WebsiteConnection::normalizeBaseUrl($data['base_url'] ?? null),
                'username'     => trim((string) ($data['username'] ?? '')),
                'app_password' => filled($data['app_password'] ?? null) ? trim((string) $data['app_password']) : null,
                'is_active'    => (bool) ($data['is_active'] ?? false),
            ];

            Validator::make($input, WebsiteConnection::rules(passwordRequired: ! $connection->exists))->validate();

            $changedSite = $connection->exists && ($connection->base_url !== $input['base_url'] || $connection->username !== $input['username']);

            $connection->fill([
                'base_url'  => $input['base_url'],
                'username'  => $input['username'],
                'is_active' => $input['is_active'],
            ]);

            if ($input['app_password'] !== null) {
                $connection->app_password = $input['app_password'];
            }

            if (! $connection->exists) {
                $connection->creator_id = $actor->id;
            }

            if ($changedSite || $input['app_password'] !== null) {
                // A new address or credentials: the last test no longer says anything about them.
                $connection->forceFill(['last_checked_at' => null, 'last_error' => null]);
            }

            $connection->save();

            return $connection;
        });
    }

    /**
     * Calls the website's ping and records the result on the connection.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(User $actor, WebsiteConnection $connection): array
    {
        $this->authorize($actor, (int) $connection->company_id);

        try {
            $info = WordPressCareersClient::for($connection)->ping();
            $connection->forceFill(['last_checked_at' => now(), 'last_error' => null])->save();

            $version = is_scalar($info['version'] ?? null) ? ' (plugin '.$info['version'].')' : '';

            return ['ok' => true, 'message' => "Connected to {$connection->host()}{$version}."];
        } catch (Throwable $e) {
            if (! $e instanceof WebsiteRequestException) {
                report($e);
            }

            $message = Str::limit($e instanceof WebsiteRequestException ? $e->getMessage() : 'Unexpected error: '.class_basename($e), 1000, '');
            $connection->forceFill(['last_checked_at' => now(), 'last_error' => $message])->save();

            return ['ok' => false, 'message' => $message];
        }
    }

    protected function authorize(User $actor, int $companyId): void
    {
        if (! static::canManage($actor, $companyId)) {
            throw new RuntimeException('You are not allowed to manage the careers website of this company.');
        }
    }
}
