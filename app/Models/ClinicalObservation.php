<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ClinicalObservation extends Model {
 protected $guarded=['id'];
 protected $casts=['payload'=>'encrypted:array','observed_at'=>'datetime'];
}
