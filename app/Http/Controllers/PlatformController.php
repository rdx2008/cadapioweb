<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DispatchService;
use App\Services\FareCalculator;
use App\Support\Audit;
use App\Support\MoneyInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PlatformController extends Controller
{
    private function tid(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    private function store(int $id): object
    {
        return DB::table('stores')->where('tenant_id', $this->tid())->where('id', $id)->firstOrFail();
    }

    public function home()
    {
        return redirect(match (auth()->user()->role) {
            'admin' => '/admin','courier' => '/courier',default => '/dashboard'
        });
    }

    public function admin()
    {
        return view('admin', ['tenants' => DB::table('tenants')->get(), 'drivers' => DB::table('drivers')->join('users', 'users.id', '=', 'drivers.user_id')->select('drivers.*', 'users.name', 'users.email')->get(), 'evolutionUrl' => DB::table('settings')->where('key', 'evolution_url')->value('value')]);
    }

    public function createTenant(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'slug' => 'required|alpha_dash|max:80|unique:tenants', 'email' => 'required|email|unique:users', 'password' => 'required|string|min:12']);
        DB::transaction(function () use ($d) {
            $id = DB::table('tenants')->insertGetId(['name' => $d['name'], 'slug' => $d['slug'], 'created_at' => now(), 'updated_at' => now()]);
            $u = new User;
            $u->name = $d['name'];
            $u->email = $d['email'];
            $u->password = $d['password'];
            $u->tenant_id = $id;
            $u->role = 'owner';
            $u->save();
            Audit::record('tenant.created', ['id' => $id], $id);
        });

        return back()->with('success', 'Empresa e acesso criados.');
    }

    public function module(Request $r, int $id)
    {
        $d = $r->validate(['state' => 'required|in:active,disabled']);
        DB::transaction(function () use ($id, $d) {
            DB::table('tenants')->where('id', $id)->lockForUpdate()->firstOrFail();
            $active = DB::table('rides')->where('tenant_id', $id)->whereIn('status', ['assigned', 'collected'])->exists();
            $state = $d['state'] === 'disabled' && $active ? 'closing' : $d['state'];
            if ($d['state'] === 'disabled') {
                $ids = DB::table('rides')->where('tenant_id', $id)->whereIn('status', ['searching', 'offered', 'broadcast', 'unassigned'])->pluck('id');
                DB::table('offers')->whereIn('ride_id', $ids)->where('status', 'open')->update(['status' => 'revoked']);
                DB::table('driver_claims')->whereIn('ride_id', $ids)->whereNotNull('expires_at')->delete();
                DB::table('rides')->whereIn('id', $ids)->update(['status' => 'cancelled']);
            }DB::table('tenants')->where('id', $id)->update(['courier_module' => $state]);
            Audit::record('module.changed', ['state' => $state], $id);
        });

        return back()->with('success', 'Módulo atualizado.');
    }

    public function approve(Request $r, int $id)
    {
        $d = $r->validate(['status' => 'required|in:approved,blocked']);
        abort_unless(DB::table('drivers')->where('id', $id)->exists(), 404);
        DB::table('drivers')->where('id', $id)->update(['status' => $d['status'], 'available' => false]);
        Audit::record('driver.reviewed', ['driver_id' => $id, 'status' => $d['status']]);

        return back()->with('success', 'Cadastro atualizado.');
    }

    public function evolution(Request $r)
    {
        $d = $r->validate(['url' => 'required|url:https|max:240', 'key' => 'nullable|string|max:1000']);
        $url = rtrim($d['url'], '/');
        abort_if(parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT), 422);
        DB::table('settings')->updateOrInsert(['key' => 'evolution_url'], ['value' => $url]);
        if (! empty($d['key'])) {
            DB::table('settings')->updateOrInsert(['key' => 'evolution_key'], ['value' => Crypt::encryptString($d['key'])]);
        }Audit::record('evolution.configured');

        return back()->with('success', 'Servidor salvo. A chave não será exibida.');
    }

    public function dashboard(Request $request)
    {
        return view('dashboard', ['tenant' => DB::table('tenants')->find($this->tid()), 'stores' => DB::table('stores')->where('tenant_id', $this->tid())->get(), 'orders' => DB::table('orders')->where('tenant_id', $this->tid())->latest()->limit(30)->get(), 'products' => DB::table('products')->where('tenant_id', $this->tid())->get(), 'rides' => DB::table('rides')->where('tenant_id', $this->tid())->latest()->limit(30)->get(), 'connections' => DB::table('whatsapp_connections')->where('tenant_id', $this->tid())->get()] + app(OrderBoardController::class)->data($request));
    }

    public function createStore(Request $r)
    {
        MoneyInput::normalize($r, ['base_fare' => 'base_cents', 'per_km_fare' => 'per_km_cents', 'minimum_fare' => 'minimum_cents', 'customer_fee' => 'customer_fee_cents']);
        $d = $r->validate(['name' => 'required|string|max:100', 'slug' => 'required|alpha_dash|max:100|unique:stores', 'address' => 'required|string|max:240', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'rate_mode' => 'required|in:fixed,per_km,per_ride', 'base_cents' => 'required|integer|min:0|max:100000', 'per_km_cents' => 'required|integer|min:0|max:100000', 'minimum_cents' => 'required|integer|min:0|max:100000', 'customer_fee_cents' => 'required|integer|min:0|max:100000']);
        DB::table('stores')->insert($d + ['tenant_id' => $this->tid(), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Loja criada.');
    }

    public function createProduct(Request $r)
    {
        MoneyInput::normalize($r, ['price' => 'price_cents']);
        $d = $r->validate(['store_id' => 'required|integer', 'name' => 'required|string|max:120', 'category' => 'required|string|max:80', 'description' => 'nullable|string|max:1000', 'price_cents' => 'required|integer|min:1|max:1000000']);
        $this->store($d['store_id']);
        DB::table('products')->insert($d + ['tenant_id' => $this->tid(), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Produto cadastrado.');
    }

    public function toggleProduct(int $id)
    {
        $p = DB::table('products')->where('tenant_id', $this->tid())->where('id', $id)->firstOrFail();
        DB::transaction(function () use ($p) {
            DB::table('stores')->where('id', $p->store_id)->lockForUpdate()->first();
            $current = DB::table('products')->where('id', $p->id)->lockForUpdate()->first();
            DB::table('products')->where('id', $p->id)->update(['available' => ! $current->available, 'updated_at' => now()]);
        });

        return back();
    }

    public function orderStatus(Request $r, int $id)
    {
        $r->validate(['status' => 'required|in:accepted,preparing,ready,completed,cancelled', 'cancellation_reason' => 'required_if:status,cancelled|nullable|string|max:500']);
        DB::transaction(function () use ($r, $id) {
            DB::table('tenants')->where('id', $this->tid())->lockForUpdate()->firstOrFail();
            $o = DB::table('orders')->where('tenant_id', $this->tid())->where('id', $id)->lockForUpdate()->firstOrFail();
            $allowed = ['pending' => ['accepted', 'cancelled'], 'accepted' => ['preparing', 'cancelled'], 'preparing' => ['ready', 'cancelled'], 'ready' => ['completed', 'cancelled']];
            abort_unless(in_array($r->status, $allowed[$o->status] ?? []), 409, 'Transição inválida.');
            abort_if(DB::table('rides')->where('order_id', $id)->whereIn('status', ['assigned', 'collected'])->exists(), 409, 'Trate a corrida atribuída antes de alterar o pedido.');
            DB::table('orders')->where('id', $id)->update(['status' => $r->status, 'cancellation_reason' => $r->status === 'cancelled' ? $r->cancellation_reason : null, 'updated_at' => now()]);
            if (in_array($r->status, ['cancelled', 'completed'])) {
                $ride = DB::table('rides')->where('order_id', $id)->first();
                if ($ride) {
                    DB::table('rides')->where('id', $ride->id)->update(['status' => 'cancelled']);
                    DB::table('offers')->where('ride_id', $ride->id)->where('status', 'open')->update(['status' => 'revoked']);
                    DB::table('driver_claims')->where('ride_id', $ride->id)->delete();
                }
            }Audit::record('order.status', ['id' => $id, 'from' => $o->status, 'to' => $r->status, 'reason' => $r->status === 'cancelled' ? $r->cancellation_reason : null], $this->tid());
        });

        return back()->with('success', 'Pedido atualizado.');
    }

    public function markPaid(int $id)
    {
        DB::transaction(function () use ($id) {
            DB::table('tenants')->where('id', $this->tid())->lockForUpdate()->firstOrFail();
            $o = DB::table('orders')->where('tenant_id', $this->tid())->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('rides')->where('order_id', $id)->whereNotIn('status', ['completed', 'cancelled'])->exists(), 409, 'Recebimento já integra a proposta de corrida. Trate o acerto da entrega antes de alterar.');
            abort_if($o->status === 'cancelled', 409);
            DB::table('orders')->where('id', $id)->update(['payment_status' => 'paid']);
            Audit::record('payment.manual', ['order_id' => $id, 'amount' => $o->total_cents], $this->tid());
        });

        return back()->with('success', 'Recebimento manual registrado e auditado.');
    }

    public function dispatch(Request $r, int $id, FareCalculator $calculator, DispatchService $service)
    {
        MoneyInput::normalize($r, ['fare' => 'fare_cents']);
        $d = $r->validate(['route_meters' => 'nullable|integer|min:1|max:200000', 'fare_cents' => 'nullable|integer|min:1|max:100000']);
        $rideId = DB::transaction(function () use ($id, $d, $calculator) {
            $tenant = DB::table('tenants')->where('id', $this->tid())->lockForUpdate()->first();
            abort_unless($tenant->courier_module === 'active', 403);
            $o = DB::table('orders')->where('tenant_id', $this->tid())->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($o->status === 'ready' && $o->mode === 'delivery', 409, 'Pedido precisa estar pronto para entrega.');
            $existing = DB::table('rides')->where('order_id', $id)->first();
            if ($existing) {
                abort_unless(in_array($existing->status, ['unassigned', 'searching', 'offered', 'broadcast']), 409);
                if ($existing->status === 'unassigned') {
                    DB::table('rides')->where('id', $existing->id)->update(['status' => 'searching']);
                }

                return $existing->id;
            }$s = $this->store($o->store_id);
            $fare = $calculator->quote($s, $d['route_meters'] ?? null, $d['fare_cents'] ?? null);

            return DB::table('rides')->insertGetId(['tenant_id' => $this->tid(), 'store_id' => $s->id, 'order_id' => $id, 'status' => 'searching', 'rate_mode' => $s->rate_mode, 'fare_cents' => $fare, 'route_meters' => $d['route_meters'] ?? null, 'cash_cents' => $o->payment_status === 'paid' ? 0 : $o->total_cents, 'created_at' => now(), 'updated_at' => now()]);
        });
        $service->dispatch($rideId);

        return back()->with('success', 'Despacho processado. Confira a situação da corrida.');
    }

    public function settle(int $id)
    {
        $ride = DB::table('rides')->where('tenant_id', $this->tid())->where('id', $id)->firstOrFail();
        abort_unless($ride->status === 'completed', 409);
        DB::table('rides')->where('id', $id)->update(['settled' => true]);
        Audit::record('ride.settled', ['ride' => $id, 'net_cents' => $ride->fare_cents - $ride->cash_cents], $this->tid());

        return back()->with('success', 'Acerto externo confirmado.');
    }
}
