<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule cleaning notifications check daily
Schedule::command('cleaning:check-notifications')->daily();

// Send today's arrivals/departures PDF reports over WhatsApp every morning
Schedule::command('reports:send-today')->dailyAt('08:00');
