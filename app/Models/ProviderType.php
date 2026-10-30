<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderType extends Model
{
    use HasFactory;

    protected $table = 'provider_types';

    protected $fillable = ['name', 'description', 'icon', 'status'];

    public function doctors()
    {
        return $this->hasMany(Doctor::class, 'provider_type_id');
    }
}

