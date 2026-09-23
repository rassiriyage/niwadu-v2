<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('max_occupancy');
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['hotel_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
