<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Barangay extends Model
{
    protected $fillable = ['psgc_code', 'city_municipality_id', 'name'];

    public function city() { return $this->belongsTo(CityMunicipality::class, 'city_municipality_id'); }
}
