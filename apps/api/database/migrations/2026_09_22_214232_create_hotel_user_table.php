<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['hotel_manager', 'reservations', 'inventory_manager', 'viewer']);
            $table->timestamps();
            $table->unique(['hotel_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_user');
    }
};
