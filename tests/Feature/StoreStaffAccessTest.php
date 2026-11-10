<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\BuildsPlatform;
use Tests\TestCase;

class StoreStaffAccessTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function staff(int $tenant, int $store, string $profile = 'operator'): User
    {
        $user = User::factory()->create(['role' => 'staff', 'tenant_id' => $tenant, 'staff_profile' => $profile, 'access_enabled' => true]);
        DB::table('user_store_access')->insert(['user_id' => $user->id, 'tenant_id' => $tenant, 'store_id' => $store]);

        return $user;
    }

    public function test_owner_creates_staff_without_granting_owner_permissions_or_foreign_stores(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $foreign = $this->store($this->tenant('other'), 'other');
        $owner = $this->owner($tenant);
        $this->actingAs($owner);
        $data = ['name' => 'Operador', 'email' => 'operator@example.test', 'password' => 'InitialPassword2026!', 'password_confirmation' => 'InitialPassword2026!', 'staff_profile' => 'operator', 'stores' => [$foreign], 'role' => 'admin', 'tenant_id' => 999];
        $this->post('/team', $data)->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        $data['stores'] = [$store];
        $this->post('/team', $data)->assertSessionHasNoErrors();
        $member = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame('staff', $member->role);
        $this->assertSame($tenant, $member->tenant_id);
        $this->assertTrue(Hash::check($data['password'], $member->password));
        $this->get('/team')->assertOk()->assertSee('Operador')->assertDontSee($data['password']);
        $this->post('/team/'.$owner->id, ['staff_profile' => 'kitchen', 'access_enabled' => 0])->assertNotFound();
        $this->assertTrue($owner->fresh()->access_enabled);
        $this->assertDatabaseHas('audit_events', ['action' => 'team.created']);
    }

    public function test_staff_can_only_read_assigned_stores_across_pages_filters_and_live_feed(): void
    {
        $tenant = $this->tenant();
        $allowed = $this->store($tenant);
        $denied = $this->store($tenant, 'denied');
        $foreign = $this->store($this->tenant('other'), 'other');
        $ownOrder = $this->order($tenant, $allowed);
        $otherOrder = $this->order($tenant, $denied);
        $foreignOrder = $this->order(DB::table('stores')->find($foreign)->tenant_id, $foreign);
        DB::table('orders')->where('id', $ownOrder)->update(['customer_name' => 'CLIENTE PERMITIDO']);
        DB::table('orders')->where('id', $otherOrder)->update(['customer_name' => 'CLIENTE PROIBIDO']);
        $this->actingAs($this->staff($tenant, $allowed));
        $this->get('/app')->assertRedirect('/operation');
        $this->get('/operation')->assertOk()->assertSee('CLIENTE PERMITIDO')->assertDontSee('CLIENTE PROIBIDO')->assertDontSee('Equipe e acessos');
        $this->getJson('/operation/live')->assertOk()->assertDontSee('CLIENTE PROIBIDO');
        $this->get('/operation?store_id='.$denied)->assertNotFound();
        $this->get('/operation/live?store_id='.$foreign)->assertNotFound();
        $this->get('/operation/orders/'.$ownOrder)->assertOk();
        $this->get('/operation/orders/'.$otherOrder)->assertNotFound();
        $this->get('/operation/orders/'.$foreignOrder)->assertNotFound();
        foreach (['/dashboard', '/orders/live', '/team', '/admin', '/integrations/ifood', '/stores/'.$allowed.'/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach (['/team', '/whatsapp/connect', '/products'] as $url) {
            $this->post($url, [])->assertForbidden();
        }
    }

    public function test_kitchen_hides_contacts_and_values_and_only_changes_preparation(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $order = $this->order($tenant, $store);
        DB::table('orders')->where('id', $order)->update(['status' => 'accepted', 'phone' => '11987654321', 'address' => 'ENDERECO PRIVADO', 'total_cents' => 98765]);
        DB::table('order_items')->insert(['order_id' => $order, 'name' => 'Pizza especial', 'quantity' => 2, 'unit_cents' => 5000, 'options' => json_encode([['group' => 'Molho', 'name' => 'Picante', 'price_cents' => 100]])]);
        $this->actingAs($this->staff($tenant, $store, 'kitchen'));
        foreach (['/operation', '/operation/live', '/operation/orders/'.$order] as $url) {
            $this->get($url)->assertOk()->assertSee('Pizza especial')->assertSee('Picante')->assertDontSee('11987654321')->assertDontSee('ENDERECO PRIVADO')->assertDontSee('987,65')->assertDontSee('Confirmar pagamento');
        }
        $this->post('/operation/orders/'.$order.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Não permitido'])->assertForbidden();
        $this->post('/operation/orders/'.$order.'/paid')->assertForbidden();
        $this->post('/operation/orders/'.$order.'/status', ['status' => 'preparing'])->assertSessionHasNoErrors();
        $this->post('/operation/orders/'.$order.'/status', ['status' => 'ready'])->assertSessionHasNoErrors();
        $this->post('/operation/orders/'.$order.'/status', ['status' => 'completed'])->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'ready', 'payment_status' => 'pending']);
    }

    public function test_operator_respects_store_scope_transitions_and_payment_rules(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $other = $this->store($tenant, 'other');
        $order = $this->order($tenant, $store);
        $denied = $this->order($tenant, $other);
        DB::table('orders')->where('id', $order)->update(['status' => 'pending']);
        $this->actingAs($this->staff($tenant, $store));
        $this->post('/operation/orders/'.$order.'/status', ['status' => 'ready'])->assertStatus(409);
        $this->post('/operation/orders/'.$denied.'/status', ['status' => 'completed'])->assertNotFound();
        $this->post('/operation/orders/'.$denied.'/paid')->assertNotFound();
        $this->post('/operation/orders/'.$order.'/status', ['status' => 'accepted'])->assertSessionHasNoErrors();
        $this->post('/operation/orders/'.$order.'/paid')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'accepted', 'payment_status' => 'paid']);
        $this->post('/operation/orders/'.$order.'/status', ['status' => 'cancelled'])->assertSessionHasErrors('cancellation_reason');
        $this->post('/operation/orders/'.$order.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Produto esgotado'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'cancelled', 'cancellation_reason' => 'Produto esgotado']);
    }

    public function test_permission_changes_revoke_sessions_and_recheck_stale_profiles_and_memberships(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $other = $this->store($tenant, 'other');
        $order = $this->order($tenant, $store);
        $member = $this->staff($tenant, $store);
        DB::table('sessions')->insert(['id' => 'staff-session', 'user_id' => $member->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($this->owner($tenant))->post('/team/'.$member->id, ['staff_profile' => 'kitchen', 'access_enabled' => 1, 'stores' => [$other]])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['id' => 'staff-session']);
        $this->actingAs($member)->get('/operation/orders/'.$order)->assertNotFound();
        $this->post('/operation/orders/'.$order.'/paid')->assertForbidden();
        $this->actingAs($this->owner($tenant))->post('/team/'.$member->id, ['staff_profile' => 'kitchen', 'access_enabled' => 0])->assertSessionHasNoErrors();
        $this->actingAs($member)->get('/operation')->assertForbidden();
        $this->get('/operation/live')->assertForbidden();
        $this->post('/logout');
        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_foreign_owner_cannot_manage_or_list_another_tenants_team(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $member = $this->staff($tenant, $store);
        $foreign = $this->tenant('foreign');
        $foreignStore = $this->store($foreign, 'foreign');
        $this->actingAs($this->owner($foreign))->get('/team')->assertOk()->assertDontSee($member->email);
        $this->post('/team/'.$member->id, ['staff_profile' => 'operator', 'access_enabled' => 1, 'stores' => [$foreignStore]])->assertNotFound();
        $this->assertDatabaseHas('user_store_access', ['user_id' => $member->id, 'store_id' => $store]);
    }
}
