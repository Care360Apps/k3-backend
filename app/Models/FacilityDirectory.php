<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityDirectory extends Model
{
    protected $table = 'facility_directory';

    protected $guarded = ['id'];

    protected $casts = ['last_verified_at' => 'datetime'];

    public function scopePublished($query)
    {
        return $query->where('listing_status', 'published')
            ->where('review_status', 'verified');
    }
}
