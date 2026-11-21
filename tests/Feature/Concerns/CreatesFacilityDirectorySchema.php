<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * Facility-only SQLite fixtures. Legacy migrations depend on tables not present
 * in an empty test database; this suite must not execute unrelated migrations.
 */
trait CreatesFacilityDirectorySchema
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('facility_directory_reviews');
        Schema::dropIfExists('facility_directory');

        $directory = require database_path('migrations/2026_11_21_000001_create_facility_directory_table.php');
        $directory->up();
        $reviews = require database_path('migrations/2026_11_21_000002_create_facility_directory_reviews.php');
        $reviews->up();
    }
}
