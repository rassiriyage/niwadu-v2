<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingMigrationTest extends TestCase
{
    public function test_sqlite_booking_catalog_relationship_and_hold_ledger_roll_back_and_reapply(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->artisan('migrate:rollback', ['--path' => [
            'database/migrations/2026_09_24_153739_add_manual_source_to_booking_quotes.php',
            'database/migrations/2026_09_25_052256_create_manual_inventory_holds_tables.php',
        ], '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('manual_inventory_holds'));
        $this->assertFalse(Schema::hasColumn('booking_quotes', 'source_revision'));
        $this->assertTrue(Schema::hasTable('booking_quotes'));
        $this->assertTrue(Schema::hasTable('user_coverage'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('manual_inventory_hold_nights'));
        $this->assertTrue(Schema::hasColumn('booking_quotes', 'source_revision'));
    }
}
