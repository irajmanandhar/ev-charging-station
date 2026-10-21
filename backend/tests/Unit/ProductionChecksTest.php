<?php

namespace Tests\Unit;

use App\Support\ProductionChecks;
use PHPUnit\Framework\TestCase;

class ProductionChecksTest extends TestCase
{
    private function good(array $override = []): array
    {
        return array_merge([
            'app_key' => 'base64:abc',
            'debug' => false,
            'expose_otp' => false,
            'app_url' => 'https://api.example.com',
            'frontend_url' => 'https://app.example.com',
            'cors_origins' => ['https://app.example.com'],
            'db_connection' => 'mysql',
            'mailer' => 'smtp',
            'queue' => 'database',
            'payments_driver' => 'null',
        ], $override);
    }

    public function test_a_sound_production_config_passes(): void
    {
        $this->assertSame([], ProductionChecks::problems($this->good()));
    }

    public function test_development_settings_are_each_reported(): void
    {
        $bad = $this->good([
            'app_key' => '', 'debug' => true, 'expose_otp' => true, 'app_url' => 'http://localhost:8000',
            'frontend_url' => 'http://localhost:3000', 'cors_origins' => ['*'], 'db_connection' => 'sqlite',
            'mailer' => 'log', 'queue' => 'sync',
        ]);

        $this->assertCount(9, ProductionChecks::problems($bad));
    }

    public function test_payment_drivers_need_their_secret(): void
    {
        $this->assertCount(1, ProductionChecks::problems($this->good(['payments_driver' => 'khalti'])));
        $this->assertCount(1, ProductionChecks::problems($this->good(['payments_driver' => 'esewa'])));
        $this->assertSame([], ProductionChecks::problems($this->good(['payments_driver' => 'khalti', 'khalti_key' => 'live_secret'])));
    }

    public function test_sandbox_payment_endpoints_are_rejected_in_production(): void
    {
        $khalti = $this->good(['payments_driver' => 'khalti', 'khalti_key' => 'k', 'khalti_url' => 'https://dev.khalti.com/api/v2']);
        $this->assertCount(1, ProductionChecks::problems($khalti));
        $this->assertSame([], ProductionChecks::problems([...$khalti, 'khalti_url' => 'https://khalti.com/api/v2']));

        $esewa = $this->good([
            'payments_driver' => 'esewa', 'esewa_key' => 'k', 'esewa_code' => 'EPAYTEST',
            'esewa_form_url' => 'https://rc-epay.esewa.com.np/api/epay/main/v2/form',
            'esewa_status_url' => 'https://rc.esewa.com.np/api/epay/transaction/status/',
        ]);
        $this->assertCount(1, ProductionChecks::problems($esewa));
        $live = [...$esewa, 'esewa_code' => 'LIVE123', 'esewa_form_url' => 'https://epay.esewa.com.np/api/epay/main/v2/form', 'esewa_status_url' => 'https://esewa.com.np/api/epay/transaction/status/'];
        $this->assertSame([], ProductionChecks::problems($live));
    }

    public function test_localhost_variants_are_detected_in_cors_origins(): void
    {
        foreach (['http://localhost:3000', 'http://127.0.0.1:3000', 'localhost'] as $origin) {
            $this->assertNotEmpty(ProductionChecks::problems($this->good(['cors_origins' => [$origin]])), $origin);
        }
    }
}
