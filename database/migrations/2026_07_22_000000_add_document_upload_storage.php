<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('documents')) {
            return;
        }

        $missing = [
            'storage_disk' => !Schema::hasColumn('documents', 'storage_disk'),
            'file_path' => !Schema::hasColumn('documents', 'file_path'),
            'mime_type' => !Schema::hasColumn('documents', 'mime_type'),
            'file_size' => !Schema::hasColumn('documents', 'file_size'),
        ];

        if (!in_array(true, $missing, true)) {
            return;
        }

        Schema::table('documents', function (Blueprint $table) use ($missing) {
            if ($missing['storage_disk']) {
                $table->string('storage_disk', 40)->nullable();
            }
            if ($missing['file_path']) {
                $table->string('file_path', 700)->nullable();
            }
            if ($missing['mime_type']) {
                $table->string('mime_type', 160)->nullable();
            }
            if ($missing['file_size']) {
                $table->unsignedBigInteger('file_size')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Forward-only: uploaded document references should not be discarded automatically.
    }
};
