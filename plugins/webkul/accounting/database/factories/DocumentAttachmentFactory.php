<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentAttachment;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<DocumentAttachment>
 */
class DocumentAttachmentFactory extends Factory
{
    protected $model = DocumentAttachment::class;

    public function definition(): array
    {
        return [
            'company_id'  => Company::factory(),
            'document_id' => Document::factory(),
            'creator_id'  => User::query()->value('id') ?? User::factory(),
        ];
    }
}
