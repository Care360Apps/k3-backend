<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ConsultationSession extends Model
{
    protected $fillable = [
        'appointment_id', 'channel', 'patient_user_id', 'doctor_user_id',
        'expires_at', 'ended_at',
    ];

    protected $hidden = ['channel', 'patient_user_id', 'doctor_user_id'];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
    ];

    public function lifecycleState(): string
    {
        if ($this->ended_at !== null) {
            return 'ended';
        }
        return $this->expires_at->timestamp <= now()->timestamp ? 'expired' : 'active';
    }

    public function participantUid(int $userId): ?int
    {
        if ($userId <= 0 || (int) $this->patient_user_id === (int) $this->doctor_user_id) {
            return null;
        }
        return match ($userId) {
            (int) $this->patient_user_id => 1,
            (int) $this->doctor_user_id => 2,
            default => null,
        };
    }
}
