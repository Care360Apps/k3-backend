<?php

namespace Tests\Feature;

use App\Models\FacilityDirectory;
use Tests\Feature\Concerns\CreatesFacilityDirectorySchema;
use Tests\TestCase;

class FacilityDirectoryCsvImportTest extends TestCase
{
    use CreatesFacilityDirectorySchema;

    private function csv(string $body): string
    {
        $file = tempnam(sys_get_temp_dir(), 'facility-test-');
        file_put_contents($file, "type,name,state,address,latitude,longitude,source_name,source_id,source_url\n".$body);
        return $file;
    }

    public function test_dry_run_never_writes_and_commit_only_stages_drafts(): void
    {
        $file = $this->csv("hospital,Synthetic Clinic,FCT,Test Street,9.1,7.4,test-source,test-001,https://example.org/source\n");
        try {
            $this->artisan('facility-directory:import', ['file' => $file])->assertExitCode(0);
            $this->assertSame(0, FacilityDirectory::count());
            $this->artisan('facility-directory:import', ['file' => $file, '--commit' => true])->assertExitCode(0);
            $this->assertDatabaseHas('facility_directory', [
                'source_id' => 'test-001', 'review_status' => 'pending',
                'listing_status' => 'draft', 'partner_status' => 'none',
            ]);
            $this->artisan('facility-directory:import', ['file' => $file, '--commit' => true])->assertExitCode(0);
            $this->assertSame(1, FacilityDirectory::count());
            $this->getJson('/api/directory/v1/facilities')->assertOk()->assertJsonCount(0, 'data');
        } finally {
            unlink($file);
        }
    }

    public function test_invalid_source_rejects_entire_batch_without_partial_writes(): void
    {
        $file = $this->csv(
            "hospital,Valid Test,FCT,Test Street,9.1,7.4,test-source,valid-001,https://example.org/source\n".
            "pharmacy,Bad Test,FCT,Test Street,9.1,7.4,test-source,bad-001,http://example.org/source\n"
        );
        try {
            $this->artisan('facility-directory:import', ['file' => $file, '--commit' => true])->assertExitCode(1);
            $this->assertSame(0, FacilityDirectory::count());
        } finally {
            unlink($file);
        }
    }
}
