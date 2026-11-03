<?php

namespace Webkul\Accounting\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;

/**
 * A cheap stamp of "the ledger as it is now" for a set of companies, used to
 * rotate cached report results the moment the ledger changes.
 *
 * Two signals are combined:
 *
 *   - A fingerprint of the companies' moves read from the database (count,
 *     sum and max of ids, latest updated_at). Posting, resetting to draft,
 *     cancelling, reversing (a new move) and deleting all change it — even
 *     when the write bypasses Eloquent (bank posting, manual adjustments and
 *     migration journals update the tables directly). One aggregate query on
 *     the (company_id, state, ...) index.
 *   - A per-company token rotated by every Eloquent save/delete of a Move or
 *     MoveLine (any subclass), which also covers same-second edits and
 *     journal-item changes that do not touch the parent move row.
 */
class LedgerVersion
{
    protected const TOKEN_PREFIX = 'accounting:ledger-version:';

    protected const ALL_COMPANIES = 'all';

    /**
     * @param  array<int, int>  $companyIds  empty = every company
     */
    public function stamp(array $companyIds, bool $postedOnly = true): string
    {
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        sort($companyIds);

        $fingerprint = DB::table('accounts_account_moves')
            ->when($companyIds !== [], fn ($query) => $query->whereIn('company_id', $companyIds))
            ->when($postedOnly, fn ($query) => $query->where('state', MoveState::POSTED->value))
            ->selectRaw('COUNT(*) AS move_count, COALESCE(SUM(id), 0) AS id_sum, COALESCE(MAX(id), 0) AS max_id, MAX(updated_at) AS last_update')
            ->first();

        $tokenKeys = array_map(
            fn ($companyId): string => self::TOKEN_PREFIX.$companyId,
            $companyIds === [] ? [self::ALL_COMPANIES] : $companyIds,
        );

        $tokens = Cache::many($tokenKeys);

        return md5(implode('|', [
            (int) ($fingerprint->move_count ?? 0),
            (string) ($fingerprint->id_sum ?? 0),
            (int) ($fingerprint->max_id ?? 0),
            (string) ($fingerprint->last_update ?? '-'),
            implode(',', array_map(fn ($token): string => (string) ($token ?? '0'), $tokens)),
        ]));
    }

    /**
     * Rotate the token of a company (and the all-companies token).
     */
    public static function bump(?int $companyId): void
    {
        try {
            Cache::forever(self::TOKEN_PREFIX.self::ALL_COMPANIES, (string) Str::uuid());

            if ($companyId !== null) {
                Cache::forever(self::TOKEN_PREFIX.$companyId, (string) Str::uuid());
            }
        } catch (Throwable $exception) {
            // A cache outage must never block posting; the database
            // fingerprint in stamp() still rotates on state changes.
            report($exception);
        }
    }

    /**
     * Wildcard model-event handler: bumps the version whenever a Move or a
     * MoveLine — or any of their subclasses (Invoice, Bill, JournalEntry,
     * JournalItem, ...) — is saved or deleted. Registered by the accounting
     * service provider, so the account models themselves stay untouched.
     *
     * @param  array<int, mixed>  $payload
     */
    public static function handleModelEvent(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Move && ! $model instanceof MoveLine) {
            return;
        }

        foreach (static::companiesOf($model) as $companyId) {
            static::bump($companyId);
        }
    }

    /**
     * The company the record belongs to, plus the one it belonged to before
     * this save when that changed (both ledgers changed).
     *
     * @return array<int, int|null>
     */
    protected static function companiesOf(Model $model): array
    {
        return array_values(array_unique(array_map(
            fn ($companyId): ?int => $companyId !== null ? (int) $companyId : null,
            [$model->getAttribute('company_id'), $model->getOriginal('company_id')],
        ), SORT_REGULAR));
    }
}
