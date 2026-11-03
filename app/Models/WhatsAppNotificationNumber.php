<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppNotificationNumber extends Model
{
    protected $table = 'whatsapp_notification_numbers';

    protected $fillable = [
        'event',
        'phone',
        'label',
    ];
}
