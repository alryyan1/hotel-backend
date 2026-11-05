<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\WhatsAppNotificationNumber;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCloudApiService
{
    private string $baseUrl;
    private string $apiVersion;
    private ?string $phoneNumberId;
    private ?string $accessToken;
    private string $defaultCountryCode;

    public function __construct()
    {
        $config = config('services.whatsapp_cloud');

        $this->baseUrl = 'https://graph.facebook.com';
        $this->apiVersion = (string)($config['api_version'] ?? 'v21.0');
        $this->phoneNumberId = $config['phone_number_id'] ?? null;
        $this->accessToken = $config['access_token'] ?? null;
        $this->defaultCountryCode = (string)($config['default_country_code'] ?? '249');
    }

    public function isConfigured(): bool
    {
        return !empty($this->phoneNumberId) && !empty($this->accessToken);
    }

    /**
     * Fetch the display phone number and verified business name for the
     * configured WhatsApp sender number.
     *
     * @return array{success: bool, display_phone_number?: string, verified_name?: string, error?: string}
     */
    public function getSenderPhoneNumber(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'WhatsApp Cloud API service not configured.'];
        }

        $endpoint = "{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}";

        try {
            $response = Http::withToken($this->accessToken)
                ->get($endpoint, ['fields' => 'display_phone_number,verified_name']);

            $responseData = $response->json();

            if (!$response->successful() || !isset($responseData['display_phone_number'])) {
                $errorMessage = $responseData['error']['message'] ?? ('HTTP Status: ' . $response->status());
                Log::error("WhatsAppCloudApiService: Failed to fetch sender phone number. {$errorMessage}", [
                    'response' => $responseData,
                ]);
                return ['success' => false, 'error' => $errorMessage];
            }

            return [
                'success' => true,
                'display_phone_number' => $responseData['display_phone_number'],
                'verified_name' => $responseData['verified_name'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('WhatsAppCloudApiService getSenderPhoneNumber Exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendReservationConfirmationTemplate(Reservation $reservation): array
    {
        $customer = $reservation->customer;

        if (!$customer || !$customer->phone) {
            return ['success' => false, 'error' => 'Customer phone number not available', 'recipients' => []];
        }

        $bodyParams = [
            Carbon::parse($reservation->check_in_date)->format('d/m/Y'),
            Carbon::parse($reservation->check_out_date)->format('d/m/Y'),
            "#{$reservation->id}",
        ];

        $result = $this->sendTemplateMessage($customer->phone, 'booking_confirmation_branch1', 'ar', $bodyParams);
        $extraResults = $this->notifyEventNumbers('confirmation', 'booking_confirmation_branch1', 'ar', $bodyParams);

        $result['recipients'] = array_merge(
            [[
                'phone' => $customer->phone,
                'label' => 'العميل',
                'success' => (bool) $result['success'],
                'error' => $result['error'] ?? null,
            ]],
            $extraResults
        );

        return $result;
    }

    public function sendCheckoutReminderTemplate(Reservation $reservation): array
    {
        $customer = $reservation->customer;

        if (!$customer || !$customer->phone) {
            return ['success' => false, 'error' => 'Customer phone number not available'];
        }

        return $this->sendTemplateMessage(
            $customer->phone,
            'checkout_reminder__guest',
            'ar',
            [
                "#{$reservation->id}",
                Carbon::parse($reservation->check_out_date)->format('d/m/Y'),
            ]
        );
    }

    public function sendCancellationTemplate(Reservation $reservation): array
    {
        $customer = $reservation->customer;

        if (!$customer || !$customer->phone) {
            return ['success' => false, 'error' => 'Customer phone number not available'];
        }

        $bodyParams = [
            $customer->name,
            "#{$reservation->id}",
        ];

        $result = $this->sendTemplateMessage($customer->phone, 'booking_cancellation_guest', 'ar', $bodyParams);
        $extraResults = $this->notifyEventNumbers('cancellation', 'booking_cancellation_guest', 'ar', $bodyParams);

        $result['recipients'] = array_merge(
            [[
                'phone' => $customer->phone,
                'label' => 'العميل',
                'success' => (bool) $result['success'],
                'error' => $result['error'] ?? null,
            ]],
            $extraResults
        );

        return $result;
    }

    /**
     * Notify a room's cleaning staff (numbers configured in settings for the
     * "cleaning" event) that it needs cleaning after a checkout. Sent as a plain
     * text message, not a template — note this only delivers if the staff number
     * has messaged the business WhatsApp number within the last 24 hours (Meta's
     * customer-service session window); outside that window Meta rejects
     * business-initiated free text and a template would be required instead.
     */
    public function notifyCleaningCheckout(Room $room): array
    {
        $numbers = WhatsAppNotificationNumber::where('event', 'cleaning')->pluck('phone');

        if ($numbers->isEmpty()) {
            return ['success' => true, 'sent' => 0, 'results' => []];
        }

        $message = "🔔 *تنبيه نظافة للغرفة رقم {$room->number}*\n\n"
            . "يرجى إتمام أعمال نظافة الغرفة والتأكد من جاهزيتها.\n\n"
            . '*NOVA SUITES*';

        $results = [];
        foreach ($numbers as $phone) {
            $results[] = $this->sendTextMessage($phone, $message);
        }

        return [
            'success' => true,
            'sent' => count(array_filter($results, fn ($r) => $r['success'])),
            'results' => $results,
        ];
    }

    /**
     * Fan the same template out to the extra numbers configured in settings for
     * a given event (e.g. management getting a copy of every confirmation).
     * Failures here are logged but never affect the customer-facing send result.
     *
     * @param array<int, string> $bodyParams
     * @return array<int, array{phone: string, label: ?string, success: bool, error: ?string}>
     */
    private function notifyEventNumbers(string $event, string $templateName, string $languageCode, array $bodyParams): array
    {
        $numbers = WhatsAppNotificationNumber::where('event', $event)->get(['phone', 'label']);

        $results = [];
        foreach ($numbers as $number) {
            $result = $this->sendTemplateMessage($number->phone, $templateName, $languageCode, $bodyParams);
            $results[] = [
                'phone' => $number->phone,
                'label' => $number->label,
                'success' => (bool) $result['success'],
                'error' => $result['error'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Send a pre-approved WhatsApp template message via the Cloud API.
     *
     * @param string $to Recipient phone number (local or international format)
     * @param string $templateName Name of the approved template
     * @param string $languageCode Template language code (e.g. "ar")
     * @param array<int, string> $bodyParams Ordered values for the template's {{1}}, {{2}}, ... placeholders
     */
    public function sendTemplateMessage(string $to, string $templateName, string $languageCode, array $bodyParams = []): array
    {
        if (!$this->isConfigured()) {
            Log::error('WhatsAppCloudApiService: Service not configured (phone number ID or access token missing).');
            return ['success' => false, 'error' => 'WhatsApp Cloud API service not configured.', 'data' => null];
        }

        $formattedTo = self::formatPhoneNumber($to, $this->defaultCountryCode);

        if (!$formattedTo) {
            return ['success' => false, 'error' => 'Invalid recipient phone number.', 'data' => null];
        }

        $endpoint = "{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $formattedTo,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode],
            ],
        ];

        if (!empty($bodyParams)) {
            $payload['template']['components'] = [
                [
                    'type' => 'body',
                    'parameters' => array_map(
                        fn (string $value) => ['type' => 'text', 'text' => $value],
                        $bodyParams
                    ),
                ],
            ];
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->post($endpoint, $payload);

            return $this->handleResponse($response, "Template '{$templateName}'");
        } catch (\Exception $e) {
            Log::error('WhatsAppCloudApiService sendTemplateMessage Exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Upload a local file to WhatsApp's media store so it can be referenced by
     * id in a template/document message, without needing a public URL for it.
     *
     * @return array{success: bool, media_id?: string, error?: string}
     */
    public function uploadMedia(string $filePath, string $mimeType, string $filename): array
    {
        if (!$this->isConfigured()) {
            Log::error('WhatsAppCloudApiService: Service not configured (phone number ID or access token missing).');
            return ['success' => false, 'error' => 'WhatsApp Cloud API service not configured.'];
        }

        $endpoint = "{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/media";

        try {
            $response = Http::withToken($this->accessToken)
                ->attach('file', file_get_contents($filePath), $filename, ['Content-Type' => $mimeType])
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'type' => $mimeType,
                ]);

            $responseData = $response->json();

            if (!$response->successful() || !isset($responseData['id'])) {
                $errorMessage = $responseData['error']['message'] ?? ('HTTP Status: ' . $response->status());
                Log::error("WhatsAppCloudApiService: Failed to upload media. {$errorMessage}", ['response' => $responseData]);
                return ['success' => false, 'error' => $errorMessage];
            }

            return ['success' => true, 'media_id' => $responseData['id']];
        } catch (\Exception $e) {
            Log::error('WhatsAppCloudApiService uploadMedia Exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a pre-approved WhatsApp template message whose header is a document
     * (e.g. a PDF report) — the document must already be uploaded via
     * uploadMedia() so a media id is available.
     *
     * @param array<int, string> $bodyParams Ordered values for the template body's {{1}}, {{2}}, ... placeholders, if any
     */
    public function sendDocumentTemplateMessage(
        string $to,
        string $templateName,
        string $languageCode,
        string $mediaId,
        string $filename,
        array $bodyParams = []
    ): array {
        if (!$this->isConfigured()) {
            Log::error('WhatsAppCloudApiService: Service not configured (phone number ID or access token missing).');
            return ['success' => false, 'error' => 'WhatsApp Cloud API service not configured.', 'data' => null];
        }

        $formattedTo = self::formatPhoneNumber($to, $this->defaultCountryCode);

        if (!$formattedTo) {
            return ['success' => false, 'error' => 'Invalid recipient phone number.', 'data' => null];
        }

        $endpoint = "{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $components = [
            [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => 'document',
                        'document' => ['id' => $mediaId, 'filename' => $filename],
                    ],
                ],
            ],
        ];

        if (!empty($bodyParams)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value) => ['type' => 'text', 'text' => $value],
                    $bodyParams
                ),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $formattedTo,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode],
                'components' => $components,
            ],
        ];

        try {
            $response = Http::withToken($this->accessToken)
                ->post($endpoint, $payload);

            return $this->handleResponse($response, "Document template '{$templateName}'");
        } catch (\Exception $e) {
            Log::error('WhatsAppCloudApiService sendDocumentTemplateMessage Exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Send a free-text WhatsApp message via the Cloud API. Unlike a template,
     * this requires no prior Meta approval, but Meta only delivers
     * business-initiated free text within an open 24-hour customer-service
     * session (i.e. the recipient must have messaged the business number
     * recently) — outside that window the send is rejected and a template must
     * be used instead.
     */
    public function sendTextMessage(string $to, string $body): array
    {
        if (!$this->isConfigured()) {
            Log::error('WhatsAppCloudApiService: Service not configured (phone number ID or access token missing).');
            return ['success' => false, 'error' => 'WhatsApp Cloud API service not configured.', 'data' => null];
        }

        $formattedTo = self::formatPhoneNumber($to, $this->defaultCountryCode);

        if (!$formattedTo) {
            return ['success' => false, 'error' => 'Invalid recipient phone number.', 'data' => null];
        }

        $endpoint = "{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $formattedTo,
            'type' => 'text',
            'text' => ['body' => $body],
        ];

        try {
            $response = Http::withToken($this->accessToken)
                ->post($endpoint, $payload);

            return $this->handleResponse($response, 'Text message');
        } catch (\Exception $e) {
            Log::error('WhatsAppCloudApiService sendTextMessage Exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    private function handleResponse(Response $response, string $actionDescription): array
    {
        $responseData = $response->json();

        if ($response->successful() && isset($responseData['messages'][0]['id'])) {
            Log::info("WhatsAppCloudApiService: {$actionDescription} sent successfully.", [
                'response' => $responseData,
            ]);

            return [
                'success' => true,
                'data' => $responseData,
                'message_id' => $responseData['messages'][0]['id'],
            ];
        }

        $errorMessage = "Failed to send {$actionDescription}.";

        if (isset($responseData['error']['message'])) {
            $errorMessage .= ' Error: ' . $responseData['error']['message'];
        } elseif (!$response->successful()) {
            $errorMessage .= ' HTTP Status: ' . $response->status();
        }

        Log::error("WhatsAppCloudApiService: {$errorMessage}", [
            'response' => $responseData,
            'status_code' => $response->status(),
        ]);

        return ['success' => false, 'error' => $errorMessage, 'data' => $responseData];
    }

    /**
     * Format a phone number for the WhatsApp Cloud API: digits only, no leading "+" or "0",
     * prefixed with the default country code when missing.
     */
    public static function formatPhoneNumber(string $phoneNumber, string $defaultCountryCode = '249'): ?string
    {
        if (empty(trim($phoneNumber))) {
            return null;
        }

        $cleanedNumber = preg_replace('/[^\d]/', '', $phoneNumber);

        if (str_starts_with($cleanedNumber, '0')) {
            $cleanedNumber = substr($cleanedNumber, 1);
        }

        if (!str_starts_with($cleanedNumber, $defaultCountryCode)) {
            $cleanedNumber = $defaultCountryCode . $cleanedNumber;
        }

        if (strlen($cleanedNumber) < 10 || strlen($cleanedNumber) > 15) {
            Log::warning("WhatsAppCloudApiService: Potentially invalid phone number format: {$phoneNumber} -> {$cleanedNumber}");
        }

        return $cleanedNumber;
    }
}
