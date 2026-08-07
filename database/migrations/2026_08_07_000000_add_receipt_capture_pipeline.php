<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receipts')) {
            Schema::table('receipts', function (Blueprint $table) {
                if (!Schema::hasColumn('receipts', 'subtotal_amount')) {
                    $table->decimal('subtotal_amount', 12, 2)->nullable();
                }
                if (!Schema::hasColumn('receipts', 'tax_amount')) {
                    $table->decimal('tax_amount', 12, 2)->nullable();
                }
                if (!Schema::hasColumn('receipts', 'tip_amount')) {
                    $table->decimal('tip_amount', 12, 2)->nullable();
                }
                if (!Schema::hasColumn('receipts', 'currency')) {
                    $table->string('currency', 3)->default('CAD');
                }
                if (!Schema::hasColumn('receipts', 'payment_method')) {
                    $table->string('payment_method', 80)->nullable();
                }
                if (!Schema::hasColumn('receipts', 'storage_disk')) {
                    $table->string('storage_disk', 40)->nullable();
                }
                if (!Schema::hasColumn('receipts', 'file_path')) {
                    $table->string('file_path', 700)->nullable();
                }
                if (!Schema::hasColumn('receipts', 'mime_type')) {
                    $table->string('mime_type', 120)->nullable();
                }
                if (!Schema::hasColumn('receipts', 'file_size')) {
                    $table->unsignedBigInteger('file_size')->nullable();
                }
                if (!Schema::hasColumn('receipts', 'file_hash')) {
                    $table->string('file_hash', 64)->nullable()->index();
                }
                if (!Schema::hasColumn('receipts', 'capture_source')) {
                    $table->string('capture_source', 40)->default('manual')->index();
                }
                if (!Schema::hasColumn('receipts', 'extraction_provider')) {
                    $table->string('extraction_provider', 60)->nullable();
                }
                if (!Schema::hasColumn('receipts', 'extraction_confidence')) {
                    $table->decimal('extraction_confidence', 5, 4)->nullable();
                }
                if (!Schema::hasColumn('receipts', 'raw_ocr_text')) {
                    $table->longText('raw_ocr_text')->nullable();
                }
                if (!Schema::hasColumn('receipts', 'extracted_data')) {
                    $table->json('extracted_data')->nullable();
                }
            });
        }

        if (!Schema::hasTable('receipt_items')) {
            Schema::create('receipt_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('receipt_id')->index();
                $table->unsignedInteger('line_number')->default(1);
                $table->string('description', 255);
                $table->decimal('quantity', 10, 3)->nullable();
                $table->decimal('unit_price', 12, 2)->nullable();
                $table->decimal('line_total', 12, 2)->nullable();
                $table->string('category_hint', 120)->nullable();
                $table->timestamps();
                $table->index(['receipt_id', 'line_number']);
            });
        }

        if (!Schema::hasTable('receipt_scans')) {
            Schema::create('receipt_scans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->foreignId('receipt_id')->nullable()->index();
                $table->string('status', 40)->default('processing')->index();
                $table->string('provider', 60)->nullable();
                $table->decimal('confidence', 5, 4)->nullable();
                $table->string('storage_disk', 40)->default('local');
                $table->string('file_path', 700);
                $table->string('file_name', 255);
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('file_hash', 64)->nullable()->index();
                $table->json('extracted_data')->nullable();
                $table->longText('raw_ocr_text')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('committed_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'home_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Forward-only: receipt records and captured files should never be silently discarded.
    }
};
