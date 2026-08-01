<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Evita que un .env de producción (APP_ENV/DB_*) contamine los tests
        $this->forceTestingEnvironment();

        parent::setUp();
    }

    private function forceTestingEnvironment(): void
    {
        $vars = [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'true',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'STRIPE_KEY' => 'pk_test_dummy',
            'STRIPE_SECRET' => 'sk_test_dummy',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_test_dummy',
            'STRIPE_CURRENCY' => 'mxn',
            'STRIPE_TRIAL_DAYS' => '14',
        ];

        foreach ($vars as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
