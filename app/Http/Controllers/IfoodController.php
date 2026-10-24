<?php

namespace App\Http\Controllers;

use App\Services\IfoodClient;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IfoodController extends Controller
{
    public function admin(): View
    {
        return view('integrations.ifood-admin', [
            'application' => DB::table('ifood_apps')->where('environment', 'test')->select('client_id', 'updated_at', 'last_polled_at', 'last_poll_error')->first(),
            'events' => DB::table('ifood_events')->where(fn ($query) => $query->whereIn('status', ['received', 'processing', 'error'])->orWhere(fn ($ignored) => $ignored->where('status', 'ignored')->where('code', '!=', 'HBT')))->orderByDesc('id')->limit(20)->get(['external_id', 'external_order_id', 'code', 'status', 'attempts', 'retry_at', 'last_error']),
            'stores' => DB::table('stores')->join('tenants', 'tenants.id', '=', 'stores.tenant_id')->select('stores.id', 'stores.name', 'tenants.name as tenant_name')->orderBy('tenants.name')->get(),
            'connections' => DB::table('ifood_connections')->join('stores', 'stores.id', '=', 'ifood_connections.store_id')
                ->join('tenants', 'tenants.id', '=', 'ifood_connections.tenant_id')->select('ifood_connections.*', 'stores.name as store_name', 'tenants.name as tenant_name')->get(),
        ]);
    }

    public function owner(Request $request): View
    {
        return view('integrations.ifood-owner', ['connections' => DB::table('ifood_connections')
            ->join('stores', 'stores.id', '=', 'ifood_connections.store_id')
            ->where('ifood_connections.tenant_id', $request->user()->tenant_id)
            ->select('ifood_connections.*', 'stores.name as store_name')->get()] + $this->orderData($request));
    }

    public function configure(Request $request): RedirectResponse
    {
        $data = $request->validate(['client_id' => 'required|string|max:255', 'client_secret' => 'nullable|string|max:4000']);
        DB::transaction(function () use ($data): void {
            $app = DB::table('ifood_apps')->where('environment', 'test')->lockForUpdate()->first();
            if (empty($data['client_secret']) && (! $app || $app->client_id !== $data['client_id'])) {
                throw ValidationException::withMessages(['client_secret' => 'Informe a chave ao cadastrar ou trocar o Client ID.']);
            }
            $values = [
                'client_id' => $data['client_id'],
                'client_secret' => ! empty($data['client_secret']) ? Crypt::encryptString($data['client_secret']) : $app->client_secret,
                'revision' => (string) Str::uuid(), 'updated_at' => now(),
            ];
            if ($app) {
                DB::table('ifood_apps')->where('id', $app->id)->update($values);
                DB::table('ifood_connections')->where('app_id', $app->id)->update(['receiving_enabled' => false, 'status' => 'pending', 'checked_at' => null, 'operations' => null, 'last_error' => null, 'updated_at' => now()]);
            } else {
                DB::table('ifood_apps')->insert($values + ['environment' => 'test', 'created_at' => now()]);
            }
            Audit::record('ifood.app_configured', ['environment' => 'test']);
        });

        return back()->with('success', 'Aplicativo de teste salvo. As conexões existentes precisam ser verificadas novamente.');
    }

    public function test(IfoodClient $client): RedirectResponse
    {
        $client->token($this->application());
        Audit::record('ifood.authentication_checked', ['environment' => 'test']);

        return back()->with('success', 'Autenticação aceita pela API. Isso não comprova homologação nem habilita o recebimento de pedidos.');
    }

    public function connect(Request $request, IfoodClient $client): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer|exists:stores,id', 'merchant_id' => 'required|uuid', 'test_merchant_confirmed' => 'accepted']);
        $data['merchant_id'] = strtolower($data['merchant_id']);
        $app = $this->application();
        $snapshot = $client->inspect($app, $data['merchant_id']);
        DB::transaction(function () use ($data, $app, $snapshot): void {
            $this->lockApplication($app);
            $store = DB::table('stores')->where('id', $data['store_id'])->lockForUpdate()->firstOrFail();
            if (DB::table('ifood_events')->where('app_id', $app->id)->where('merchant_id', $data['merchant_id'])->where('store_id', '!=', $store->id)->exists()) {
                throw ValidationException::withMessages(['ifood' => 'Este Merchant ID já possui histórico em outra loja. A transferência exige revisão do histórico.']);
            }
            if (DB::table('ifood_connections')->where('store_id', $store->id)->exists() || DB::table('ifood_connections')->where('app_id', $app->id)->where('merchant_id', $data['merchant_id'])->exists()) {
                throw ValidationException::withMessages(['ifood' => 'Loja local ou Merchant ID já vinculado. Remova o vínculo anterior antes de alterar.']);
            }
            DB::table('ifood_connections')->insert([
                'app_id' => $app->id, 'tenant_id' => $store->tenant_id, 'store_id' => $store->id,
                'merchant_id' => $data['merchant_id'], 'merchant_name' => $snapshot['merchant_name'],
                'status' => 'verified', 'operations' => json_encode($snapshot['operations'], JSON_THROW_ON_ERROR),
                'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('ifood.store_linked', ['store_id' => $store->id, 'merchant_id' => $data['merchant_id'], 'environment' => 'test'], $store->tenant_id);
        });

        return back()->with('success', 'Vínculo de teste verificado. O recebimento permanece desligado até ser habilitado explicitamente.');
    }

    public function check(Request $request, int $id, IfoodClient $client): RedirectResponse
    {
        $connection = $this->connection($request, $id);
        $app = $this->application();
        try {
            $snapshot = $client->inspect($app, $connection->merchant_id);
        } catch (ValidationException $exception) {
            DB::transaction(function () use ($app, $connection, $exception): void {
                $this->lockApplication($app);
                DB::table('ifood_connections')->where('id', $connection->id)->where('merchant_id', $connection->merchant_id)->update([
                    'status' => 'error', 'last_error' => $exception->errors()['ifood'][0], 'operations' => null, 'updated_at' => now(),
                ]);
            });
            throw $exception;
        }
        DB::transaction(function () use ($app, $connection, $snapshot): void {
            $this->lockApplication($app);
            DB::table('ifood_connections')->where('id', $connection->id)->where('merchant_id', $connection->merchant_id)->update([
                'status' => 'verified', 'merchant_name' => $snapshot['merchant_name'],
                'operations' => json_encode($snapshot['operations'], JSON_THROW_ON_ERROR), 'last_error' => null,
                'checked_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('ifood.store_checked', ['connection_id' => $connection->id], $connection->tenant_id);
        });

        return back()->with('success', 'Consulta atualizada. A situação exibida é uma fotografia da última verificação.');
    }

    public function disconnect(int $id): RedirectResponse
    {
        DB::transaction(function () use ($id): void {
            $app = $this->application();
            $this->lockApplication($app);
            $connection = DB::table('ifood_connections')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('ifood_events')->where('connection_id', $id)->whereIn('status', ['received', 'processing', 'error'])->exists()
                || DB::table('ifood_orders')->where('app_id', $app->id)->where('merchant_id', $connection->merchant_id)->whereNotIn('status', ['completed', 'cancelled'])->exists(), 409, 'Há eventos pendentes ou pedidos ativos. Desligue novos recebimentos e resolva as pendências antes de remover o vínculo.');
            DB::table('ifood_connections')->where('id', $id)->delete();
            Audit::record('ifood.store_unlinked', ['store_id' => $connection->store_id, 'merchant_id' => $connection->merchant_id], $connection->tenant_id);
        });

        return back()->with('success', 'Vínculo local removido. A autorização no portal iFood permanece até ser revogada por lá.');
    }

    public function receiving(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean', 'test_only_confirmed' => 'accepted_if:enabled,1']);
        DB::transaction(function () use ($data, $id): void {
            $app = $this->application();
            $this->lockApplication($app);
            $connection = DB::table('ifood_connections')->where('id', $id)->where('app_id', $app->id)->lockForUpdate()->firstOrFail();
            abort_if($data['enabled'] && $connection->status !== 'verified', 409, 'Verifique o acesso à loja antes de habilitar o recebimento.');
            abort_if($data['enabled'] && ! $connection->receiving_enabled && DB::table('ifood_connections')->where('app_id', $app->id)->where('receiving_enabled', true)->count() >= 100, 409, 'Limite de 100 lojas no piloto de recebimento.');
            DB::table('ifood_connections')->where('id', $id)->update(['receiving_enabled' => $data['enabled'], 'updated_at' => now()]);
            Audit::record('ifood.receiving_changed', ['connection_id' => $id, 'enabled' => (bool) $data['enabled']], $connection->tenant_id);
        });

        return back()->with('success', $data['enabled'] ? 'Recebimento de teste habilitado. Pedidos não serão aceitos automaticamente.' : 'Novos recebimentos desligados. Eventos já armazenados continuam sendo processados.');
    }

    public function ordersLive(Request $request): JsonResponse
    {
        $html = view('integrations.ifood-orders', $this->orderData($request))->render();

        return response()->json(['html' => $html, 'signature' => hash('sha256', $html)])->header('Cache-Control', 'no-store');
    }

    public function showOrder(Request $request, int $id): View
    {
        $order = DB::table('ifood_orders')->where('tenant_id', $request->user()->tenant_id)->where('id', $id)->firstOrFail();

        return view('integrations.ifood-order', ['order' => $order, 'timezone' => DB::table('stores')->where('id', $order->store_id)->value('timezone'), 'snapshot' => json_decode(Crypt::decryptString($order->snapshot), true, flags: JSON_THROW_ON_ERROR)]);
    }

    private function orderData(Request $request): array
    {
        $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $query = DB::table('ifood_events')->where('tenant_id', $request->user()->tenant_id)->whereIn('status', ['received', 'processing', 'error']);

        return [
            'orders' => DB::table('ifood_orders')->join('stores', 'stores.id', '=', 'ifood_orders.store_id')->where('ifood_orders.tenant_id', $request->user()->tenant_id)
                ->select('ifood_orders.*', 'stores.name as store_name', 'stores.timezone as store_timezone')->orderByDesc('ifood_orders.ordered_at')->orderByDesc('ifood_orders.id')->paginate(30)->withPath(route('ifood.owner')),
            'pendingEvents' => (clone $query)->count(), 'failedEvents' => (clone $query)->where('status', 'error')->count(),
            'eventErrors' => (clone $query)->where('status', 'error')->latest('id')->limit(5)->get(['external_order_id', 'last_error', 'retry_at']),
        ];
    }

    private function application(): object
    {
        $app = DB::table('ifood_apps')->where('environment', 'test')->first();
        if (! $app) {
            throw ValidationException::withMessages(['ifood' => 'O superadmin precisa configurar o aplicativo iFood de teste.']);
        }

        return $app;
    }

    private function lockApplication(object $snapshot): void
    {
        $current = DB::table('ifood_apps')->where('id', $snapshot->id)->lockForUpdate()->firstOrFail();
        if ($current->revision !== $snapshot->revision) {
            throw ValidationException::withMessages(['ifood' => 'A configuração mudou durante a consulta. Verifique novamente.']);
        }
    }

    private function connection(Request $request, int $id): object
    {
        return DB::table('ifood_connections')->where('id', $id)
            ->when($request->user()->role !== 'admin', fn ($query) => $query->where('tenant_id', $request->user()->tenant_id))->firstOrFail();
    }
}
