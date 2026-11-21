<?php

namespace Tests\Feature;

use App\Models\FacilityDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityDirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_verified_published_facilities_are_public(): void
    {
        FacilityDirectory::create([
            'name' => 'Public Test Hospital', 'type' => 'hospital',
            'state' => 'FCT', 'source_name' => 'synthetic',
            'source_id' => 'public-1', 'review_status' => 'verified',
            'listing_status' => 'published',
        ]);
        FacilityDirectory::create([
            'name' => 'Private Test Pharmacy', 'type' => 'pharmacy',
            'state' => 'FCT', 'source_name' => 'synthetic',
            'source_id' => 'private-1', 'review_status' => 'pending',
            'listing_status' => 'draft',
        ]);
        $this->getJson('/api/directory/v1/facilities?state=FCT')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Public Test Hospital');
        $this->getJson('/api/directory/v1/facilities/2')->assertNotFound();
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->getJson('/api/directory/v1/facilities?state=Invalid')->assertStatus(422);
        $this->getJson('/api/directory/v1/facilities?per_page=1000')->assertStatus(422);
    }
}
