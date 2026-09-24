<?php

namespace Tests\Postgres;

use App\Models\HotelPhoto;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogMigrationTest extends TestCase
{
    public function test_catalog_migrations_roll_back_and_reapply_without_removing_existing_photos(): void
    {
        require dirname(__DIR__).'/postgres-bootstrap.php';

        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame($_ENV['DB_DATABASE'], config('database.connections.pgsql.database'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $photo = HotelPhoto::factory()->create();
        $this->assertTrue(Schema::hasColumn('hotels', 'discovery_snapshot'));
        $this->assertTrue(Schema::hasTable('room_types'));
        $this->assertTrue(Schema::hasTable('room_type_photos'));
        $this->artisan('migrate:rollback', ['--path' => [
            'database/migrations/2026_09_23_094644_create_room_types_table.php',
            'database/migrations/2026_09_23_094645_create_room_type_photos_table.php',
            'database/migrations/2026_09_24_074233_add_discovery_release_to_hotels_table.php',
        ], '--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('user_coverage'));
        $this->assertFalse(Schema::hasColumn('hotels', 'discovery_snapshot'));
        $this->assertFalse(Schema::hasTable('room_types'));
        $this->assertFalse(Schema::hasTable('room_type_photos'));
        $this->assertDatabaseHas('hotel_photos', ['id' => $photo->id]);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('hotels', 'discovery_snapshot'));
        $this->assertTrue(Schema::hasTable('room_types'));
        $this->assertTrue(Schema::hasTable('room_type_photos'));
        $this->assertDatabaseHas('hotel_photos', ['id' => $photo->id]);
    }
}
