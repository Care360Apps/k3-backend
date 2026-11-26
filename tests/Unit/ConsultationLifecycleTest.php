<?php

namespace Tests\Unit;

use App\Models\ConsultationSession;
use Tests\TestCase;

final class ConsultationLifecycleTest extends TestCase
{
    public function test_active_and_expired_sessions(): void
    {
        $session = new ConsultationSession(['expires_at' => now()->addMinute()]);
        $this->assertSame('active', $session->lifecycleState());
        $session->expires_at = now()->subSecond();
        $this->assertSame('expired', $session->lifecycleState());
    }

    public function test_ended_session_takes_precedence(): void
    {
        $session = new ConsultationSession([
            'expires_at' => now()->addMinute(),
            'ended_at' => now(),
        ]);
        $this->assertSame('ended', $session->lifecycleState());
    }
}
