<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Older HomeOps databases may still have bills.status as a restrictive
     * MySQL ENUM. Current application code uses lifecycle values such as
     * "active" and "inactive", so normalize the existing column to VARCHAR.
     */
    public function up(): void
    {
        if (!Schema::hasTable('bills') || !Schema::hasColumn('bills', 'status')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE `bills` MODIFY COLUMN `status` VARCHAR(40) NOT NULL DEFAULT 'active'"
            );
        }
    }

    /**
     * Intentionally forward-only. Converting back to an unknown legacy ENUM
     * could truncate valid lifecycle values and operational data.
     */
    public function down(): void
    {
        // No destructive rollback.
    }
};
