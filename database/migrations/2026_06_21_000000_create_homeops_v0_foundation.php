<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('homes')) {
            Schema::create('homes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->string('name', 160);
                $table->string('property_type', 80)->nullable();
                $table->string('city_region', 160)->nullable();
                $table->date('purchase_date')->nullable();
                $table->decimal('purchase_price', 12, 2)->nullable();
                $table->unsignedInteger('square_footage')->nullable();
                $table->string('cover_image_url', 500)->nullable();
                $table->string('currency', 3)->default('CAD');
                $table->decimal('mortgage_payment', 12, 2)->nullable();
                $table->decimal('hoa_fee', 12, 2)->nullable();
                $table->decimal('property_tax', 12, 2)->nullable();
                $table->decimal('insurance', 12, 2)->nullable();
                $table->decimal('utilities', 12, 2)->nullable();
                $table->decimal('internet', 12, 2)->nullable();
                $table->decimal('other_baseline_costs', 12, 2)->nullable();
                $table->string('occupancy_status', 80)->nullable();
                $table->string('primary_use', 80)->nullable();
                $table->string('parking', 120)->nullable();
                $table->string('locker', 120)->nullable();
                $table->text('service_notes')->nullable();
                $table->json('condo_rules_flags')->nullable();
                $table->boolean('is_primary')->default(false)->index();
                $table->timestamps();

                $table->index(['user_id', 'is_primary']);
            });
        }

        if (!Schema::hasTable('rooms')) {
            Schema::create('rooms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->index();
                $table->string('name', 120);
                $table->string('room_type', 80)->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('sort_order')->default(50);
                $table->timestamps();

                $table->index(['home_id', 'sort_order']);
            });
        }

        if (!Schema::hasTable('home_assets')) {
            Schema::create('home_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->index();
                $table->foreignId('room_id')->nullable()->index();
                $table->string('name', 160);
                $table->string('asset_type', 100)->nullable();
                $table->string('brand', 120)->nullable();
                $table->string('model', 120)->nullable();
                $table->string('serial_number', 160)->nullable();
                $table->date('installed_on')->nullable();
                $table->date('warranty_expires_on')->nullable();
                $table->string('status', 40)->default('active')->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['home_id', 'asset_type']);
            });
        }

        if (!Schema::hasTable('ownership_events')) {
            Schema::create('ownership_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->index();
                $table->string('event_type', 80)->default('custom')->index();
                $table->string('title', 180);
                $table->date('event_date')->index();
                $table->text('description')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['home_id', 'event_date']);
            });
        }

        if (!Schema::hasTable('home_photos')) {
            Schema::create('home_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->index();
                $table->string('url', 500);
                $table->string('caption', 180)->nullable();
                $table->boolean('is_cover')->default(false)->index();
                $table->timestamps();
            });
        }

        foreach (['bills', 'bill_instances', 'ledger_entries', 'receipts', 'maintenance_items', 'maintenance_logs', 'wishlist_items', 'spending_periods'] as $tableName) {
            $this->addNullableIndexedColumn($tableName, 'home_id');
        }

        $this->addNullableIndexedColumn('wishlist_items', 'room_id');
        $this->addNullableIndexedColumn('maintenance_items', 'asset_id');
        $this->addNullableIndexedColumn('ledger_entries', 'room_id');
        $this->addNullableIndexedColumn('ledger_entries', 'asset_id');
        $this->addNullableIndexedColumn('receipts', 'room_id');
        $this->addNullableIndexedColumn('receipts', 'asset_id');

        $this->seedDefaultHomeForDevUser();
    }

    public function down(): void
    {
        foreach (['bills', 'bill_instances', 'ledger_entries', 'receipts', 'maintenance_items', 'maintenance_logs', 'wishlist_items', 'spending_periods'] as $tableName) {
            $this->dropColumnIfExists($tableName, 'home_id');
        }

        foreach ([
            ['wishlist_items', 'room_id'],
            ['maintenance_items', 'asset_id'],
            ['ledger_entries', 'room_id'],
            ['ledger_entries', 'asset_id'],
            ['receipts', 'room_id'],
            ['receipts', 'asset_id'],
        ] as [$tableName, $column]) {
            $this->dropColumnIfExists($tableName, $column);
        }

        Schema::dropIfExists('home_photos');
        Schema::dropIfExists('ownership_events');
        Schema::dropIfExists('home_assets');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('homes');
    }

    private function addNullableIndexedColumn(string $tableName, string $columnName): void
    {
        if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, $columnName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columnName) {
            $table->unsignedBigInteger($columnName)->nullable()->index();
        });
    }

    private function dropColumnIfExists(string $tableName, string $columnName): void
    {
        if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, $columnName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columnName) {
            $table->dropColumn($columnName);
        });
    }

    private function seedDefaultHomeForDevUser(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('homes')) {
            return;
        }

        $userId = DB::table('users')->orderBy('id')->value('id');

        if (!$userId || DB::table('homes')->where('user_id', $userId)->exists()) {
            return;
        }

        $homeId = DB::table('homes')->insertGetId([
            'user_id' => $userId,
            'name' => 'Toronto Townhouse',
            'property_type' => 'townhouse',
            'city_region' => 'Toronto, ON',
            'purchase_date' => '2026-06-05',
            'purchase_price' => 425000,
            'square_footage' => 700,
            'currency' => 'CAD',
            'mortgage_payment' => 1985,
            'hoa_fee' => 727,
            'property_tax' => 220,
            'occupancy_status' => 'owner_occupied',
            'primary_use' => 'primary_residence',
            'is_primary' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (Schema::hasTable('rooms')) {
            $rooms = [
                ['Living room', 'living'],
                ['Kitchen', 'kitchen'],
                ['Primary bedroom', 'bedroom'],
                ['Office / studio', 'office'],
                ['Bathroom', 'bathroom'],
                ['Balcony / exterior', 'exterior'],
            ];

            foreach ($rooms as $index => [$name, $type]) {
                DB::table('rooms')->insert([
                    'user_id' => $userId,
                    'home_id' => $homeId,
                    'name' => $name,
                    'room_type' => $type,
                    'sort_order' => ($index + 1) * 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('ownership_events')) {
            foreach ([
                ['purchase', 'Purchase closed', '2026-06-05', 'HomeOps V0 starter timeline event.'],
                ['keys', 'Keys received', '2026-06-05', 'First operational day for the property.'],
                ['move_in', 'Move-in / setup period', '2026-06-06', 'Moving, paint, furniture, AC and first ownership chaos.'],
            ] as [$type, $title, $date, $description]) {
                DB::table('ownership_events')->insert([
                    'user_id' => $userId,
                    'home_id' => $homeId,
                    'event_type' => $type,
                    'title' => $title,
                    'event_date' => $date,
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
