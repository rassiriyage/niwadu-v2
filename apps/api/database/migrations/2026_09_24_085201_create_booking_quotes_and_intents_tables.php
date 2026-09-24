<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('source', 32);
            $table->json('snapshot');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['id', 'user_id', 'hotel_id']);
        });
        Schema::create('booking_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_quote_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->timestampsTz();
            $table->unique(['user_id', 'idempotency_key']);
            $table->foreign(['booking_quote_id', 'user_id', 'hotel_id'])
                ->references(['id', 'user_id', 'hotel_id'])->on('booking_quotes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_intents');
        Schema::dropIfExists('booking_quotes');
    }
};
