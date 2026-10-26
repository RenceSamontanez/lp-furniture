<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CityMunicipality extends Model
{
    protected $table = 'cities_municipalities';

    protected $fillable = ['psgc_code', 'province_id', 'name'];

    public function province()  { return $this->belongsTo(Province::class); }
    public function barangays() { return $this->hasMany(Barangay::class, 'city_municipality_id'); }
}
