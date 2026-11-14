<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ClinicalCriticalResult extends Model {
 protected $guarded=['id'];
 protected $casts=['reason'=>'encrypted','contact_attempt'=>'encrypted','acknowledgement'=>'encrypted','acknowledged_at'=>'datetime'];
}
