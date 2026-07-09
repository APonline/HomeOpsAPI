<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('budget_profiles')) {
            return;
        }

        Schema::create('budget_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->foreignId('home_id')->nullable()->index();
            $table->string('profile_name', 140)->default('Monthly operating plan');
            $table->date('period_month')->nullable()->index();
            $table->decimal('monthly_take_home', 12, 2)->nullable();
            $table->decimal('savings_target', 12, 2)->nullable();
            $table->decimal('discretionary_cap', 12, 2)->nullable();
            $table->string('currency', 3)->default('CAD');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['user_id', 'home_id', 'is_active']);
            $table->index(['user_id', 'home_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_profiles');
    }
};
