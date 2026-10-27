<?php

namespace Webkul\Security\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

trait HasPermissionScope
{
    protected ?string $ownerColumn = 'creator_id';

    protected ?string $assignmentColumn = 'user_id';

    protected ?string $pivotTable = null;

    protected ?string $pivotForeignKey = null;

    protected ?string $pivotRelatedKey = 'user_id';

    /**
     * Memoised schema lookups, keyed by "connection.table.column", so the
     * permission scope does not hit information_schema on every query build.
     *
     * @var array<string, bool>
     */
    protected static array $permissionScopeColumnCache = [];

    protected function getOwnerColumn(): string
    {
        return $this->ownerColumn;
    }

    protected function getAssignmentColumn(): ?string
    {
        return $this->assignmentColumn;
    }

    protected function getPivotTable(): ?string
    {
        return $this->pivotTable;
    }

    protected function getPivotForeignKey(): ?string
    {
        return $this->pivotForeignKey;
    }

    protected function getPivotRelatedKey(): string
    {
        return $this->pivotRelatedKey;
    }

    protected function setOwnerColumn(string $column): void
    {
        $this->ownerColumn = $column;
    }

    protected function setAssignmentColumn(?string $column): void
    {
        $this->assignmentColumn = $column;
    }

    protected function setPivotTable(?string $table): void
    {
        $this->pivotTable = $table;
    }

    protected function setPivotForeignKey(?string $key): void
    {
        $this->pivotForeignKey = $key;
    }

    protected function setPivotRelatedKey(string $key): void
    {
        $this->pivotRelatedKey = $key;
    }

    public function scopeApplyPermissionScope(Builder $query): Builder
    {
        $user = filament()->auth()->user();

        if (! $user) {
            return $query;
        }

        $userIds = bouncer()->getAuthorizedUserIds();

        if (empty($userIds)) {
            return $query;
        }

        return $query->where(function (Builder $subQuery) use ($userIds) {
            $subQuery->whereIn($this->getOwnerColumn(), $userIds);

            $assignmentColumn = $this->getAssignmentColumn();

            if (
                $assignmentColumn &&
                $this->permissionScopeTableHasColumn($assignmentColumn)
            ) {
                $subQuery->orWhereIn($assignmentColumn, $userIds);
            }

            $pivotTable = $this->getPivotTable();
            if ($pivotTable) {
                $pivotForeignKey = $this->getPivotForeignKey() ?? $this->getTable().'_id';
                $pivotRelatedKey = $this->getPivotRelatedKey();

                $subQuery->orWhereExists(function ($existsQuery) use ($pivotTable, $pivotForeignKey, $pivotRelatedKey, $userIds) {
                    $existsQuery->select(DB::raw(1))
                        ->from($pivotTable)
                        ->whereColumn($this->getTable().'.id', $pivotTable.'.'.$pivotForeignKey)
                        ->whereIn($pivotTable.'.'.$pivotRelatedKey, $userIds);
                });
            }
        });
    }

    protected function permissionScopeTableHasColumn(string $column): bool
    {
        $cacheKey = $this->getConnection()->getName().'.'.$this->getTable().'.'.$column;

        return static::$permissionScopeColumnCache[$cacheKey] ??= $this->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($this->getTable(), $column);
    }
}
