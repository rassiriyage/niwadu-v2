<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pms_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('provider', 50);
            $table->string('environment', 20)->default('sandbox');
            $table->string('endpoint_origin', 2048);
            $table->string('external_property_id', 255);
            $table->text('credentials');
            $table->string('inventory_mode', 20)->default('pms');
            $table->boolean('enabled')->default(false);
            $table->json('capabilities')->nullable();
            $table->json('freshness')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'provider', 'enabled']);
            $table->index(['provider', 'environment']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('pms_connections');
    }
};
