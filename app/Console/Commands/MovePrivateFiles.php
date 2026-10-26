<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Moves sensitive uploads that older code stored on the web-reachable public
 * disk (anyone with the link could open them) to the private disk, keeping
 * the same relative paths so database records stay valid. Idempotent.
 */
class MovePrivateFiles extends Command
{
    protected $signature = 'security:move-private-files {--dry-run : Only list what would be moved}';

    protected $description = 'Move chatter attachments, employee work permits and request attachments from the public disk to the private disk';

    /** Directories (relative to each disk root) that hold private documents. */
    public const DIRECTORIES = [
        'chats-attachments',
        'log-attachments',
        'messages-attachments',
        'employees/work-permit',
        'employees/requests',
    ];

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;
        $skipped = 0;

        foreach (self::DIRECTORIES as $directory) {
            if (! $public->exists($directory)) {
                continue;
            }

            foreach ($public->allFiles($directory) as $path) {
                if ($private->exists($path)) {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    $private->writeStream($path, $public->readStream($path));

                    if ($private->size($path) !== $public->size($path)) {
                        $this->error("Copy check failed for {$path}; left on the public disk.");
                        $private->delete($path);

                        continue;
                    }

                    $public->delete($path);
                }

                $moved++;
            }
        }

        $this->info(($dryRun ? 'Would move' : 'Moved')." {$moved} file(s) to the private disk; {$skipped} already there.");

        return self::SUCCESS;
    }
}
