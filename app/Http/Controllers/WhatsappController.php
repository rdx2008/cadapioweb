<?php

namespace App\Http\Controllers;

use App\Services\EvolutionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsappController extends Controller
{
    public function connect(Request $r, EvolutionService $service)
    {
        $d = $r->validate(['store_id' => 'required|integer', 'phone' => 'required|regex:/^[1-9][0-9]{9,14}$/', 'mode' => 'required|in:qr,waba']);
        $tenant = auth()->user()->tenant_id;
        $store = DB::table('stores')->where('tenant_id', $tenant)->where('id', $d['store_id'])->firstOrFail();
        $id = DB::transaction(function () use ($tenant, $store, $d) {
            DB::table('stores')->where('id', $store->id)->lockForUpdate()->first();
            $old = DB::table('whatsapp_connections')->where('store_id', $store->id)->first();
            if ($old) {
                abort_unless($old->phone === $d['phone'] && $old->mode === $d['mode'], 422, 'Para trocar número/modo, encerre a conexão pelo suporte.');

                return $old->id;
            }

            return DB::table('whatsapp_connections')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store->id, 'phone' => $d['phone'], 'mode' => $d['mode'], 'instance' => 'tenant-'.$tenant.'-'.Str::uuid(), 'secret' => Crypt::encryptString(Str::random(64)), 'created_at' => now(), 'updated_at' => now()]);
        });
        $c = DB::table('whatsapp_connections')->find($id);
        if ($c->mode === 'waba') {
            return back()->with('success', 'Solicitação WABA registrada. Ativação depende de onboarding Meta e homologação; não está conectada.');
        }try {
            $result = $service->connect($c);
        } catch (ConnectionException|RequestException $e) {
            return back()->withErrors(['whatsapp' => 'Evolution não respondeu de forma válida. Verifique o servidor no admin e tente novamente; nenhuma conexão foi confirmada.']);
        }$qr = $result['base64'] ?? $result['qrcode']['base64'] ?? null;
        if (! is_string($qr) || ! preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $qr)) {
            $qr = null;
        }

        return view('whatsapp', ['connection' => $c, 'qr' => $qr]);
    }

    public function webhook(Request $r, int $id)
    {
        $c = DB::table('whatsapp_connections')->find($id);
        abort_unless($c && is_string($r->header('x-webhook-token')) && hash_equals(Crypt::decryptString($c->secret), $r->header('x-webhook-token')), 403);
        abort_unless($r->input('instance') === $c->instance, 403);
        $state = $r->input('data.state');
        if (in_array($state, ['open', 'close', 'connecting'])) {
            DB::table('whatsapp_connections')->where('id', $id)->update(['status' => $state === 'open' ? 'connected' : $state, 'updated_at' => now()]);
        }

        return response()->json(['received' => true]);
    }
}
