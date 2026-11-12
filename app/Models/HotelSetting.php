<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $official_name
 * @property string|null $branch_name
 * @property string|null $trade_name
 * @property string|null $logo_path
 * @property string|null $stamp_path
 * @property string|null $header_path
 * @property string|null $footer_path
 * @property string|null $address_line
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $country
 * @property string|null $phone
 * @property string|null $phone_2
 * @property string|null $email
 * @property string|null $website
 * @property string|null $cancellation_policy
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereAddressLine($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereCancellationPolicy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereFooterPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereHeaderPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereLogoPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereOfficialName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting wherePhone2($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting wherePostalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereStampPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereTradeName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HotelSetting whereWebsite($value)
 * @mixin \Eloquent
 */
class HotelSetting extends Model
{
    protected $fillable = [
        'official_name',
        'branch_name',
        'logo_path',
        'stamp_path',
        'header_path',
        'footer_path',
        'address_line',
        'city',
        'phone',
        'phone_2',
        'email',
        'bank_name_1',
        'bank_account_number_1',
        'bank_account_name_1',
        'bank_name_2',
        'bank_account_number_2',
        'bank_account_name_2',
        'sms_notifications_enabled',
        'whatsapp_notifications_enabled',
        'allow_checkin_outside_arrival_date',
        'whatsapp_confirmation_template',
    ];

    protected $casts = [
        'sms_notifications_enabled' => 'boolean',
        'whatsapp_notifications_enabled' => 'boolean',
        'allow_checkin_outside_arrival_date' => 'boolean',
    ];
}
