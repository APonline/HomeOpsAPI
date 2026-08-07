<?php

use App\Support\HomeOpsSchemaRepair;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

Artisan::command('homeops:cleanup-receipt-scans', function () {
    if (!Schema::hasTable('receipt_scans')) {
        $this->info('Receipt scan queue is not installed.');
        return Command::SUCCESS;
    }

    $expired = DB::table('receipt_scans')
        ->where('status', '!=', 'committed')
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->get(['id', 'storage_disk', 'file_path']);

    foreach ($expired as $scan) {
        if (!empty($scan->file_path)) {
            Storage::disk($scan->storage_disk ?: 'local')->delete($scan->file_path);
        }
    }

    if ($expired->isNotEmpty()) {
        DB::table('receipt_scans')->whereIn('id', $expired->pluck('id'))->delete();
    }

    $this->info('Removed '.$expired->count().' expired receipt scan(s).');
    return Command::SUCCESS;
})->purpose('Delete expired temporary receipt images and scan sessions');

Schedule::command('homeops:cleanup-receipt-scans')->hourly()->withoutOverlapping();
