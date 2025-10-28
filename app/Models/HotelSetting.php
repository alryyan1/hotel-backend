<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelSetting extends Model
{
    protected $fillable = [
        'official_name',
        'trade_name',
        'logo_path',
        'address_line',
        'city',
        'postal_code',
        'country',
        'phone',
        'email',
        'website',
        'cancellation_policy',
    ];
}
