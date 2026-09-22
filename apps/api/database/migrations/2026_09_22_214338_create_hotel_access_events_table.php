<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->string('role')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_access_events');
    }
};
