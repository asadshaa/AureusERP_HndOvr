<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentAudit;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<DocumentAudit>
 */
class DocumentAuditFactory extends Factory
{
    protected $model = DocumentAudit::class;

    public function definition(): array
    {
        return [
            'company_id'  => Company::factory(),
            'document_id' => Document::factory(),
            'actor_id'    => User::query()->value('id') ?? User::factory(),
            'action'      => DocumentAuditAction::Uploaded,
        ];
    }
}
