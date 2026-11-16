<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Optional SMS delivery through Twilio's REST API.
 *
 * Disabled unless PCFIX_SMS_FROM, TWILIO_ACCOUNT_SID and TWILIO_AUTH_TOKEN are
 * configured. No credentials are bundled with the application.
 */
class SmsGateway
{
    public static function isConfigured(): bool
    {
        return (bool) (config('pcfix.sms.from') && config('pcfix.sms.account_sid') && config('pcfix.sms.auth_token'));
    }

    /** Normalise a Philippine mobile number to E.164 (+63...). Returns null if unusable. */
    public static function normalise(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
            return '+63' . substr($digits, 1);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '639')) {
            return '+' . $digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '+63' . $digits;
        }

        return strlen($digits) >= 10 ? '+' . $digits : null;
    }

    public static function send(?string $phone, string $message): bool
    {
        $to = self::normalise($phone);

        if (! $to || ! self::isConfigured()) {
            Log::info('PCFix SMS skipped', ['reason' => $to ? 'provider not configured' : 'no valid number']);

            return false;
        }

        try {
            $sid = config('pcfix.sms.account_sid');
            $response = Http::asForm()
                ->withBasicAuth($sid, config('pcfix.sms.auth_token'))
                ->timeout(10)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => config('pcfix.sms.from'),
                    'To' => $to,
                    'Body' => mb_substr($message, 0, 480),
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('PCFix SMS failed: ' . $e->getMessage());

            return false;
        }
    }
}
