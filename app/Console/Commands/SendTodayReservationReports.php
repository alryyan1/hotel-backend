<?php

namespace App\Console\Commands;

use App\Models\HotelSetting;
use App\Models\WhatsAppNotificationNumber;
use App\Services\ReservationReportPdfService;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Console\Command;

class SendTodayReservationReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:send-today';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send today\'s arrivals/departures PDF reports over WhatsApp to the configured numbers';

    public function handle(ReservationReportPdfService $pdfService, WhatsAppCloudApiService $whatsapp): int
    {
        $settings = HotelSetting::first();
        if (!($settings->whatsapp_notifications_enabled ?? true)) {
            $this->info('WhatsApp notifications are disabled in settings — skipping.');
            return 0;
        }

        if (!$whatsapp->isConfigured()) {
            $this->error('WhatsApp Cloud API is not configured — skipping.');
            return 1;
        }

        $reports = [
            'arrivals' => ['event' => 'arrivals_report', 'template' => 'today_arrivals'],
            'departures' => ['event' => 'departures_report', 'template' => 'today_depatures'],
        ];

        foreach ($reports as $type => $config) {
            $numbers = WhatsAppNotificationNumber::where('event', $config['event'])->get(['phone', 'label']);

            if ($numbers->isEmpty()) {
                $this->info("No numbers configured for '{$config['event']}' — skipping {$type} report.");
                continue;
            }

            $this->sendReport($pdfService, $whatsapp, $type, $config['template'], $numbers);
        }

        return 0;
    }

    private function sendReport(
        ReservationReportPdfService $pdfService,
        WhatsAppCloudApiService $whatsapp,
        string $type,
        string $templateName,
        $numbers
    ): void {
        $report = $pdfService->generate($type);

        $tmpPath = tempnam(sys_get_temp_dir(), 'report_') . '.pdf';
        file_put_contents($tmpPath, $report['content']);

        try {
            $upload = $whatsapp->uploadMedia($tmpPath, 'application/pdf', $report['filename']);

            if (!$upload['success']) {
                $this->error("Failed to upload {$type} report to WhatsApp: " . ($upload['error'] ?? 'unknown error'));
                return;
            }

            foreach ($numbers as $number) {
                $result = $whatsapp->sendDocumentTemplateMessage(
                    $number->phone,
                    $templateName,
                    'ar',
                    $upload['media_id'],
                    $report['filename']
                );

                if ($result['success']) {
                    $this->info("Sent {$type} report to {$number->phone}" . ($number->label ? " ({$number->label})" : ''));
                } else {
                    $this->error("Failed to send {$type} report to {$number->phone}: " . ($result['error'] ?? 'unknown error'));
                }
            }
        } finally {
            @unlink($tmpPath);
        }
    }
}
