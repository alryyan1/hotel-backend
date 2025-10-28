<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;

class UltramsgService
{
    protected string $baseUrl;
    protected ?string $instanceId;
    protected ?string $token;
    protected string $defaultCountryCode;

    public function __construct()
    {
        $appSettings = Setting::first();
        
        $this->baseUrl = $appSettings?->ultramsg_base_url ?? 'https://api.ultramsg.com';
        $this->instanceId = $appSettings?->ultramsg_instance_id;
        $this->token = $appSettings?->ultramsg_token;
        $this->defaultCountryCode = $appSettings?->ultramsg_default_country_code ?? '249';
    }

    /**
     * Check if the Ultramsg service is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->instanceId) && !empty($this->token) && !empty($this->baseUrl);
    }

    /**
     * Get the configured instance ID.
     */
    public function getInstanceId(): ?string
    {
        return $this->instanceId;
    }

    /**
     * Get the configured token.
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * Send a text message via Ultramsg WhatsApp API.
     *
     * @param string $to Phone number with international format (e.g., +249991961111)
     * @param string $body Message text (max 4096 characters)
     * @return array{success: bool, data: mixed, error?: string}
     */
    public function sendTextMessage(string $to, string $body): array
    {
        if (!$this->isConfigured()) {
            Log::error('UltramsgService: Service not configured (Instance ID or Token missing).');
            return ['success' => false, 'error' => 'Ultramsg service not configured.', 'data' => null];
        }

        // Validate message length
        if (strlen($body) > 4096) {
            Log::error('UltramsgService: Message too long. Max length is 4096 characters.');
            return ['success' => false, 'error' => 'Message too long. Maximum 4096 characters allowed.', 'data' => null];
        }

        $endpoint = "{$this->baseUrl}/{$this->instanceId}/messages/chat";

        try {
            $response = Http::asForm()
                ->post($endpoint, [
                    'token' => $this->token,
                    'to' => $to,
                    'body' => $body,
                ]);

            return $this->handleResponse($response, 'Text message');

        } catch (\Exception $e) {
            Log::error("UltramsgService sendTextMessage Exception: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Send a document via Ultramsg WhatsApp API.
     *
     * @param string $to Phone number with international format (e.g., +249991961111)
     * @param string $filename File name with extension (e.g., hello.pdf)
     * @param string $document HTTP link to file or base64-encoded file (max 30MB)
     * @param string $caption Caption text under the file (max 1024 characters)
     * @return array{success: bool, data: mixed, error?: string}
     */
    public function sendDocument(string $to, string $filename, string $document, string $caption = ''): array
    {
        if (!$this->isConfigured()) {
            Log::error('UltramsgService: Service not configured.');
            return ['success' => false, 'error' => 'Ultramsg service not configured.', 'data' => null];
        }

        // Validate filename length
        if (strlen($filename) > 255) {
            Log::error('UltramsgService: Filename too long. Max length is 255 characters.');
            return ['success' => false, 'error' => 'Filename too long. Maximum 255 characters allowed.', 'data' => null];
        }

        // Validate caption length
        if (strlen($caption) > 1024) {
            Log::error('UltramsgService: Caption too long. Max length is 1024 characters.');
            return ['success' => false, 'error' => 'Caption too long. Maximum 1024 characters allowed.', 'data' => null];
        }

        $endpoint = "{$this->baseUrl}/{$this->instanceId}/messages/document";

        try {
            $response = Http::asForm()
                ->post($endpoint, [
                    'token' => $this->token,
                    'to' => $to,
                    'filename' => 'LAB RESULT',
                    'document' => $document,
                    'caption' => $caption,
                ]);

            return $this->handleResponse($response, 'Document');

        } catch (\Exception $e) {
            Log::error("UltramsgService sendDocument Exception: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Send a document from a local file path.
     *
     * @param string $to Phone number with international format
     * @param string $filePath Local file path
     * @param string $caption Caption text under the file
     * @return array{success: bool, data: mixed, error?: string}
     */
    public function sendDocumentFromFile(string $to, string $filePath, string $caption = ''): array
    {
        if (!file_exists($filePath)) {
            Log::error("UltramsgService: File not found: {$filePath}");
            return ['success' => false, 'error' => 'File not found.', 'data' => null];
        }

        $filename = basename($filePath);
        $fileContent = file_get_contents($filePath);
        
        if ($fileContent === false) {
            Log::error("UltramsgService: Could not read file: {$filePath}");
            return ['success' => false, 'error' => 'Could not read file.', 'data' => null];
        }

        // Convert to base64
        $base64Content = base64_encode($fileContent);

        return $this->sendDocument($to, $filename, $base64Content, $caption);
    }

    /**
     * Send a document from a URL.
     *
     * @param string $to Phone number with international format
     * @param string $documentUrl HTTP URL to the document
     * @param string $filename File name with extension
     * @param string $caption Caption text under the file
     * @return array{success: bool, data: mixed, error?: string}
     */
    public function sendDocumentFromUrl(string $to, string $documentUrl, string $filename, string $caption = ''): array
    {
        return $this->sendDocument($to, $filename, $documentUrl, $caption);
    }

    /**
     * Send an image via Ultramsg WhatsApp API.
     *
     * @param string $to Phone number with international format (e.g., +249991961111)
     * @param string $image HTTP link to image or base64-encoded image
     * @param string $caption Caption text under the image (max 1024 characters)
     * @return array{success: bool, data: mixed, error?: string}
     */
    public function sendImage(string $to, string $image, string $caption = ''): array
    {
        if (!$this->isConfigured()) {
            Log::error('UltramsgService: Service not configured.');
            return ['success' => false, 'error' => 'Ultramsg service not configured.', 'data' => null];
        }

        // Validate caption length
        if (strlen($caption) > 1024) {
            Log::error('UltramsgService: Caption too long. Max length is 1024 characters.');
            return ['success' => false, 'error' => 'Caption too long. Maximum 1024 characters allowed.', 'data' => null];
        }

        $endpoint = "{$this->baseUrl}/{$this->instanceId}/messages/image";

        try {
            $response = Http::asForm()
                ->post($endpoint, [
                    'token' => $this->token,
                    'to' => $to,
                    'image' => $image,
                    'caption' => $caption,
                ]);

            return $this->handleResponse($response, 'Image');

        } catch (\Exception $e) {
            Log::error("UltramsgService sendImage Exception: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Send an image from a local file path.
     *
     * @param string $to Phone number with international format
     * @param string $filePath Local file path
     * @param string $caption Caption text under the image
     * @return array{success: bool, data: mixed, error?: string}
     */
    public function sendImageFromFile(string $to, string $filePath, string $caption = ''): array
    {
        if (!file_exists($filePath)) {
            Log::error("UltramsgService: Image file not found: {$filePath}");
            return ['success' => false, 'error' => 'Image file not found.', 'data' => null];
        }

        $fileContent = file_get_contents($filePath);
        
        if ($fileContent === false) {
            Log::error("UltramsgService: Could not read image file: {$filePath}");
            return ['success' => false, 'error' => 'Could not read image file.', 'data' => null];
        }

        // Convert to base64
        $base64Content = base64_encode($fileContent);

        return $this->sendImage($to, $base64Content, $caption);
    }

    /**
     * Send an image from a URL.
     *
     * @param string $to Phone number with international format
     * @param string $imageUrl HTTP URL to the image
     * @param string $caption Caption text under the image
     * @return array{success: bool, data: mixed, error?: string}
     */
    public function sendImageFromUrl(string $to, string $imageUrl, string $caption = ''): array
    {
        return $this->sendImage($to, $imageUrl, $caption);
    }

    /**
     * Handles the response from the Ultramsg API.
     *
     * @param Response $response
     * @param string $actionDescription
     * @return array{success: bool, data: mixed, error?: string}
     */
    protected function handleResponse(Response $response, string $actionDescription): array
    {
        $responseData = $response->json();

        if ($response->successful() && isset($responseData['sent']) && $responseData['sent'] === 'true') {
            Log::info("UltramsgService: {$actionDescription} sent successfully.", [
                'response' => $responseData,
                'message_id' => $responseData['id'] ?? null
            ]);
            return [
                'success' => true, 
                'data' => $responseData,
                'message_id' => $responseData['id'] ?? null
            ];
        }

        $errorMessage = "Failed to send {$actionDescription}.";
        
        if (isset($responseData['message'])) {
            $errorMessage .= " Error: " . $responseData['message'];
        } elseif (!$response->successful()) {
            $errorMessage .= " HTTP Status: " . $response->status();
        }

        Log::error("UltramsgService: {$errorMessage}", [
            'response' => $responseData, 
            'status_code' => $response->status()
        ]);
        
        return ['success' => false, 'error' => $errorMessage, 'data' => $responseData];
    }

    /**
     * Format a phone number to international format for Ultramsg.
     * Handles various input formats:
     * - Local format: 0991961111 -> +249991961111
     * - International format: +249991961111 -> +249991961111
     * - Without country code: 991961111 -> +249991961111
     *
     * @param string $phoneNumber
     * @param string $defaultCountryCode
     * @return string|null
     */
    public static function formatPhoneNumber(string $phoneNumber, string $defaultCountryCode = '249'): ?string
    {
        if (empty(trim($phoneNumber))) {
            return null;
        }

        // Remove common characters like +, -, spaces, parentheses
        $cleanedNumber = preg_replace('/[^\d]/', '', $phoneNumber);

        // If it starts with 0, remove it (common for local numbers like 0991961111)
        if (str_starts_with($cleanedNumber, '0')) {
            $cleanedNumber = substr($cleanedNumber, 1);
        }

        // If it doesn't start with the default country code, prepend it
        if (!str_starts_with($cleanedNumber, $defaultCountryCode)) {
            $cleanedNumber = $defaultCountryCode . $cleanedNumber;
        }

        // Basic length check (country code + 8-10 digits)
        if (strlen($cleanedNumber) < 10 || strlen($cleanedNumber) > 15) {
            Log::warning("UltramsgService: Potentially invalid phone number format: {$phoneNumber} -> {$cleanedNumber}");
        }

        return '+' . $cleanedNumber;
    }

    /**
     * Get instance status from Ultramsg API.
     *
     * @return array{success: bool, data: mixed, error?: string, status?: string, substatus?: string}
     */
    public function getInstanceStatus(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Ultramsg service not configured.', 'data' => null];
        }

        $endpoint = "{$this->baseUrl}/{$this->instanceId}/instance/status";

        try {
            $response = Http::get($endpoint, [
                'token' => $this->token,
            ]);

            $responseData = $response->json();

            if ($response->successful()) {
                // Extract status information from the response
                $status = $responseData['status']['accountStatus']['status'] ?? null;
                $substatus = $responseData['status']['accountStatus']['substatus'] ?? null;
                
                Log::info("UltramsgService: Instance status retrieved successfully.", [
                    'status' => $status,
                    'substatus' => $substatus,
                    'response' => $responseData
                ]);

                return [
                    'success' => true,
                    'data' => $responseData,
                    'status' => $status,
                    'substatus' => $substatus
                ];
            }

            $errorMessage = "Failed to get instance status.";
            if (isset($responseData['message'])) {
                $errorMessage .= " Error: " . $responseData['message'];
            } else {
                $errorMessage .= " HTTP Status: " . $response->status();
            }

            Log::error("UltramsgService: {$errorMessage}", [
                'response' => $responseData,
                'status_code' => $response->status()
            ]);

            return ['success' => false, 'error' => $errorMessage, 'data' => $responseData];

        } catch (\Exception $e) {
            Log::error("UltramsgService getInstanceStatus Exception: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Check if the Ultramsg instance is connected and ready to send messages.
     *
     * @return bool
     */
    public function isInstanceConnected(): bool
    {
        $statusResult = $this->getInstanceStatus();
        
        if (!$statusResult['success']) {
            return false;
        }

        $status = $statusResult['status'] ?? null;
        $substatus = $statusResult['substatus'] ?? null;

        // Check if the instance is authenticated and connected
        return $status === 'authenticated' && $substatus === 'connected';
    }

}
