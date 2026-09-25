<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_inventory_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_intent_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('inventory_pool_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('ownership_version');
            $table->unsignedSmallInteger('night_count');
            $table->enum('state', ['active', 'released', 'expired']);
            $table->timestampTz('expires_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
            $table->index(['state', 'expires_at']);
        });
        Schema::create('manual_inventory_hold_nights', function (Blueprint $table) {
            $table->foreignUuid('hold_id')->constrained('manual_inventory_holds')->restrictOnDelete();
            $table->foreignId('inventory_night_id')->constrained()->restrictOnDelete();
            $table->primary(['hold_id', 'inventory_night_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('manual_inventory_holds')->where('state', 'active')->exists()) {
            throw new RuntimeException('Release or expire active manual holds before rolling back their ledger.');
        }
        Schema::dropIfExists('manual_inventory_hold_nights');
        Schema::dropIfExists('manual_inventory_holds');
    }
};
