<?php

namespace App\Services\Demo;

use App\Models\DemoRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Remembers every row the demo seeder creates (demo_records), so demo:purge
 * removes exactly those rows and a re-run never creates an item twice.
 *
 * While recording, every Eloquent "created" event is captured: the rows the
 * seeder writes itself and every side effect of the services it calls
 * (partners, approval requests and decisions, chatter, notifications,
 * allocations...). Rows written with bulk inserts are added with track().
 * Captured rows are written to demo_records after the surrounding
 * transaction, and only those that still exist (a rolled-back row is never
 * remembered).
 */
class DemoRecorder
{
    /** @var array<int, array{table: string, key: string, id: string, company_id: ?int, phase: string}> */
    private array $buffer = [];

    private bool $recording = false;

    private bool $listening = false;

    private ?int $companyId = null;

    private string $phase = DemoRecord::PHASE_STAFF;

    /** @var array<string, int> rows remembered per table since resetCreated() */
    private array $created = [];

    public function start(): void
    {
        if (! $this->listening) {
            Event::listen('eloquent.created: *', function (string $event, array $payload): void {
                $this->capture($payload[0] ?? null);
            });
            $this->listening = true;
        }

        $this->recording = true;
    }

    public function stop(): void
    {
        $this->flush();
        $this->recording = false;
    }

    public function context(?int $companyId, string $phase): void
    {
        $this->flush();
        $this->companyId = $companyId;
        $this->phase = $phase;
    }

    public function companyId(): ?int
    {
        return $this->companyId;
    }

    public function resetCreated(): void
    {
        $this->flush();
        $this->created = [];
    }

    /** @return array<string, int> rows created per table since resetCreated() */
    public function created(): array
    {
        $this->flush();
        ksort($this->created);

        return $this->created;
    }

    /**
     * Runs the callback in a transaction and remembers what it created,
     * including rows written by after-commit callbacks.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } finally {
            $this->flush();
        }
    }

    /**
     * Rows written without Eloquent events (bulk inserts).
     *
     * @param  array<int, int|string>  $ids
     */
    public function track(string $table, array $ids, string $keyName = 'id'): void
    {
        foreach ($ids as $id) {
            $this->buffer[] = ['table' => $table, 'key' => $keyName, 'id' => (string) $id, 'company_id' => $this->companyId, 'phase' => $this->phase];
        }
    }

    public function trackFile(string $disk, string $path): void
    {
        DemoRecord::query()->insert([
            'company_id'   => $this->companyId,
            'phase'        => $this->phase,
            'record_table' => DemoRecord::FILE_TABLE,
            'record_id'    => $disk.':'.$path,
            'created_at'   => now(),
        ]);
    }

    /** Whether the seeder already created the item with this key. */
    public function has(string $key): bool
    {
        return DemoRecord::query()->where('demo_key', $key)->exists();
    }

    /** The row created for a key, or null. */
    public function find(string $key): ?DemoRecord
    {
        return DemoRecord::query()->where('demo_key', $key)->first();
    }

    /** Marks the item with this key as created (by the given row). */
    public function mark(string $key, Model $model): void
    {
        DemoRecord::query()->insertOrIgnore([
            'company_id'   => $this->companyId,
            'phase'        => $this->phase,
            'record_table' => $model->getTable(),
            'record_id'    => (string) $model->getKey(),
            'demo_key'     => $key,
            'created_at'   => now(),
        ]);
    }

    /**
     * A pre-existing demo row the seeder filled in: purge restores $original
     * (only where the value is still the one the seeder wrote).
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $applied
     */
    public function rememberFill(Model $model, array $original, array $applied): void
    {
        if ($applied === []) {
            return;
        }

        $key = 'fill:'.$model->getTable().':'.$model->getKey();
        $existing = $this->find($key);

        if ($existing) {
            // A later run filled more fields: keep the first original of each field.
            $existing->update([
                'original' => (array) $existing->original + $original,
                'applied'  => $applied + (array) $existing->applied,
            ]);

            return;
        }

        DemoRecord::query()->create([
            'company_id'   => $this->companyId,
            'phase'        => DemoRecord::PHASE_STAFF,
            'record_table' => $model->getTable(),
            'record_id'    => (string) $model->getKey(),
            'demo_key'     => $key,
            'original'     => $original,
            'applied'      => $applied,
        ]);
    }

    /** Writes the captured rows that still exist to demo_records. */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $buffer = $this->buffer;
        $this->buffer = [];
        $existing = [];

        foreach (collect($buffer)->groupBy(fn (array $row): string => $row['table'].'|'.$row['key']) as $group => $rows) {
            [$table, $keyName] = explode('|', $group);

            foreach ($rows->pluck('id')->unique()->chunk(1000) as $ids) {
                foreach (DB::table($table)->whereIn($keyName, $ids->all())->pluck($keyName) as $id) {
                    $existing[$table][(string) $id] = true;
                }
            }
        }

        $now = now();
        $rows = [];
        foreach ($buffer as $row) {
            if (! isset($existing[$row['table']][$row['id']])) {
                continue;
            }

            $this->created[$row['table']] = ($this->created[$row['table']] ?? 0) + 1;
            $rows[] = [
                'company_id'   => $row['company_id'],
                'phase'        => $row['phase'],
                'record_table' => $row['table'],
                'record_id'    => $row['id'],
                'created_at'   => $now,
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DemoRecord::query()->insert($chunk);
        }
    }

    private function capture(mixed $model): void
    {
        if (! $this->recording || ! $model instanceof Model || $model instanceof DemoRecord) {
            return;
        }

        $id = $model->getKey();

        if ($id === null || is_array($id)) {
            return;
        }

        $this->buffer[] = [
            'table'      => $model->getTable(),
            'key'        => $model->getKeyName(),
            'id'         => (string) $id,
            'company_id' => $this->companyId,
            'phase'      => $this->phase,
        ];
    }
}
