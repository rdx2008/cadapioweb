<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\BuildsPlatform;
use Tests\TestCase;

class WhatsappIntegrationTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_qr_reuses_instance_and_keeps_global_key_server_side(): void
    {
        $t = $this->tenant();
        $s = $this->store($t);
        $owner = $this->owner($t);
        DB::table('settings')->insert([['key' => 'evolution_url', 'value' => 'https://evolution.example.test'], ['key' => 'evolution_key', 'value' => Crypt::encryptString('global-secret')]]);
        Http::fake(['*/instance/connectionState/*' => Http::response([], 404), '*/instance/create' => Http::response(['instance' => []]), '*/instance/connect/*' => Http::response(['base64' => 'data:image/png;base64,YQ=='])]);
        $this->actingAs($owner)->post('/whatsapp/connect', ['store_id' => $s, 'phone' => '5511999999999', 'mode' => 'qr'])->assertOk()->assertSee('data:image/png;base64,YQ==', false)->assertDontSee('global-secret');
        $this->assertDatabaseCount('whatsapp_connections', 1);
        Http::fake(['*/instance/connectionState/*' => Http::response(['state' => 'connecting']), '*/instance/connect/*' => Http::response(['base64' => 'data:image/png;base64,YQ=='])]);
        $this->post('/whatsapp/connect', ['store_id' => $s, 'phone' => '5511999999999', 'mode' => 'qr'])->assertOk();
        $this->assertDatabaseCount('whatsapp_connections', 1);
    }

    public function test_webhook_requires_secret_and_matching_instance(): void
    {
        $t = $this->tenant();
        $s = $this->store($t);
        $id = DB::table('whatsapp_connections')->insertGetId(['tenant_id' => $t, 'store_id' => $s, 'phone' => '5511999999999', 'mode' => 'qr', 'instance' => 'tenant-1-test', 'secret' => Crypt::encryptString('token')]);
        $this->postJson('/webhooks/evolution/'.$id, ['instance' => 'tenant-1-test', 'data' => ['state' => 'open']])->assertForbidden();
        $this->withHeader('x-webhook-token', 'token')->postJson('/webhooks/evolution/'.$id, ['instance' => 'other', 'data' => ['state' => 'open']])->assertForbidden();
        $this->withHeader('x-webhook-token', 'token')->postJson('/webhooks/evolution/'.$id, ['instance' => 'tenant-1-test', 'data' => ['state' => 'open']])->assertOk();
        $this->assertDatabaseHas('whatsapp_connections', ['id' => $id, 'status' => 'connected']);
    }
}
