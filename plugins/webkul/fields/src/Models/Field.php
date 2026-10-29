<?php

namespace Webkul\Field\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Field extends Model implements Sortable
{
    use SoftDeletes, SortableTrait;

    /**
     * Table name.
     *
     * @var string
     */
    protected $table = 'custom_fields';

    /**
     * Table name.
     *
     * @var string
     */
    protected $casts = [
        'is_multiselect'    => 'boolean',
        'options'           => 'array',
        'form_settings'     => 'array',
        'table_settings'    => 'array',
        'infolist_settings' => 'array',
    ];

    /**
     * Fillable.
     *
     * @var array
     */
    protected $fillable = [
        'code',
        'name',
        'type',
        'input_type',
        'is_multiselect',
        'datalist',
        'options',
        'form_settings',
        'use_in_table',
        'table_settings',
        'infolist_settings',
        'sort',
        'customizable_type',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    /**
     * Active field definitions (code, type, is_multiselect) grouped by customizable type.
     * Loaded with one query and reused until a field is saved, deleted or restored.
     *
     * @var array<string, Collection<int, static>>|null
     */
    protected static ?array $definitionsByCustomizableType = null;

    protected static function booted(): void
    {
        $flush = fn () => static::flushDefinitionCache();

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
        static::forceDeleted($flush);
    }

    /**
     * The active custom field definitions for one customizable model class.
     *
     * @return Collection<int, static>
     */
    public static function definitionsFor(string $customizableType): Collection
    {
        static::$definitionsByCustomizableType ??= static::query()
            ->get(['code', 'type', 'is_multiselect', 'customizable_type'])
            ->groupBy('customizable_type')
            ->all();

        return static::$definitionsByCustomizableType[$customizableType] ?? new Collection;
    }

    public static function flushDefinitionCache(): void
    {
        static::$definitionsByCustomizableType = null;
    }
}
