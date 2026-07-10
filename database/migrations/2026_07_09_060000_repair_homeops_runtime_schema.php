<?php

use App\Support\HomeOpsSchemaRepair;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        HomeOpsSchemaRepair::run(false);
    }

    public function down(): void
    {
        // Forward-only repair. Never remove existing HomeOps data or columns.
    }
};
