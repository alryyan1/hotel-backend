<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContestParticipant extends Model
{
    
    protected $fillable = ['full_name', 'phone_number', 'address','has_won'];
}