<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;

/**
 * One task of a checklist template: who does it (responsible_role, or a
 * named responsible user for IT / admin work), when (due_offset_days from
 * the joining date or last working day) and, optionally, the employee
 * document type whose upload completes it.
 *
 * @property int $due_offset_days
 */
class ChecklistTemplateTask extends Model
{
    protected $table = 'employees_checklist_template_tasks';

    protected $fillable = [
        'template_id',
        'code',
        'title',
        'description',
        'responsible_role',
        'responsible_user_id',
        'due_offset_days',
        'document_type_id',
        'action_key',
        'sort',
    ];

    protected $casts = [
        'due_offset_days' => 'integer',
        'sort'            => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'template_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }
}
