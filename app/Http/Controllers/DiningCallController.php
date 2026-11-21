<?php

namespace App\Http\Controllers;

use App\Services\DiningCalls;
use App\Services\DiningRoom;
use App\Support\Audit;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DiningCallController extends Controller
{
    private function page(string $view, array $data = [], int $status = 200): Response
    {
        return response()->view($view, $data, $status)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function guest(string $token, DiningCalls $calls): Response
    {
        $tab = $calls->publicTab($token);
        if (! $tab) {
            return $this->page('dining.guest-unavailable', status: 404);
        }
        $store = DB::table('stores')->find($tab->store_id);
        $pending = DB::table('dining_calls')->where('dining_tab_id', $tab->id)->where('status', 'pending')->pluck('kind')->all();

        return $this->page('dining.guest', ['token' => $token, 'tableName' => $tab->table_name, 'storeName' => $store->name, 'pending' => $pending]);
    }

    public function request(Request $request, string $token, DiningCalls $calls): RedirectResponse
    {
        $data = $request->validate(['kind' => 'required|in:service,bill', 'request_key' => 'required|uuid']);
        $calls->request($token, $data['kind'], $data['request_key']);

        return redirect()->route('dining.guest', $token)->with('success', 'Solicitação registrada. A equipe vai atender você.');
    }

    public function issue(Request $request, int $id, DiningRoom $room, DiningCalls $calls): RedirectResponse
    {
        DB::transaction(function () use ($request, $id, $room, $calls): void {
            $user = $room->member($request->user(), true);
            $calls->issue($room->tab($user, $id, true), $user);
        }, 3);

        return redirect()->route('dining.guest-link', $id);
    }

    public function share(Request $request, int $id, DiningRoom $room): Response
    {
        $user = $room->member($request->user());
        $tab = $room->tab($user, $id);
        abort_if($tab->closed_at !== null || $tab->guest_token === null, 404);

        $url = route('dining.guest', Crypt::decryptString($tab->guest_token));

        return $this->page('dining.guest-link', ['tab' => $tab, 'store' => DB::table('stores')->find($tab->store_id), 'waiter' => $user->staff_profile === 'waiter', 'url' => $url, 'qr' => $this->qr($url), 'canRotate' => $user->role === 'owner' || $user->staff_profile === 'operator']);
    }

    private function qr(string $url): string
    {
        return (new SvgWriter)->write(new QrCode(data: $url, size: 320, margin: 40))->getDataUri();
    }

    public function printLink(Request $request, int $id, DiningRoom $room): Response
    {
        $user = $room->member($request->user());
        $tab = $room->tab($user, $id);
        abort_if($tab->closed_at !== null || $tab->guest_token === null, 404);
        $data = $request->validate(['revision' => 'required|string|size:64']);
        abort_unless(hash_equals($tab->guest_token_hash, $data['revision']), 409, 'O link mudou. Abra novamente o link do cliente para imprimir.');
        $url = route('dining.guest', Crypt::decryptString($tab->guest_token));

        return $this->page('dining.guest-print', ['tab' => $tab, 'store' => DB::table('stores')->find($tab->store_id), 'qr' => $this->qr($url)]);
    }

    public function rotate(Request $request, int $id, DiningRoom $room, DiningCalls $calls): RedirectResponse
    {
        $data = $request->validate(['confirmed' => 'accepted', 'expected_hash' => 'required|string|size:64']);
        DB::transaction(function () use ($request, $id, $room, $calls, $data): void {
            $user = $room->member($request->user(), true, true);
            $calls->issue($room->tab($user, $id, true), $user, $data['expected_hash']);
        }, 3);

        return redirect()->route('dining.guest-link', $id)->with('success', 'Novo link gerado. O anterior deixou de funcionar.');
    }

    public function attend(Request $request, int $id, DiningRoom $room): RedirectResponse
    {
        $storeId = DB::transaction(function () use ($request, $id, $room): int {
            $user = $room->member($request->user(), true);
            $call = DB::table('dining_calls')->where('tenant_id', $user->tenant_id)->whereIn('store_id', $room->stores($user)->select('id'))->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($call->status === 'attended') {
                abort_unless($call->handled_by === $user->id, 409, 'Outra pessoa já registrou este atendimento.');

                return $call->store_id;
            }
            abort_unless($call->status === 'pending', 409, 'Esta solicitação não está mais pendente.');
            DB::table('dining_calls')->where('id', $id)->update(['status' => 'attended', 'pending_key' => null, 'handled_by' => $user->id, 'handled_at' => now()]);
            Audit::record('dining.customer_attended', ['call_id' => $id, 'tab_id' => $call->dining_tab_id], $user->tenant_id);

            return $call->store_id;
        }, 3);

        return redirect()->route('dining.index', ['store_id' => $storeId])->with('success', 'Atendimento registrado. A comanda e os pagamentos permanecem sob conferência.');
    }
}
