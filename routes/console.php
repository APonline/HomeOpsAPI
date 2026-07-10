<?php

use App\Support\HomeOpsSchemaRepair;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('homeops:repair-schema {--no-sync : Do not reconcile the three Laravel framework baseline migrations}', function () {
    $this->info('Checking the HomeOps database schema...');

    $changes = HomeOpsSchemaRepair::run(!$this->option('no-sync'));

    if ($changes === []) {
        $this->info('HomeOps schema is already ready.');
        return Command::SUCCESS;
    }

    foreach ($changes as $change) {
        $this->line("  ✓ {$change}");
    }

    $this->newLine();
    $this->info('HomeOps schema repair completed.');

    return Command::SUCCESS;
})->purpose('Repair HomeOps tables and reconcile an existing Laravel database safely');
