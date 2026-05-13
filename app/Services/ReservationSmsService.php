<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\HotelSetting;
use App\Services\Contracts\SmsClient;
use Carbon\Carbon;

class ReservationSmsService
{
    private SmsClient $smsClient;

    public function __construct(SmsClient $smsClient)
    {
        $this->smsClient = $smsClient;
    }

    public function sendReservationConfirmation(Reservation $reservation): array
    {
        $customer = $reservation->customer;

        if (!$customer || !$customer->phone) {
            return ['success' => false, 'error' => 'Customer phone number not available'];
        }

        $message = $this->buildMessage(
            $reservation->id,
            Carbon::parse($reservation->check_in_date)->format('d/m/Y'),
            Carbon::parse($reservation->check_out_date)->format('d/m/Y')
        );

        return $this->smsClient->send($customer->phone, $message);
    }

    public function sendReservationConfirmationSms(Reservation $reservation): array
    {
        $customer = $reservation->customer;

        if (!$customer || !$customer->phone) {
            return ['success' => false, 'error' => 'Customer phone number not available'];
        }

        $message = $this->buildMessage(
            $reservation->id,
            Carbon::parse($reservation->check_in_date)->format('d/m/Y'),
            Carbon::parse($reservation->check_out_date)->format('d/m/Y')
        );

        return $this->smsClient->send($customer->phone, $message);
    }

    public function sendBulkReservationConfirmations(array $reservations): array
    {
        $messages = [];

        foreach ($reservations as $reservation) {
            if ($reservation instanceof Reservation && $reservation->customer?->phone) {
                $messages[] = [
                    'to' => $reservation->customer->phone,
                    'message' => $this->buildMessage(
                        $reservation->id,
                        Carbon::parse($reservation->check_in_date)->format('d/m/Y'),
                        Carbon::parse($reservation->check_out_date)->format('d/m/Y')
                    ),
                ];
            }
        }

        if (empty($messages)) {
            return ['success' => false, 'error' => 'No valid reservations with phone numbers found'];
        }

        return $this->smsClient->sendBulk($messages);
    }

    private function buildMessage(int $reservationId, string $checkInDate, string $checkOutDate): string
    {
        $mapLink = "https://maps.app.goo.gl/jYAhKEEzNXbUPSya8?g_st=aw";
        $alrawdaMapLink = "https://maps.app.goo.gl/7JiTnwK2gPbSXXEd6?g_st=aw";

        return "تاكيد استضافة | NOVA SUITES
الى ضيفنا الموقر.. بكل حفاوة، نؤكد جاهزية نوفا سويتس لاستقبالكم. نحن هنا لنصنع لكم اقامة تليق بمقامكم، حيث تتجسد الرفاهية والخصوصية في ابهى صورها.

بيانات التشريف:
تاريخ الوصول: {$checkInDate}
تاريخ المغادرة: {$checkOutDate}
الحجز رقم: #{$reservationId}

وجهتكم المختارة:
فرع النسمة (اطلالة النيل):
{$mapLink}
فرع الروضة (القلب النابض):
{$alrawdaMapLink}

في نوفا سويتس.. لا نكتفي بالاستضافة، بل نصمم لكم ذكريات لا تنسى. نتطلع لاستقبالكم بكل مودة";
    }
}
