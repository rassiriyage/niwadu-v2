<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->uuid('client_key')->nullable();
            $table->string('creation_fingerprint', 64)->nullable();
            $table->unique(['hotel_id', 'client_key']);
        });
        Schema::create('catalog_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('onboarding_version');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->json('source_snapshot');
            $table->json('rooms');
            $table->timestamp('created_at');
        });
        $this->currencies(['LKR', 'USD']);
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->enum('meal_plan', ['RO', 'BB', 'HB', 'FB'])->nullable();
            $table->unique(['room_type_id', 'meal_plan', 'currency']);
        });
        Schema::table('rate_plan_nights', function (Blueprint $table) {
            foreach (['base_minor', 'tax_minor', 'fee_minor'] as $field) {
                $table->unsignedBigInteger($field)->nullable()->change();
            }
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE rate_plan_nights ADD CONSTRAINT complete_nightly_charges CHECK (NOT mandatory_charges_complete OR (base_minor IS NOT NULL AND tax_minor IS NOT NULL AND fee_minor IS NOT NULL))');
        }
        Schema::table('hotel_photos', fn (Blueprint $table) => $table->enum('gallery', ['property', 'room'])->default('property'));
        DB::table('hotel_photos')->whereIn('id', DB::table('room_type_photos')->select('hotel_photo_id'))->update(['gallery' => 'room']);
    }

    public function down(): void
    {
        if (DB::table('catalog_conversions')->exists() || DB::table('room_types')->whereNotNull('client_key')->exists() || DB::table('rate_plans')->where('currency', 'USD')->exists() || DB::table('rate_plans')->whereNotNull('meal_plan')->exists()
            || DB::table('hotel_photos')->where('gallery', 'room')->exists()
            || DB::table('rate_plan_nights')->whereNull('base_minor')->orWhereNull('tax_minor')->orWhereNull('fee_minor')->exists()) {
            throw new RuntimeException('Cannot remove configured meal plans, USD prices or room gallery ownership.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE rate_plan_nights DROP CONSTRAINT complete_nightly_charges');
        }
        Schema::table('rate_plan_nights', function (Blueprint $table) {
            foreach (['base_minor', 'tax_minor', 'fee_minor'] as $field) {
                $table->unsignedBigInteger($field)->nullable(false)->change();
            }
        });
        Schema::table('hotel_photos', fn (Blueprint $table) => $table->dropColumn('gallery'));
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropUnique(['room_type_id', 'meal_plan', 'currency']);
            $table->dropColumn('meal_plan');
        });
        $this->currencies(['LKR']);
        Schema::dropIfExists('catalog_conversions');
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropUnique(['hotel_id', 'client_key']);
            $table->dropColumn(['client_key', 'creation_fingerprint']);
        });
    }

    private function currencies(array $currencies): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_currency_check');
            DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_currency_check CHECK (currency IN ('".implode("','", $currencies)."'))");
        } else {
            Schema::table('rate_plans', fn (Blueprint $table) => $table->enum('currency', $currencies)->change());
        }
    }
};
