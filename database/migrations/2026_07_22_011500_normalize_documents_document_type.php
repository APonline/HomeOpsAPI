<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('documents')
            || !Schema::hasColumn('documents', 'document_type')
        ) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE `documents`
                 MODIFY `document_type` VARCHAR(60) NOT NULL DEFAULT 'other'"
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(
                "ALTER TABLE documents
                 ALTER COLUMN document_type TYPE VARCHAR(60)
                 USING document_type::VARCHAR"
            );
            DB::statement(
                "ALTER TABLE documents
                 ALTER COLUMN document_type SET DEFAULT 'other'"
            );
            DB::statement(
                "ALTER TABLE documents
                 ALTER COLUMN document_type SET NOT NULL"
            );
        }
    }

    public function down(): void
    {
        // Forward-only: do not restore the restrictive legacy enum.
    }
};
