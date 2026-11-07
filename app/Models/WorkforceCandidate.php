<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkforceCandidate extends Model
{
    protected $table = 'workforce_candidates';

    protected $fillable = [
        'account_id', 'career_stage', 'institution', 'qualification',
        'desired_role', 'state', 'city', 'shift_preference',
        'available_for_work', 'consent_to_list',
    ];

    protected $casts = [
        'available_for_work' => 'boolean', 'consent_to_list' => 'boolean',
        'licence_expires_at' => 'date',
    ];

    public function account()
    {
        return $this->belongsTo(WorkforceAccount::class, 'account_id');
    }
}
