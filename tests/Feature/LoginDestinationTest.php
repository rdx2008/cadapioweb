<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LoginDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class LoginDestinationTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function ownerLogin(): User
    {
        return $this->owner($this->tenant());
    }

    private function credentials(User $user): array
    {
        return ['email' => $user->email, 'password' => 'password'];
    }

    public function test_guest_is_returned_to_requested_screen_with_filters_after_login(): void
    {
        $owner = $this->ownerLogin();
        $url = '/finance?direction=payable&from=2026-11-01&to=2026-11-30';
        $this->get($url)->assertRedirect('/login');
        $this->post('/login', $this->credentials($owner))->assertRedirect($url);
        $this->assertAuthenticatedAs($owner);
        $this->assertNull(session('url.intended'));
    }

    public function test_encrypted_screen_cookie_survives_session_loss_without_polling_or_download_overwriting_it(): void
    {
        $owner = $this->ownerLogin();
        $store = $this->store($owner->tenant_id);
        $url = '/finance?store_id='.$store.'&direction=receivable';
        $response = $this->actingAs($owner)->get($url)->assertOk()->assertCookie(LoginDestination::COOKIE);
        $cookie = $response->getCookie(LoginDestination::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame($url, json_decode($cookie->getValue(), true)['path']);
        $this->assertStringNotContainsString('/finance', $response->getCookie(LoginDestination::COOKIE, false)->getValue());
        $this->getJson('/finance')->assertCookieMissing(LoginDestination::COOKIE);
        $this->get('/finance/export')->assertOk()->assertCookieMissing(LoginDestination::COOKIE);
        Auth::logout();
        session()->invalidate();
        $this->withCookie(LoginDestination::COOKIE, $cookie->getValue())->post('/login', $this->credentials($owner))->assertRedirect($url);
    }

    public function test_cookie_is_bound_to_account_and_explicit_logout_clears_it(): void
    {
        $owner = $this->ownerLogin();
        $cookie = $this->actingAs($owner)->get('/finance')->assertOk()->getCookie(LoginDestination::COOKIE)->getValue();
        $this->post('/logout')->assertRedirect('/login')->assertCookieExpired(LoginDestination::COOKIE);
        $other = $this->owner($this->tenant('other'));
        $this->withCookie(LoginDestination::COOKIE, $cookie)->post('/login', $this->credentials($other))->assertRedirect('/app');
    }

    public function test_untrusted_or_non_screen_destinations_are_ignored_and_failed_login_keeps_intended_screen(): void
    {
        $owner = $this->ownerLogin();
        $this->withSession(['url.intended' => '/finance'])->from('/login')->post('/login', ['email' => $owner->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertSame('/finance', session('url.intended'));
        $this->post('/login', $this->credentials($owner))->assertRedirect('/finance');
        Auth::logout();
        foreach (['https://evil.test/finance', '//evil.test/finance', '/%2fevil.test/finance', '/\\evil.test/finance', '/login', '/logout', '/cardapio/demo', '/missing'] as $target) {
            $this->assertNull(app(LoginDestination::class)->path(request(), $target));
        }
        $this->assertSame('/dashboard?q=Maria%20Silva', app(LoginDestination::class)->path(request(), '/dashboard?q=Maria%20Silva'));
        $this->withSession(['url.intended' => 'https://evil.test/finance'])->post('/login', $this->credentials($owner))->assertRedirect('/app');
    }

    public function test_expired_form_returns_to_login_without_replaying_action_and_json_keeps_419(): void
    {
        $owner = $this->ownerLogin();
        $cookie = $this->actingAs($owner)->get('/finance')->assertOk()->getCookie(LoginDestination::COOKIE)->getValue();
        Auth::logout();
        session()->invalidate();
        $this->app->instance('env', 'local');
        $this->withCookie(LoginDestination::COOKIE, $cookie)->post('/finance', ['description' => 'Not submitted'])->assertRedirect('/login')->assertSessionHas('success');
        $this->assertDatabaseCount('financial_accounts', 0);
        $this->postJson('/finance', ['description' => 'Not submitted'])->assertStatus(419);
        $this->app->instance('env', 'testing');
        $this->post('/login', $this->credentials($owner))->assertRedirect('/finance');
    }

    public function test_login_resume_never_bypasses_disabled_access_or_suspended_company(): void
    {
        $owner = $this->ownerLogin();
        DB::table('users')->where('id', $owner->id)->update(['access_enabled' => false]);
        $this->withSession(['url.intended' => '/finance'])->post('/login', $this->credentials($owner))->assertSessionHasErrors('email');
        $this->assertGuest();
        DB::table('users')->where('id', $owner->id)->update(['access_enabled' => true]);
        DB::table('tenants')->where('id', $owner->tenant_id)->update(['status' => 'suspended', 'suspended_at' => now()]);
        $this->post('/login', $this->credentials($owner))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_intended_return_also_applies_to_superadmin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->get('/admin?include_deleted=1')->assertRedirect('/login');
        $this->post('/login', $this->credentials($admin))->assertRedirect('/admin?include_deleted=1');
    }
}
