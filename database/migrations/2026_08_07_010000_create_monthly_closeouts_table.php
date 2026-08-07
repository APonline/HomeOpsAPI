<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('monthly_closeouts')) {
            return;
        }

        Schema::create('monthly_closeouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->foreignId('home_id')->nullable()->index();
            $table->date('period_month')->index();
            $table->string('status', 20)->default('open')->index();
            $table->text('closing_note')->nullable();
            $table->boolean('confirmed_unpaid')->default(false);
            $table->json('snapshot')->nullable();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['user_id', 'home_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_closeouts');
    }
};
