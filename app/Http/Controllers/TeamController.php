<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;

        return view('team', ['members' => User::where('tenant_id', $tenantId)->where('role', 'staff')->orderBy('name')->get(),
            'stores' => DB::table('stores')->where('tenant_id', $tenantId)->orderBy('name')->get(),
            'memberships' => DB::table('user_store_access')->where('tenant_id', $tenantId)->get()->groupBy('user_id')]);
    }

    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190|unique:users', 'password' => 'required|string|min:12|max:200|confirmed',
            'staff_profile' => 'required|in:operator,kitchen', 'stores' => 'required|array|min:1|max:100', 'stores.*' => 'required|integer|distinct']);
        $this->validateStores($request, $data['stores']);
        DB::transaction(function () use ($request, $data): void {
            $user = new User;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = $data['password'];
            $user->tenant_id = $request->user()->tenant_id;
            $user->role = 'staff';
            $user->staff_profile = $data['staff_profile'];
            $user->access_enabled = true;
            $user->save();
            $this->syncStores($user, $data['stores']);
            Audit::record('team.created', ['user_id' => $user->id, 'profile' => $user->staff_profile, 'stores' => $data['stores']], $user->tenant_id);
        });

        return back()->with('success', 'Acesso da equipe criado. Compartilhe a senha inicial por um canal privado.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['staff_profile' => 'required|in:operator,kitchen', 'access_enabled' => 'required|boolean',
            'stores' => [Rule::requiredIf($request->boolean('access_enabled')), 'nullable', 'array', 'max:100'], 'stores.*' => 'required|integer|distinct']);
        $stores = $data['stores'] ?? [];
        $this->validateStores($request, $stores);
        DB::transaction(function () use ($request, $id, $data, $stores): void {
            $user = User::where('id', $id)->where('tenant_id', $request->user()->tenant_id)->where('role', 'staff')->lockForUpdate()->firstOrFail();
            $user->staff_profile = $data['staff_profile'];
            $user->access_enabled = $data['access_enabled'];
            $user->remember_token = Str::random(60);
            $user->save();
            $this->syncStores($user, $stores);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            Audit::record('team.access_changed', ['user_id' => $user->id, 'enabled' => $user->access_enabled, 'profile' => $user->staff_profile, 'stores' => $stores], $user->tenant_id);
        });

        return back()->with('success', 'Permissões atualizadas. As sessões anteriores desse usuário foram encerradas.');
    }

    private function validateStores(Request $request, array $ids): void
    {
        abort_unless(DB::table('stores')->where('tenant_id', $request->user()->tenant_id)->whereIn('id', $ids)->count() === count($ids), 422, 'Selecione somente lojas da sua empresa.');
    }

    private function syncStores(User $user, array $ids): void
    {
        DB::table('user_store_access')->where('user_id', $user->id)->delete();
        foreach ($ids as $id) {
            DB::table('user_store_access')->insert(['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'store_id' => $id]);
        }
    }
}
