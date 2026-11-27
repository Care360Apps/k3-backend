<?php

namespace Tests\Feature;

use App\Models\Appointment;
use Tests\TestCase;

class AppointmentReportMetadataTest extends TestCase
{
    public function test_invalid_report_metadata_returns_empty_array(): void
    {
        foreach (['not-json', 'null', '123', '"file.png"'] as $raw) {
            $appointment = new Appointment;
            $appointment->setRawAttributes(['report_image' => $raw]);
            $this->assertSame([], $appointment->report_image);
        }
    }

    public function test_only_safe_legacy_filenames_are_returned(): void
    {
        $appointment = new Appointment;
        $appointment->setRawAttributes([
            'report_image' => json_encode(['report-123.png', '../secret.png', 'a/b.png', '..', '.', '', null, 123, 'a?b.png']),
        ]);
        $this->assertSame([url('images/upload').'/report-123.png'], $appointment->report_image);
    }
}
