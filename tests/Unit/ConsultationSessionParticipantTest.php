<?php

namespace Tests\Unit;

use App\Models\ConsultationSession;
use PHPUnit\Framework\TestCase;

final class ConsultationSessionParticipantTest extends TestCase
{
    public function test_only_distinct_participants_receive_uids(): void
    {
        $session = new ConsultationSession(['patient_user_id' => 10, 'doctor_user_id' => 20]);
        self::assertSame(1, $session->participantUid(10));
        self::assertSame(2, $session->participantUid(20));
        self::assertNull($session->participantUid(30));
        self::assertNull($session->participantUid(0));
        $session->doctor_user_id = 10;
        self::assertNull($session->participantUid(10));
    }
}
