<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_photos', function (Blueprint $table) {
            $table->unique(['hotel_id', 'id']);
        });
        Schema::create('room_type_photos', function (Blueprint $table) {
            $table->foreignId('hotel_id');
            $table->foreignId('room_type_id');
            $table->foreignId('hotel_photo_id');
            $table->unsignedSmallInteger('position');
            $table->primary(['room_type_id', 'hotel_photo_id']);
            $table->unique(['room_type_id', 'position']);
            $table->foreign(['hotel_id', 'room_type_id'])->references(['hotel_id', 'id'])->on('room_types')->cascadeOnDelete();
            $table->foreign(['hotel_id', 'hotel_photo_id'])->references(['hotel_id', 'id'])->on('hotel_photos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_photos');
        Schema::table('hotel_photos', function (Blueprint $table) {
            $table->dropUnique(['hotel_id', 'id']);
        });
    }
};
