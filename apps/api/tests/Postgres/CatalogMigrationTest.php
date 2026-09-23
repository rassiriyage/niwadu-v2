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
        $this->assertTrue(Schema::hasTable('room_types'));
        $this->assertTrue(Schema::hasTable('room_type_photos'));
        $this->artisan('migrate:rollback', ['--step' => 2, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('room_types'));
        $this->assertFalse(Schema::hasTable('room_type_photos'));
        $this->assertDatabaseHas('hotel_photos', ['id' => $photo->id]);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('room_types'));
        $this->assertTrue(Schema::hasTable('room_type_photos'));
        $this->assertDatabaseHas('hotel_photos', ['id' => $photo->id]);
    }
}
