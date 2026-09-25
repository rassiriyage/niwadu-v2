<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', fn (Blueprint $table) => $table->unsignedInteger('property_gallery_version')->default(0));
        Schema::table('hotel_photos', fn (Blueprint $table) => $table->unsignedInteger('position')->nullable());
        foreach (DB::table('hotel_photos')->where('gallery', 'property')->select('hotel_id')->distinct()->pluck('hotel_id') as $hotel) {
            foreach (DB::table('hotel_photos')->where('hotel_id', $hotel)->where('gallery', 'property')->orderBy('id')->pluck('id') as $position => $id) {
                DB::table('hotel_photos')->where('id', $id)->update(['position' => $position]);
            }
        }
        Schema::table('hotel_photos', fn (Blueprint $table) => $table->unique(['hotel_id', 'gallery', 'position']));
    }

    public function down(): void
    {
        if (DB::table('hotels')->where('property_gallery_version', '>', 0)->exists()) {
            throw new RuntimeException('Cannot discard edited property gallery order or version.');
        }
        Schema::table('hotel_photos', function (Blueprint $table) {
            $table->dropUnique(['hotel_id', 'gallery', 'position']);
            $table->dropColumn('position');
        });
        Schema::table('hotels', fn (Blueprint $table) => $table->dropColumn('property_gallery_version'));
    }
};
