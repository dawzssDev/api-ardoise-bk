<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_sixth_failed_login_with_same_email_and_ip_returns_429(): void
    {
        User::factory()->create([
            'email' => 'luis@example.com',
            'password' => 'password123',
        ]);

        $payload = [
            'email' => 'luis@example.com',
            'password' => 'wrong-password',
        ];

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', $payload)->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', $payload)
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Too many requests, try again later.')
            ->assertHeader('Retry-After');
    }

    public function test_sixth_login_with_different_email_same_ip_is_not_throttled(): void
    {
        User::factory()->create([
            'email' => 'luis@example.com',
            'password' => 'password123',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'luis@example.com',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        // Misma IP, distinto email → otra llave del limiter 'auth'
        $this->postJson('/api/auth/login', [
            'email' => 'otro@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Credenciales inválidas');
    }

    public function test_authenticated_me_includes_api_rate_limit_headers(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining');
    }
}
