<?php

namespace Webkul\Accounting\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Accounting\Services\DocumentIntegrityChecker;

/**
 * Read-only. Reports missing storage objects and orphaned files; fixes
 * nothing. See DocumentIntegrityChecker for what each finding means.
 */
class CheckDocumentIntegrityCommand extends Command
{
    protected $signature = 'accounting:documents:check-integrity {--company= : Limit the check to one company ID}';

    protected $description = 'Scan accounting documents for missing storage objects and orphaned files (detection only -- nothing is deleted or repaired)';

    public function handle(DocumentIntegrityChecker $checker): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;

        $report = $checker->check($companyId);

        $this->info("Checked {$report['versions_checked']} document version(s).");

        if ($report['missing_objects']) {
            $this->error(count($report['missing_objects']).' version(s) reference a storage object that no longer exists:');

            foreach ($report['missing_objects'] as $missing) {
                $this->line("  - document #{$missing['document_id']}, version #{$missing['version_number']} (version id {$missing['version_id']}): {$missing['storage_path']}");
            }
        } else {
            $this->info('No missing storage objects found.');
        }

        if ($report['orphan_files']) {
            $this->warn(count($report['orphan_files']).' storage object(s) have no matching document version record:');

            foreach ($report['orphan_files'] as $path) {
                $this->line("  - {$path}");
            }
        } else {
            $this->info('No orphaned storage objects found.');
        }

        return ($report['missing_objects'] || $report['orphan_files']) ? self::FAILURE : self::SUCCESS;
    }
}
