<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkforceAccount extends Model
{
    protected $table = 'workforce_accounts';

    protected $fillable = ['name', 'email', 'password', 'account_type'];

    protected $hidden = ['password'];

    protected $casts = ['email_verified_at' => 'datetime', 'is_active' => 'boolean'];

    public function candidate()
    {
        return $this->hasOne(WorkforceCandidate::class, 'account_id');
    }

    public function employer()
    {
        return $this->hasOne(WorkforceEmployer::class, 'account_id');
    }
}
