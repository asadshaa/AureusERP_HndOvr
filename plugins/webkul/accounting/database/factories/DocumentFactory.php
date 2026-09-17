<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Enums\DocumentStatus;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Models\Document;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'company_id'    => Company::factory(),
            'creator_id'    => User::query()->value('id') ?? User::factory(),
            'document_type' => DocumentType::Other,
            'title'         => fake()->words(3, true),
            'description'   => fake()->sentence(),
            'status'        => DocumentStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Archived,
        ]);
    }
}
