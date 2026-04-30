<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = ['name'];

    public function reservationServices()
    {
        return $this->hasMany(ReservationService::class);
    }
}
