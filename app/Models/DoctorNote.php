<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A private clinician-authored encounter note (not automatically shared). */
class DoctorNote extends Model
{
    protected $table = 'doctor_notes';

    protected $fillable = [
        'doctor_id', 'user_id', 'appointment_id', 'title', 'body',
        'status', 'finalized_at',
    ];

    protected $casts = [
        'title' => 'encrypted',
        'body' => 'encrypted',
        'finalized_at' => 'datetime',
    ];
}
