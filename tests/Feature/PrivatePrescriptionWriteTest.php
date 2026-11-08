<?php

namespace Tests\Feature;

use App\Services\PrivatePrescriptionStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PrivatePrescriptionWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing')) {
            $this->markTestSkipped('Never run clinical storage tests outside testing.');
        }
    }

    private function syntheticUpload(string $payload): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'k3-test-pdf-');
        file_put_contents($temp, $payload);

        return new UploadedFile(
            $temp,
            'synthetic-test.pdf',
            'application/pdf',
            null,
            true
        );
    }

    public function test_links_are_resolved_per_document_regardless_of_flag(): void
    {
        $private = 'k3priv_'.str_repeat('a', 40).'.pdf';
        $legacy = str_repeat('b', 32).'.pdf';

        foreach ([false, true] as $flag) {
            config()->set('medical_files.private_prescription_writes', $flag);
            $this->assertSame($flag, PrivatePrescriptionStorage::enabled());
            $this->assertSame('', PrivatePrescriptionStorage::appointmentUrl(10, null));
            $this->assertTrue(PrivatePrescriptionStorage::isPrivateFilename($private));
            $this->assertFalse(PrivatePrescriptionStorage::isPrivateFilename($legacy));
            $this->assertStringEndsWith(
                '/api/medical-files/appointments/10/prescription',
                PrivatePrescriptionStorage::appointmentUrl(10, $private)
            );
            $this->assertStringEndsWith(
                '/prescription/upload/'.$legacy,
                PrivatePrescriptionStorage::appointmentUrl(10, $legacy)
            );
            $this->assertSame(
                '',
                PrivatePrescriptionStorage::appointmentUrl(10, '../unauthorized.pdf')
            );
            $this->assertSame(
                '',
                PrivatePrescriptionStorage::appointmentUrl(10, 'k3priv_not-a-valid-hex.pdf')
            );
            $this->assertSame(
                '',
                PrivatePrescriptionStorage::appointmentUrl(10, 'https://external.test/evil.pdf')
            );
        }
    }

    public function test_synthetic_pdf_is_written_privately_and_cleaned_up(): void
    {
        $body = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
        $storage = new PrivatePrescriptionStorage();
        $filename = $storage->store($this->syntheticUpload($body));
        $path = storage_path('app/private/medical/prescriptions/'.$filename);
        try {
            $this->assertMatchesRegularExpression('/^k3priv_[a-f0-9]{40}\.pdf$/D', $filename);
            $this->assertFileExists($path);
            $this->assertSame($body, file_get_contents($path));
            if (DIRECTORY_SEPARATOR !== '\\') {
                $this->assertSame(0600, fileperms($path) & 0777);
            }
        } finally {
            $storage->discard($filename);
        }
        $this->assertFileDoesNotExist($path);
    }

    public function test_fake_pdf_with_untrusted_contents_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new PrivatePrescriptionStorage())->store(
            $this->syntheticUpload('not actually a PDF')
        );
    }
}
