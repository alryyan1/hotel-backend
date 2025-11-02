# SMS Configuration for Reservation Notifications

## Environment Variables

Add the following variables to your `.env` file:

```env
# Airtel SMS Service Configuration
AIRTEL_SMS_BASE_URL=https://www.airtel.sd
AIRTEL_SMS_ENDPOINT=/api/rest_send_sms/
AIRTEL_SMS_API_KEY=your_api_key_here
AIRTEL_SMS_SENDER=Jawda
AIRTEL_SMS_TIMEOUT=10
```

## How It Works

1. When a reservation is created via the API, an SMS is automatically sent to the customer's phone number
2. When a reservation is confirmed via the `/confirm` endpoint, an SMS is also sent
3. The SMS message includes:
   - Hotel name (from HotelSetting model)
   - Reservation ID
   - Check-in date
   - Check-out date

## SMS Message Format

The SMS message sent to customers is in Arabic:

```
عزيزي العميل،
تم تأكيد حجزك بنجاح في فندق [اسم الفندق].
رقم الحجز: [رقم الحجز]
تاريخ الوصول: [تاريخ الوصول]
تاريخ المغادرة: [تاريخ المغادرة]
نحن بانتظارك ونتمنى لك إقامة ممتعة. 🌿
```

## Error Handling

- SMS failures are logged but don't prevent reservation creation
- Check Laravel logs for SMS-related errors
- Ensure customer has a valid phone number in international format

## Testing

To test the SMS functionality:

1. Ensure you have valid Airtel SMS API credentials
2. Create a reservation with a customer that has a phone number
3. Check the Laravel logs for SMS success/failure messages
4. Verify the SMS is received on the customer's phone




