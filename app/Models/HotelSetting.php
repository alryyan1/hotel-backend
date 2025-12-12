<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelSetting extends Model
{
    protected $fillable = [
        'official_name',
        'logo_path',
        'stamp_path',
        'header_path',
        'footer_path',
        'address_line',
        'city',
        'phone',
        'phone_2',
        'email',
    ];
}
