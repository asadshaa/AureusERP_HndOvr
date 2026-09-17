<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Enums\DriveSyncStatus;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentDriveSync;

/**
 * @extends Factory<DocumentDriveSync>
 */
class DocumentDriveSyncFactory extends Factory
{
    protected $model = DocumentDriveSync::class;

    public function definition(): array
    {
        return [
            'document_id'     => Document::factory(),
            'status'          => DriveSyncStatus::NotSynced,
            'exists_in_drive' => false,
        ];
    }

    public function synced(): static
    {
        return $this->state(fn () => [
            'status'          => DriveSyncStatus::Synced,
            'exists_in_drive' => true,
            'drive_file_id'   => 'fake-'.fake()->uuid(),
            'last_synced_at'  => now(),
        ]);
    }
}
