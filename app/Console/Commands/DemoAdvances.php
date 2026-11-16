<?php

namespace App\Console\Commands;

use Database\Seeders\DemoAdvanceSeeder;
use Illuminate\Console\Command;

class DemoAdvances extends Command
{
    protected $signature = 'demo:advances';

    protected $description = 'Seed realistic business advances (every state, with settlements) for the ShipLine and OpenPort demo companies';

    public function handle(): int
    {
        config(['mail.default' => 'log', 'queue.default' => 'sync', 'accounting_drive.enabled' => false]);

        $seeder = app(DemoAdvanceSeeder::class);
        $seeder->run();

        foreach ($seeder->summary as $company => $created) {
            $this->info($company.': '.($created === [] ? 'nothing new' : collect($created)->map(fn (int $count, string $table): string => "{$table} {$count}")->implode(', ')));
        }

        foreach ($seeder->warnings as $warning) {
            $this->warn($warning);
        }

        $this->info('Remove it with: php artisan demo:purge --company="<company>"');

        return self::SUCCESS;
    }
}
