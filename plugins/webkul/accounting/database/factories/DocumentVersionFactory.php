<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentVersion;
use Webkul\Security\Models\User;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    protected $model = DocumentVersion::class;

    public function definition(): array
    {
        return [
            'document_id'        => Document::factory(),
            'version_number'     => 1,
            'storage_disk'       => 'accounting_documents',
            'storage_path'       => 'companies/1/'.fake()->uuid().'.pdf',
            'original_filename'  => fake()->word().'.pdf',
            'mime_type'          => 'application/pdf',
            'file_size'          => fake()->numberBetween(1024, 1024 * 1024),
            'checksum_sha256'    => hash('sha256', fake()->uuid()),
            'uploaded_by'        => User::query()->value('id') ?? User::factory(),
        ];
    }
}
