<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkforceEmployer extends Model
{
    protected $table = 'workforce_employers';

    protected $fillable = [
        'account_id', 'organization_name', 'organization_type',
        'registration_number', 'state',
    ];

    public function account()
    {
        return $this->belongsTo(WorkforceAccount::class, 'account_id');
    }
}
