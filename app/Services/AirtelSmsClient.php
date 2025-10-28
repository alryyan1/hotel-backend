<?php

namespace App\Services;

use App\Services\Contracts\SmsClient;
use Illuminate\Support\Facades\Http;

class AirtelSmsClient implements SmsClient
{
    private string $baseUrl;
    private string $endpoint;
    private string $apiKey;
    private string $defaultSender;
    private int $timeoutSeconds;

    public function __construct()
    {
        $config = config('services.airtel_sms');
        $this->baseUrl = rtrim((string)($config['base_url'] ?? 'https://www.airtel.sd'), '/');
        $this->endpoint = '/' . ltrim((string)($config['endpoint'] ?? '/api/rest_send_sms/'), '/');
        $this->apiKey = (string)($config['api_key'] ?? '');
        $this->defaultSender = (string)($config['default_sender'] ?? 'Jawda');
        $this->timeoutSeconds = (int)($config['timeout'] ?? 10);
    }

    public function send(string $to, string $message, bool $isOtp = false, ?string $sender = null): array
    {
        $senderId = $sender ?: $this->defaultSender;
        
        // Validate sender ID format (typically 3-11 alphanumeric characters)
 
        $payload = [
            'sender' => $senderId,
            'messages' => [
                [
                    'to' => $to,
                    'message' => $message,
                    'is_otp' => $isOtp,
                ],
            ],
        ];

        $response = $this->request($payload);
        return $this->normalizeSingleResponse($to, $response);
    }

    public function sendBulk(array $messages, ?string $sender = null): array
    {
        $senderId = $sender ?: $this->defaultSender;
        
        // Validate sender ID format (typically 3-11 alphanumeric characters)
        if (!preg_match('/^[A-Z0-9]{3,11}$/', $senderId)) {
            return [
                'success' => false,
                'results' => [],
                'error' => "Invalid sender ID format: '{$senderId}'. Must be 3-11 alphanumeric characters (uppercase).",
                'raw' => null,
            ];
        }

        $normalized = [];
        foreach ($messages as $msg) {
            $normalized[] = [
                'to' => (string)$msg['to'],
                'message' => (string)$msg['message'],
                'is_otp' => (bool)($msg['is_otp'] ?? false),
            ];
        }

        $payload = [
            'sender' => $senderId,
            'messages' => $normalized,
        ];

        $response = $this->request($payload);
        return $this->normalizeBulkResponse($response);
    }

    private function request(array $payload)
    {
        $url = $this->baseUrl . $this->endpoint;
        $http = Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->withHeaders([
                'X-API-KEY' => $this->apiKey,
            ]);

        return $http->post($url, $payload);
    }

    private function normalizeSingleResponse(string $to, $response): array
    {
        if (!$response->successful()) {
            return [
                'success' => false,
                'error' => $response->body(),
                'raw' => $response->json(),
            ];
        }

        $json = $response->json();
        $success = true;
        $providerId = null;

        if (is_array($json)) {
            // Handle response shape:
            // {
            //   "status":"completed",
            //   "results":[{"to":"249...","status":"sent","units":1,"apiMsgId":197589}],
            //   "total_units":1
            // }
            if (isset($json['results'][0])) {
                $first = $json['results'][0];
                $providerId = $first['apiMsgId'] ?? ($first['message_id'] ?? null);
                $msgStatus = strtolower((string)($first['status'] ?? 'sent'));
                $success = in_array($msgStatus, ['sent', 'queued', 'delivered', 'success', 'ok'], true);
            } else {
                // Fallbacks for other shapes
                $providerId = $json['apiMsgId'] ?? ($json['message_id'] ?? ($json['data']['message_id'] ?? null));
                $overall = strtolower((string)($json['status'] ?? 'completed'));
                $success = in_array($overall, ['completed', 'success', 'ok'], true);
            }
        }

        return [
            'success' => (bool)$success,
            'provider_id' => $providerId,
            'raw' => $json,
        ];
    }

    private function normalizeBulkResponse($response): array
    {
        if (!$response->successful()) {
            return [
                'success' => false,
                'results' => [],
                'raw' => $response->json(),
            ];
        }

        $json = $response->json();
        $results = [];
        // Map provider results
        if (isset($json['results']) && is_array($json['results'])) {
            foreach ($json['results'] as $item) {
                $msgStatus = strtolower((string)($item['status'] ?? 'sent'));
                $isSuccess = isset($item['success']) ? (bool)$item['success'] : in_array($msgStatus, ['sent', 'queued', 'delivered', 'success', 'ok'], true);
                $results[] = [
                    'to' => (string)($item['to'] ?? ''),
                    'success' => $isSuccess,
                    'provider_id' => $item['apiMsgId'] ?? ($item['message_id'] ?? null),
                    'error' => $item['error'] ?? null,
                ];
            }
        }

        return [
            'success' => empty($results) ? true : !collect($results)->contains(fn($r) => $r['success'] === false),
            'results' => $results,
            'raw' => $json,
        ];
    }
}


