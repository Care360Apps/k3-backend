<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'course';

    protected $fillable = [
        'title',
        'summary',
        'details',
        'image',
        'duration',
        'amount',
        'link',
    ];

    protected $appends = ['fullImage'];

    protected function getFullImageAttribute()
    {
        if ($this->image) {
            return url('images/upload').'/'.$this->image;
        }

        return url('images/upload_empty/defaultUser.png');
    }
}
