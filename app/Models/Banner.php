<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;

    protected $table = 'banner';

    protected $fillable = ['image', 'link', 'status', 'name', 'description', 'is_sponsored', 'starts_at', 'ends_at'];

    protected $casts = [
        'is_sponsored' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected $appends = ['fullImage'];

    protected function getFullImageAttribute()
    {
        return url('api/banner-images/'.$this->id).'?v='.rawurlencode((string) $this->image);
    }
}
