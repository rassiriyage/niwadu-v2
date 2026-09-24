<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('discovery_slug', 120)->nullable()->unique();
            $table->json('discovery_snapshot')->nullable();
            $table->unsignedInteger('discovery_version')->default(0);
            $table->foreignId('discovery_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('discovery_approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropForeign(['discovery_approved_by']);
            $table->dropUnique(['discovery_slug']);
            $table->dropColumn(['discovery_slug', 'discovery_snapshot', 'discovery_version', 'discovery_approved_by', 'discovery_approved_at']);
        });
    }
};
