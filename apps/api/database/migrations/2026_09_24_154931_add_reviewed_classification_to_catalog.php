<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('name');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('hotels', fn (Blueprint $table) => $table->json('classification_draft')->nullable());
    }

    public function down(): void
    {
        Schema::table('hotels', fn (Blueprint $table) => $table->dropColumn('classification_draft'));
        Schema::dropIfExists('catalog_destinations');
    }
};
