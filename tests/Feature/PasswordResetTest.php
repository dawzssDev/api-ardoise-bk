<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_notification_only_for_existing_user(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'dueño@example.com',
        ]);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'dueño@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, ResetPasswordNotification::class);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'no-existe@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertCount(1);
    }

    public function test_reset_password_updates_password_and_invalidates_token(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'dueño@example.com',
            'password' => 'password-anterior',
        ]);
        $token = $user->createToken('api')->plainTextToken;

        $plainToken = Password::broker('users')->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email' => 'dueño@example.com',
            'token' => $plainToken,
            'password' => 'nuevaClave123',
            'password_confirmation' => 'nuevaClave123',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertTrue(Hash::check('nuevaClave123', $user->password));
        $this->assertFalse(Hash::check('password-anterior', $user->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'dueño@example.com',
        ]);

        // El mismo token ya no sirve
        $this->postJson('/api/auth/reset-password', [
            'email' => 'dueño@example.com',
            'token' => $plainToken,
            'password' => 'otraClave123',
            'password_confirmation' => 'otraClave123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // Token anterior revocado
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_reset_password_notification_builds_frontend_link(): void
    {
        config(['app.frontend_url' => 'https://ardoise.dawzss.com']);

        $user = User::factory()->create([
            'name' => 'Luis',
            'email' => 'luis@example.com',
        ]);

        $notification = new ResetPasswordNotification('token-de-prueba');
        $mail = $notification->toMail($user);
        $html = $mail->render();

        // El token solo va en el href (botón / texto). Blade escapa & → &amp;.
        $this->assertStringContainsString(
            'href="https://ardoise.dawzss.com/reset-password?token=token-de-prueba&amp;email=luis%40example.com"',
            $html,
        );
        $this->assertStringContainsString('Restablecer contraseña', $html);
        $this->assertStringContainsString('Luis', $html);
        $this->assertStringContainsString('#1E2539', $html);
        $this->assertStringContainsString('#D7B794', $html);
        $this->assertStringNotContainsString('copia y pega este enlace', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/>(https?:\/\/[^<]*reset-password\?token=)/',
            $html,
        );
    }

    public function test_forgot_password_requires_email(): void
    {
        $this->postJson('/api/auth/forgot-password', [])
            ->assertStatus(422);
    }
}
