<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Doctor extends Model
{
    use HasFactory;

    protected $table = 'doctor';

    protected $fillable = [
        'name',
        'is_filled',
        'treatment_id',
        'category_id',
        'custom_timeslot',
        'dob',
        'gender',
        'expertise_id',
        'timeslot',
        'start_time',
        'end_time',
        'hospital_id',
        'address',
        'lat',
        'lng',
        'image',
        'user_id',
        'user_role',
        'provider_type_id',
        'desc',
        'education',
        'certificate',
        'appointment_fees',
        'hourly_rate',
        'experience',
        'since',
        'status',
        'is_approved',
        'based_on',
        'commission_amount',
        'is_popular',
        'subscription_status',
        'language',
        'offers_video_call',
        'offers_home_visit',
        'service_catalog',
    ];

    /**
     * Fields doctors/providers may change on their own profile. Approval, status, commission, subscription,
     * popularity and ownership fields are admin-only.
     */
    public const SELF_EDITABLE = [
        'name',
        'treatment_id',
        'category_id',
        'custom_timeslot',
        'dob',
        'gender',
        'expertise_id',
        'timeslot',
        'start_time',
        'end_time',
        'hospital_id',
        'address',
        'lat',
        'lng',
        'desc',
        'education',
        'certificate',
        'appointment_fees',
        'hourly_rate',
        'experience',
        'since',
        'language',
        'offers_video_call',
        'offers_home_visit',
        'service_catalog',
    ];

    protected $appends = ['fullImage', 'rate', 'review'];

    protected function getFullImageAttribute()
    {
        return url('images/upload').'/'.$this->image;
    }

    public function expertise()
    {
        return $this->belongsTo('App\Models\Expertise');
    }

    public function treatment()
    {
        return $this->belongsTo('App\Models\Treatments');
    }

    public function category()
    {
        return $this->belongsTo('App\Models\Category');
    }

    public function providerType()
    {
        return $this->belongsTo(ProviderType::class, 'provider_type_id');
    }

    public function documents()
    {
        return $this->hasMany(DoctorDocument::class);
    }

    protected static function booted()
    {
        static::deleting(function (Doctor $doctor) {
            $doctor->documents->each->delete();
        });
    }

    public function DoctorSubscription()
    {
        return $this->hasOne('App\Models\DoctorSubscription');
    }

    public function Doctor()
    {
        return $this->hasOne('App\Models\Doctor');
    }

    public function getRateAttribute()
    {
        $review = Review::where('doctor_id', $this->attributes['id'])->get();
        if (count($review) > 0) {
            $totalRate = 0;
            foreach ($review as $r) {
                $totalRate = $totalRate + $r->rate;
            }

            return round($totalRate / count($review), 1);
        } else {
            return 0;
        }
    }

    public function getReviewAttribute()
    {
        return Review::where('doctor_id', $this->attributes['id'])->count();
    }
}
