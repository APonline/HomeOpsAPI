<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairDocuments();
        $this->addMaintenanceInventory();
        $this->addMaintenanceLogInventoryFields();
    }

    private function repairDocuments(): void
    {
        if (!Schema::hasTable('documents')) {
            Schema::create('documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->foreignId('room_id')->nullable()->index();
                $table->foreignId('asset_id')->nullable()->index();
                $table->string('title', 180);
                $table->string('document_type', 60)->default('other')->index();
                $table->string('provider', 160)->nullable();
                $table->date('document_date')->nullable()->index();
                $table->date('expires_on')->nullable()->index();
                $table->string('file_url', 700)->nullable();
                $table->string('file_name', 255)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_favourite')->default(false)->index();
                $table->timestamps();
            });

            return;
        }

        $this->addMissingColumns('documents', [
            'user_id' => fn (Blueprint $table) => $table->foreignId('user_id')->nullable()->index(),
            'home_id' => fn (Blueprint $table) => $table->foreignId('home_id')->nullable()->index(),
            'room_id' => fn (Blueprint $table) => $table->foreignId('room_id')->nullable()->index(),
            'asset_id' => fn (Blueprint $table) => $table->foreignId('asset_id')->nullable()->index(),
            'title' => fn (Blueprint $table) => $table->string('title', 180)->nullable(),
            'document_type' => fn (Blueprint $table) => $table->string('document_type', 60)->default('other')->index(),
            'provider' => fn (Blueprint $table) => $table->string('provider', 160)->nullable(),
            'document_date' => fn (Blueprint $table) => $table->date('document_date')->nullable()->index(),
            'expires_on' => fn (Blueprint $table) => $table->date('expires_on')->nullable()->index(),
            'file_url' => fn (Blueprint $table) => $table->string('file_url', 700)->nullable(),
            'file_name' => fn (Blueprint $table) => $table->string('file_name', 255)->nullable(),
            'notes' => fn (Blueprint $table) => $table->text('notes')->nullable(),
            'is_favourite' => fn (Blueprint $table) => $table->boolean('is_favourite')->default(false)->index(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ]);
    }

    private function addMaintenanceInventory(): void
    {
        if (!Schema::hasTable('maintenance_items')) {
            return;
        }

        $this->addMissingColumns('maintenance_items', [
            'room_id' => fn (Blueprint $table) => $table->foreignId('room_id')->nullable()->index(),
            'tracks_inventory' => fn (Blueprint $table) => $table->boolean('tracks_inventory')->default(false)->index(),
            'quantity_on_hand' => fn (Blueprint $table) => $table->unsignedInteger('quantity_on_hand')->default(0),
            'units_per_service' => fn (Blueprint $table) => $table->unsignedInteger('units_per_service')->default(1),
            'pack_quantity' => fn (Blueprint $table) => $table->unsignedInteger('pack_quantity')->nullable(),
            'restock_cost' => fn (Blueprint $table) => $table->decimal('restock_cost', 12, 2)->nullable(),
            'inventory_unit' => fn (Blueprint $table) => $table->string('inventory_unit', 60)->nullable(),
        ]);
    }

    private function addMaintenanceLogInventoryFields(): void
    {
        if (!Schema::hasTable('maintenance_logs')) {
            return;
        }

        $this->addMissingColumns('maintenance_logs', [
            'log_type' => fn (Blueprint $table) => $table->string('log_type', 30)->default('completed')->index(),
            'quantity_delta' => fn (Blueprint $table) => $table->integer('quantity_delta')->default(0),
            'quantity_after' => fn (Blueprint $table) => $table->unsignedInteger('quantity_after')->nullable(),
        ]);
    }

    private function addMissingColumns(string $tableName, array $columns): void
    {
        foreach ($columns as $columnName => $definition) {
            if (Schema::hasColumn($tableName, $columnName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($definition) {
                $definition($table);
            });
        }
    }

    public function down(): void
    {
        // Forward-only production repair. Do not remove user data on rollback.
    }
};
