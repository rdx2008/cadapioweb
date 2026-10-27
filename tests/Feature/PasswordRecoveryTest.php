<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'app.url' => 'https://cadapioweb.example']);
    }

    public function test_recovery_response_does_not_reveal_accounts_and_notification_uses_trusted_domain(): void
    {
        $user = User::factory()->create();
        $this->get('/login')->assertOk()->assertSee('Esqueci minha senha');
        $this->get('/forgot-password')->assertOk()->assertSee('Enviar link de recuperação');
        $this->from('/forgot-password')->withHeader('Host', 'attacker.example')->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        $knownMessage = session('success');
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);
            $this->assertSame('Redefina sua senha · CadapioWeb', $mail->subject);
            $this->assertStringStartsWith('https://cadapioweb.example/reset-password/', $mail->actionUrl);
            $this->assertStringNotContainsString('attacker.example', $mail->actionUrl);
            $this->assertDatabaseMissing('password_reset_tokens', ['token' => $notification->token]);

            return true;
        });
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertSessionHasNoErrors()->assertSessionHas('success', $knownMessage);
        Notification::assertCount(1);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('success', $knownMessage);
        Notification::assertCount(1);
    }

    public function test_valid_reset_revokes_sessions_and_remember_token_for_every_role(): void
    {
        $unrelated = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'unrelated-session', 'user_id' => $unrelated->id, 'payload' => '', 'last_activity' => time()]);
        foreach (['admin', 'owner', 'courier', 'staff'] as $role) {
            $this->travel(61)->seconds();
            $user = User::factory()->create(['role' => $role, 'password' => 'OriginalPassword2026!', 'remember_token' => Str::random(60)]);
            $remember = $user->remember_token;
            DB::table('sessions')->insert(['id' => 'session-'.$role, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
            $token = Password::createToken($user);
            $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))->assertOk()->assertSee('Escolha sua nova senha')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
            $data = ['token' => $token, 'email' => $user->email, 'password' => 'ChangedPassword2026!', 'password_confirmation' => 'ChangedPassword2026!'];
            $this->post('/reset-password', $data)->assertRedirect('/login')->assertSessionHasNoErrors();
            $this->assertTrue(Hash::check('ChangedPassword2026!', $user->fresh()->password));
            $this->assertSame($role, $user->fresh()->role);
            $this->assertNotSame($remember, $user->fresh()->remember_token);
            $this->assertDatabaseMissing('sessions', ['id' => 'session-'.$role]);
            $this->assertDatabaseHas('sessions', ['id' => 'unrelated-session']);
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
            $this->assertDatabaseHas('audit_events', ['action' => 'account.password_reset', 'details' => json_encode(['user_id' => $user->id])]);
            $this->post('/reset-password', $data)->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->post('/login', ['email' => $user->email, 'password' => 'OriginalPassword2026!'])->assertSessionHasErrors('email');
            $this->post('/login', ['email' => $user->email, 'password' => 'ChangedPassword2026!'])->assertRedirect('/app');
            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
    }

    public function test_expired_or_foreign_token_and_invalid_password_cannot_change_account(): void
    {
        $user = User::factory()->create(['password' => 'OriginalPassword2026!']);
        $other = User::factory()->create();
        $token = Password::createToken($user);
        $data = ['token' => $token, 'email' => $other->email, 'password' => 'ChangedPassword2026!', 'password_confirmation' => 'ChangedPassword2026!'];
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
        $data['email'] = $user->email;
        $data['password_confirmation'] = 'DifferentPassword2026!';
        $this->post('/reset-password', $data)->assertSessionHasErrors('password');
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('token'));
        $data['password_confirmation'] = $data['password'];
        $this->travel(61)->minutes();
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('OriginalPassword2026!', $user->fresh()->password));
    }

    public function test_disabled_mailer_does_not_write_reset_links_to_logs_and_requests_are_limited(): void
    {
        $user = User::factory()->create();
        config(['mail.default' => 'log']);
        $this->get('/forgot-password')->assertOk()->assertSee('indisponível');
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasErrors('email');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        config(['mail.default' => 'smtp']);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertRedirect();
        }
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(429);
    }
}
