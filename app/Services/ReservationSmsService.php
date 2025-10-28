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

    /**
     * Send reservation creation SMS to customer
     */
    public function sendReservationConfirmation(Reservation $reservation): array
    {
        $customer = $reservation->customer;
        $hotelSettings = HotelSetting::first();
        
        if (!$customer || !$customer->phone) {
            return [
                'success' => false,
                'error' => 'Customer phone number not available'
            ];
        }

        $hotelName = $hotelSettings?->official_name ?? $hotelSettings?->trade_name ?? 'فندقنا';
        $reservationId = $reservation->id;
        $checkInDate = Carbon::parse($reservation->check_in_date)->format('Y-m-d');
        $checkOutDate = Carbon::parse($reservation->check_out_date)->format('Y-m-d');

        $message = $this->buildReservationCreationMessage(
            $hotelName,
            $reservationId,
            $checkInDate,
            $checkOutDate
        );

        return $this->smsClient->send($customer->phone, $message);
    }

    /**
     * Send reservation confirmation SMS to customer
     */
    public function sendReservationConfirmationSms(Reservation $reservation): array
    {
        $customer = $reservation->customer;
        $hotelSettings = HotelSetting::first();
        
        if (!$customer || !$customer->phone) {
            return [
                'success' => false,
                'error' => 'Customer phone number not available'
            ];
        }

        $hotelName = $hotelSettings?->official_name ?? $hotelSettings?->trade_name ?? 'فندقنا';
        $reservationId = $reservation->id;
        $checkInDate = Carbon::parse($reservation->check_in_date)->format('Y-m-d');
        $checkOutDate = Carbon::parse($reservation->check_out_date)->format('Y-m-d');

        $message = $this->buildReservationConfirmationMessage(
            $hotelName,
            $reservationId,
            $checkInDate,
            $checkOutDate
        );

        return $this->smsClient->send($customer->phone, $message);
    }

    /**
     * Build the reservation creation message in Arabic
     */
    private function buildReservationCreationMessage(
        string $hotelName,
        int $reservationId,
        string $checkInDate,
        string $checkOutDate
    ): string {
        return "مرحبًا بك في فندق {$hotelName} 🌿
تم تسجيل حجزك بنجاح، ويسعدنا اختيارك لنا.
رقم الحجز: {$reservationId}
تاريخ الوصول: {$checkInDate}
تاريخ المغادرة: {$checkOutDate}
نتمنى لك إقامة مريحة وتجربة رائعة معنا.";
    }

    /**
     * Build the reservation confirmation message in Arabic
     */
    private function buildReservationConfirmationMessage(
        string $hotelName,
        int $reservationId,
        string $checkInDate,
        string $checkOutDate
    ): string {
        return "تم تأكيد حجزك بنجاح في فندق {$hotelName}.
رقم الحجز: {$reservationId}
تاريخ الوصول: {$checkInDate}
تاريخ المغادرة: {$checkOutDate}
نحن بانتظارك ونتمنى لك إقامة ممتعة. 🌿";
    }

    /**
     * Send bulk reservation confirmations
     */
    public function sendBulkReservationConfirmations(array $reservations): array
    {
        $messages = [];
        
        foreach ($reservations as $reservation) {
            if ($reservation instanceof Reservation && $reservation->customer?->phone) {
                $hotelSettings = HotelSetting::first();
                $hotelName = $hotelSettings?->official_name ?? $hotelSettings?->trade_name ?? 'فندقنا';
                $reservationId = $reservation->id;
                $checkInDate = Carbon::parse($reservation->check_in_date)->format('Y-m-d');
                $checkOutDate = Carbon::parse($reservation->check_out_date)->format('Y-m-d');

                $message = $this->buildReservationConfirmationMessage(
                    $hotelName,
                    $reservationId,
                    $checkInDate,
                    $checkOutDate
                );

                $messages[] = [
                    'to' => $reservation->customer->phone,
                    'message' => $message
                ];
            }
        }

        if (empty($messages)) {
            return [
                'success' => false,
                'error' => 'No valid reservations with phone numbers found'
            ];
        }

        return $this->smsClient->sendBulk($messages);
    }
}
