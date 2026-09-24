<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_quotes', function (Blueprint $table) {
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->unsignedBigInteger('rate_plan_id')->nullable();
            $table->json('source_revision')->nullable();
            $table->foreign(['hotel_id', 'room_type_id', 'rate_plan_id'], 'booking_quote_catalog_fk')
                ->references(['hotel_id', 'room_type_id', 'id'])->on('rate_plans')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('booking_quotes', function (Blueprint $table) {
            $table->dropForeign('booking_quote_catalog_fk');
            $table->dropColumn(['room_type_id', 'rate_plan_id', 'source_revision']);
        });
    }
};
