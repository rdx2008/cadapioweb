<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class DiningCallsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->post('/dining/tables', ['store_id' => $store, 'name' => 'Mesa 1', 'seats' => 4, 'enabled' => 1])->assertRedirect();
        $table = DB::table('dining_tables')->firstOrFail();
        $this->post('/dining/tables/'.$table->id.'/open', ['guests' => 2, 'guest_label' => 'NOME PRIVADO', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $tab = DB::table('dining_tabs')->firstOrFail();
        $this->post('/dining/tabs/'.$tab->id.'/guest-link')->assertRedirect('/dining/tabs/'.$tab->id.'/guest-link');
        $share = $this->get('/dining/tabs/'.$tab->id.'/guest-link')->assertOk();
        $url = $share->viewData('url');
        $token = basename($url);

        return compact('tenant', 'store', 'owner', 'table', 'tab', 'url', 'token');
    }

    public function test_qr_print_uses_current_link_without_mutation_and_rejects_old_revision(): void
    {
        $f = $this->fixture();
        $tab = DB::table('dining_tabs')->find($f['tab']->id);
        $path = route('dining.guest-print', ['id' => $tab->id, 'revision' => $tab->guest_token_hash]);
        $share = $this->get(route('dining.guest-link', $tab->id))->assertOk();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $share->viewData('qr'));
        $this->get($path)->assertOk()->assertViewHas('qr', $share->viewData('qr'))->assertDontSee('NOME PRIVADO')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame($tab->guest_token, DB::table('dining_tabs')->find($tab->id)->guest_token);
        $this->assertDatabaseCount('dining_calls', 0);
        $this->assertDatabaseCount('cash_entries', 0);
        $otherOwner = $this->owner($this->tenant('qr-other'));
        $this->actingAs($otherOwner)->get($path)->assertNotFound();
        $this->actingAs($f['owner']);
        $this->post(route('dining.guest-rotate', $tab->id), ['confirmed' => 1, 'expected_hash' => $tab->guest_token_hash])->assertRedirect();
        $this->get($path)->assertConflict();
        DB::table('dining_tabs')->where('id', $tab->id)->update(['closed_at' => now()]);
        $this->get($path)->assertNotFound();
        auth()->logout();
        $this->get($path)->assertRedirect('/login');
    }

    private function requestCall(array $f, string $kind = 'service', ?string $key = null): array
    {
        $data = ['kind' => $kind, 'request_key' => $key ?? (string) Str::uuid()];
        $this->post($f['url'], $data)->assertRedirect($f['url'])->assertSessionHasNoErrors();

        return $data;
    }

    public function test_customer_link_is_private_per_session_and_only_exposes_call_buttons(): void
    {
        $f = $this->fixture();
        $stored = DB::table('dining_tabs')->find($f['tab']->id);
        $this->assertNotSame($f['token'], $stored->guest_token);
        $this->assertSame($f['token'], Crypt::decryptString($stored->guest_token));
        $this->assertSame(hash('sha256', $f['token']), $stored->guest_token_hash);
        $this->post('/dining/tabs/'.$f['tab']->id.'/guest-link')->assertRedirect();
        $this->assertSame($stored->guest_token_hash, DB::table('dining_tabs')->find($f['tab']->id)->guest_token_hash);
        auth()->logout();
        $this->get($f['url'])->assertOk()->assertSee('Chamar atendimento')->assertSee('Solicitar a conta')->assertDontSee('NOME PRIVADO')->assertDontSee('R$')->assertDontSee('guest_token_hash')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/mesa/'.str_repeat('a', 64))->assertNotFound()->assertSee('Este link não está disponível');
        $this->requestCall($f, 'bill');
        $this->assertDatabaseHas('dining_calls', ['dining_tab_id' => $f['tab']->id, 'kind' => 'bill', 'status' => 'pending']);
        $this->assertDatabaseHas('audit_events', ['action' => 'dining.customer_requested', 'user_id' => null]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_duplicate_customer_requests_are_coalesced_and_retries_after_attendance_do_not_reopen(): void
    {
        $f = $this->fixture();
        $first = $this->requestCall($f);
        $this->requestCall($f, key: $first['request_key']);
        $other = $this->requestCall($f);
        $this->assertDatabaseCount('dining_calls', 1);
        $this->assertDatabaseCount('dining_call_requests', 2);
        $id = DB::table('dining_calls')->value('id');
        $this->post('/dining/calls/'.$id.'/attend')->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/dining/calls/'.$id.'/attend')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dining_calls', ['id' => $id, 'status' => 'attended', 'handled_by' => $f['owner']->id, 'pending_key' => null]);
        $this->requestCall($f, key: $first['request_key']);
        $this->requestCall($f, key: $other['request_key']);
        $this->assertDatabaseCount('dining_calls', 1);
        $this->requestCall($f);
        $this->assertDatabaseCount('dining_calls', 2);
        $this->post($f['url'], array_replace($first, ['kind' => 'bill']))->assertConflict();
        $this->assertDatabaseHas('dining_tabs', ['id' => $f['tab']->id, 'closed_at' => null]);
    }

    public function test_waiter_sees_live_calls_and_can_attend_but_cannot_rotate_or_cross_store_scope(): void
    {
        $f = $this->fixture();
        $this->requestCall($f, 'bill');
        $id = DB::table('dining_calls')->value('id');
        $waiter = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => 'waiter']);
        $this->actingAs($waiter)->getJson('/dining/live?store_id='.$f['store'])->assertNotFound();
        $this->post('/dining/calls/'.$id.'/attend')->assertNotFound();
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'user_id' => $waiter->id]);
        $this->getJson('/dining/live?store_id='.$f['store'])->assertOk()->assertSee('Solicitou a conta')->assertJsonPath('callIds', [$id]);
        $this->get('/dining?store_id='.$f['store'])->assertOk()->assertSee('data-dining-sound', false)->assertSee('aria-pressed="false"', false);
        $this->getJson('/dining/tabs/'.$f['tab']->id.'/live')->assertOk()->assertSee('Solicitou a conta')->assertDontSee($f['token'])->assertJsonPath('callIds', [$id]);
        $this->post('/dining/tabs/'.$f['tab']->id.'/guest-link')->assertRedirect();
        $this->get('/dining/tabs/'.$f['tab']->id.'/guest-link')->assertOk()->assertDontSee('Gerar novo link');
        $this->post('/dining/tabs/'.$f['tab']->id.'/guest-link/rotate', ['confirmed' => 1, 'expected_hash' => hash('sha256', $f['token'])])->assertForbidden();
        $this->post('/dining/calls/'.$id.'/attend')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dining_calls', ['id' => $id, 'handled_by' => $waiter->id]);
        $this->getJson('/dining/live?store_id='.$f['store'])->assertOk()->assertJsonPath('callIds', []);
        $this->actingAs($f['owner'])->post('/dining/calls/'.$id.'/attend')->assertConflict();
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_rotation_invalidates_old_link_and_preserves_pending_calls(): void
    {
        $f = $this->fixture();
        $this->requestCall($f);
        $rotate = ['expected_hash' => hash('sha256', $f['token']), 'confirmed' => 1];
        $this->post('/dining/tabs/'.$f['tab']->id.'/guest-link/rotate', $rotate)->assertRedirect()->assertSessionHasNoErrors();
        $new = $this->get('/dining/tabs/'.$f['tab']->id.'/guest-link')->viewData('url');
        $this->assertNotSame($f['url'], $new);
        $this->get($f['url'])->assertNotFound();
        $this->post($f['url'], ['kind' => 'bill', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->get($new)->assertOk()->assertSee('A equipe já recebeu seu chamado.');
        $this->post('/dining/tabs/'.$f['tab']->id.'/guest-link/rotate', $rotate)->assertConflict();
        $this->assertDatabaseHas('dining_calls', ['kind' => 'service', 'status' => 'pending']);
    }

    public function test_transfer_keeps_link_current_and_close_cancels_calls_and_invalidates_it(): void
    {
        $f = $this->fixture();
        $this->requestCall($f);
        $target = DB::table('dining_tables')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Mesa 2', 'name_key' => 'mesa 2', 'seats' => 4, 'enabled' => true]);
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', ['from_table_id' => $f['table']->id, 'to_table_id' => $target, 'reason' => 'Mudou', 'confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($f['url'])->assertOk()->assertSee('Mesa 2');
        $this->getJson('/dining/live?store_id='.$f['store'])->assertOk()->assertSee('Mesa 2');
        $this->post('/dining/tabs/'.$f['tab']->id.'/close', ['confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($f['url'])->assertNotFound();
        $this->assertDatabaseHas('dining_calls', ['status' => 'cancelled', 'cancelled_reason' => 'closed', 'pending_key' => null]);
        $this->post('/dining/calls/'.DB::table('dining_calls')->value('id').'/attend')->assertConflict();
        $this->post('/dining/tables/'.$target.'/open', ['guests' => 1, 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $next = DB::table('dining_tabs')->where('active_table_id', $target)->firstOrFail();
        $this->post('/dining/tabs/'.$next->id.'/guest-link')->assertRedirect();
        $newUrl = $this->get('/dining/tabs/'.$next->id.'/guest-link')->assertOk()->viewData('url');
        $this->assertNotSame($f['url'], $newUrl);
        $this->get($f['url'])->assertNotFound();
    }

    public function test_merge_invalidates_source_link_and_retains_target_link_without_new_receipts(): void
    {
        $f = $this->fixture();
        $this->requestCall($f, 'bill');
        $target = DB::table('dining_tables')->insertGetId(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'name' => 'Mesa 2', 'name_key' => 'mesa 2', 'seats' => 4, 'enabled' => true]);
        $this->post('/dining/tables/'.$target.'/open', ['guests' => 2, 'request_key' => (string) Str::uuid()])->assertRedirect();
        $targetTab = DB::table('dining_tabs')->where('active_table_id', $target)->firstOrFail();
        $this->post('/dining/tabs/'.$targetTab->id.'/guest-link')->assertRedirect();
        $targetUrl = $this->get('/dining/tabs/'.$targetTab->id.'/guest-link')->viewData('url');
        $preview = $this->get('/dining/tabs/'.$f['tab']->id.'/merge?target_tab_id='.$targetTab->id)->assertOk();
        $this->post('/dining/tabs/'.$f['tab']->id.'/merge', ['target_tab_id' => $targetTab->id, 'source_hash' => $preview->viewData('sourceHash'), 'target_hash' => $preview->viewData('targetHash'), 'reason' => 'Juntar', 'confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($f['url'])->assertNotFound();
        $this->get($targetUrl)->assertOk();
        $this->assertDatabaseHas('dining_calls', ['dining_tab_id' => $f['tab']->id, 'status' => 'cancelled', 'cancelled_reason' => 'merged']);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_suspension_and_audit_failure_stop_public_requests_without_partial_records(): void
    {
        $f = $this->fixture();
        DB::unprepared("CREATE TRIGGER fail_customer_call BEFORE INSERT ON audit_events WHEN NEW.action = 'dining.customer_requested' BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
        $this->post($f['url'], ['kind' => 'service', 'request_key' => (string) Str::uuid()])->assertStatus(500);
        $this->assertDatabaseCount('dining_calls', 0);
        $this->assertDatabaseCount('dining_call_requests', 0);
        DB::unprepared('DROP TRIGGER fail_customer_call');
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->get($f['url'])->assertNotFound();
        $this->post($f['url'], ['kind' => 'bill', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->get('/dining/tabs/'.$f['tab']->id.'/guest-link')->assertForbidden();
    }

    public function test_attendance_audit_failure_leaves_request_pending_for_retry(): void
    {
        $f = $this->fixture();
        $this->requestCall($f);
        $id = DB::table('dining_calls')->value('id');
        DB::unprepared("CREATE TRIGGER fail_customer_attend BEFORE INSERT ON audit_events WHEN NEW.action = 'dining.customer_attended' BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
        $this->post('/dining/calls/'.$id.'/attend')->assertStatus(500);
        $this->assertDatabaseHas('dining_calls', ['id' => $id, 'status' => 'pending', 'handled_by' => null, 'pending_key' => $f['tab']->id.':service']);
        DB::unprepared('DROP TRIGGER fail_customer_attend');
        $this->post('/dining/calls/'.$id.'/attend')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dining_calls', ['id' => $id, 'status' => 'attended', 'handled_by' => $f['owner']->id]);
    }

    public function test_public_request_validation_and_rate_limit_are_enforced(): void
    {
        $f = $this->fixture();
        $this->post($f['url'], ['kind' => 'pay', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('kind');
        $this->post($f['url'], ['kind' => 'bill', 'request_key' => 'invalid'])->assertSessionHasErrors('request_key');
        for ($i = 0; $i < 6; $i++) {
            $this->requestCall($f);
        }
        $this->post($f['url'], ['kind' => 'service', 'request_key' => (string) Str::uuid()])->assertStatus(429);
        $this->assertDatabaseCount('dining_calls', 1);
    }
}
