<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->json('onboarding_data')->nullable();
            $table->unsignedInteger('onboarding_version')->default(0);
            $table->unsignedTinyInteger('onboarding_step')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('hotels', fn (Blueprint $table) => $table->dropColumn(['onboarding_data', 'onboarding_version', 'onboarding_step']));
    }
};
