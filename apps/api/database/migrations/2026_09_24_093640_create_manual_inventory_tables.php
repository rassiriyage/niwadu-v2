<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id');
            $table->foreignId('room_type_id')->unique();
            $table->foreign(['hotel_id', 'room_type_id'])->references(['hotel_id', 'id'])->on('room_types')->restrictOnDelete();
            $table->enum('owner', ['unconfigured', 'manual', 'pms'])->default('unconfigured');
            $table->enum('sales_state', ['closed', 'open'])->default('closed');
            $table->string('timezone')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('ownership_version')->default(1);
            $table->timestamps();
        });
        Schema::create('rate_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id');
            $table->foreignId('room_type_id');
            $table->foreign(['hotel_id', 'room_type_id'])->references(['hotel_id', 'id'])->on('room_types')->restrictOnDelete();
            $table->string('name');
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->enum('currency', ['LKR']);
            $table->json('policy');
            $table->unique(['hotel_id', 'room_type_id', 'id']);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('inventory_nights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_pool_id')->constrained()->restrictOnDelete();
            $table->date('stay_date');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('held')->default(0);
            $table->unsignedInteger('sold')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->unique(['inventory_pool_id', 'stay_date']);
        });
        Schema::create('rate_plan_nights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->restrictOnDelete();
            $table->date('stay_date');
            foreach (['base_minor', 'tax_minor', 'fee_minor'] as $field) {
                $table->unsignedBigInteger($field);
            }
            $table->boolean('mandatory_charges_complete');
            $table->boolean('stop_sell');
            $table->unsignedSmallInteger('min_stay');
            $table->unsignedSmallInteger('max_stay');
            $table->boolean('closed_to_arrival');
            $table->boolean('closed_to_departure');
            $table->unsignedInteger('version')->default(1);
            $table->unique(['rate_plan_id', 'stay_date']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_nights ADD CONSTRAINT inventory_counts_valid CHECK (capacity >= 0 AND capacity <= 100000 AND held >= 0 AND sold >= 0 AND held + sold <= capacity)');
            DB::statement('ALTER TABLE rate_plan_nights ADD CONSTRAINT nightly_rates_valid CHECK (base_minor BETWEEN 0 AND 1000000000 AND tax_minor BETWEEN 0 AND 1000000000 AND fee_minor BETWEEN 0 AND 1000000000 AND min_stay BETWEEN 1 AND 30 AND max_stay BETWEEN min_stay AND 30)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plan_nights');
        Schema::dropIfExists('inventory_nights');
        Schema::dropIfExists('rate_plans');
        Schema::dropIfExists('inventory_pools');
    }
};
