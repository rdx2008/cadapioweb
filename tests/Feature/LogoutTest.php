<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_logout_action_is_in_the_header_and_invalidates_the_owner_session(): void
    {
        $tenant = $this->tenant();
        $this->store($tenant);
        $owner = $this->owner($tenant);
        $response = $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('class="logout-form"', false)->assertSee('aria-label="Sair da conta"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'action="/logout"'));
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/integrations/ifood')->assertRedirect('/login');
    }

    public function test_superadmin_and_courier_also_have_a_single_logout_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('aria-label="Sair da conta"', false);
        $this->post('/logout')->assertRedirect('/login');
        $driver = $this->driver();
        $user = User::findOrFail(DB::table('drivers')->where('id', $driver)->value('user_id'));
        $response = $this->actingAs($user)->get('/courier')->assertOk()->assertSee('aria-label="Sair da conta"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'action="/logout"'));
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
