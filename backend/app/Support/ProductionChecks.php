<?php

namespace App\Support;

/** Refuses to run production with development settings (debug on, OTP echoed, sqlite, log mailer, ...). */
class ProductionChecks
{
    /**
     * @param  array<string, mixed>  $c  keys mirror the config() values read below, so this stays testable
     * @return string[]
     */
    public static function problems(array $c): array
    {
        $problems = [];

        if (empty($c['app_key'])) {
            $problems[] = 'APP_KEY is not set (run: php artisan key:generate --show).';
        }
        if (! empty($c['debug'])) {
            $problems[] = 'APP_DEBUG must be false: debug pages leak configuration and stack traces.';
        }
        if (! empty($c['expose_otp'])) {
            $problems[] = 'EXPOSE_OTP_IN_RESPONSE must be false: it would hand out login codes in API responses.';
        }
        if (! str_starts_with((string) ($c['app_url'] ?? ''), 'https://')) {
            $problems[] = 'APP_URL must be an https:// URL.';
        }
        if (self::isLocal((string) ($c['frontend_url'] ?? ''))) {
            $problems[] = 'FRONTEND_URL must be the public web address, not localhost.';
        }
        foreach ((array) ($c['cors_origins'] ?? []) as $origin) {
            if (self::isLocal((string) $origin) || $origin === '*') {
                $problems[] = "CORS_ALLOWED_ORIGINS contains '{$origin}'; list only your public web origin(s).";
                break;
            }
        }
        if (($c['db_connection'] ?? '') === 'sqlite') {
            $problems[] = 'DB_CONNECTION=sqlite is for development; use MySQL or PostgreSQL (row locking protects bookings).';
        }
        if (in_array($c['mailer'] ?? '', ['log', 'array'], true)) {
            $problems[] = 'MAIL_MAILER must send real email (smtp, ses, ...): sign-up and password-reset codes are emailed.';
        }
        if (($c['queue'] ?? '') === 'sync') {
            $problems[] = 'QUEUE_CONNECTION=sync sends email inside the request; use database or redis and run a queue worker.';
        }
        $driver = $c['payments_driver'] ?? 'none';
        if ($driver === 'khalti') {
            if (empty($c['khalti_key'])) {
                $problems[] = 'PAYMENTS_DRIVER=khalti needs KHALTI_SECRET_KEY.';
            }
            if (str_contains((string) ($c['khalti_url'] ?? ''), 'dev.khalti.com')) {
                $problems[] = 'PAYMENTS_DRIVER=khalti still points at the Khalti SANDBOX; set KHALTI_BASE_URL to the live API once approved (or use PAYMENTS_DRIVER=none).';
            }
        }
        if ($driver === 'esewa') {
            if (empty($c['esewa_key'])) {
                $problems[] = 'PAYMENTS_DRIVER=esewa needs ESEWA_SECRET_KEY.';
            }
            $sandbox = str_contains((string) ($c['esewa_form_url'] ?? ''), 'rc-epay')
                || str_contains((string) ($c['esewa_status_url'] ?? ''), '//rc.esewa')
                || ($c['esewa_code'] ?? '') === 'EPAYTEST';
            if ($sandbox) {
                $problems[] = 'PAYMENTS_DRIVER=esewa still uses the eSewa SANDBOX (rc-epay URLs / EPAYTEST); set the live ESEWA_* values once approved (or use PAYMENTS_DRIVER=none).';
            }
        }

        return $problems;
    }

    /** @return string[] */
    public static function fromConfig(): array
    {
        return self::problems([
            'app_key' => config('app.key'),
            'debug' => config('app.debug'),
            'expose_otp' => config('ev.expose_otp'),
            'app_url' => config('app.url'),
            'frontend_url' => config('ev.frontend_url'),
            'cors_origins' => config('cors.allowed_origins'),
            'db_connection' => config('database.default'),
            'mailer' => config('mail.default'),
            'queue' => config('queue.default'),
            'payments_driver' => config('payments.driver'),
            'khalti_key' => config('payments.khalti.secret_key'),
            'esewa_key' => config('payments.esewa.secret_key'),
            'khalti_url' => config('payments.khalti.base_url'),
            'esewa_form_url' => config('payments.esewa.form_url'),
            'esewa_status_url' => config('payments.esewa.status_url'),
            'esewa_code' => config('payments.esewa.product_code'),
        ]);
    }

    private static function isLocal(string $url): bool
    {
        return (bool) preg_match('#^(https?://)?(localhost|127\.0\.0\.1|\[::1\])(:|/|$)#i', $url);
    }
}
