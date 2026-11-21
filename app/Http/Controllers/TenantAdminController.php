<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TenantState;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TenantAdminController extends Controller
{
    public function edit(int $id): View
    {
        $company = DB::table('tenants')->where('id', $id)->firstOrFail();

        return view('admin.tenant', ['company' => $company, 'owner' => User::where('tenant_id', $id)->where('role', 'owner')->orderBy('id')->first(), 'counts' => ['stores' => DB::table('stores')->where('tenant_id', $id)->count(), 'orders' => DB::table('orders')->where('tenant_id', $id)->count(), 'users' => DB::table('users')->where('tenant_id', $id)->count()]]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $company = DB::table('tenants')->where('id', $id)->whereNull('deleted_at')->firstOrFail();
        $owner = User::where('tenant_id', $id)->where('role', 'owner')->orderBy('id')->first();
        $data = $request->validate(['name' => 'required|string|max:100', 'slug' => ['required', 'alpha_dash:ascii', 'max:80', Rule::unique('tenants')->ignore($id)], 'owner_name' => $owner ? 'required|string|max:120' : 'nullable', 'email' => $owner ? ['required', 'email', 'max:190', Rule::unique('users')->ignore($owner->id)] : 'nullable']);
        DB::transaction(function () use ($id, $data, $owner): void {
            $locked = DB::table('tenants')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if($locked->deleted_at, 409);
            DB::table('tenants')->where('id', $id)->update(['name' => $data['name'], 'slug' => $data['slug'], 'updated_at' => now()]);
            if ($owner) {
                $owner->name = $data['owner_name'];
                $owner->email = $data['email'];
                $owner->save();
            }
            Audit::record('tenant.updated', ['id' => $id, 'name' => $data['name'], 'slug' => $data['slug'], 'owner_id' => $owner?->id], $id);
        });

        return back()->with('success', 'Empresa atualizada.');
    }

    public function status(Request $request, int $id, TenantState $state): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:active,suspended', 'reason' => 'required_if:status,suspended|nullable|string|max:500']);
        DB::transaction(function () use ($id, $data, $state): void {
            $company = DB::table('tenants')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_if($company->deleted_at, 409, 'Restaure a empresa antes de alterar a situação.');
            DB::table('tenants')->where('id', $id)->update(['status' => $data['status'], 'suspension_reason' => $data['status'] === 'suspended' ? $data['reason'] : null, 'suspended_at' => $data['status'] === 'suspended' ? now() : null, 'updated_at' => now()]);
            if ($data['status'] === 'suspended') {
                $state->stopNewOffers($id);
            }
            Audit::record('tenant.status', ['id' => $id, 'from' => $company->status, 'to' => $data['status'], 'reason' => $data['reason'] ?? null], $id);
        });

        return back()->with('success', $data['status'] === 'active' ? 'Empresa reativada.' : 'Empresa suspensa.');
    }

    public function delete(Request $request, int $id, TenantState $state): RedirectResponse
    {
        $data = $request->validate(['confirm_slug' => 'required|string|max:80', 'current_password' => 'required|current_password:web']);
        DB::transaction(function () use ($id, $data, $state): void {
            $company = DB::table('tenants')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($data['confirm_slug'] !== $company->slug) {
                throw ValidationException::withMessages(['confirm_slug' => 'Digite o identificador exato da empresa para confirmar.']);
            }
            if (DB::table('orders')->where('tenant_id', $id)->whereIn('status', ['pending', 'accepted', 'preparing', 'ready'])->exists() || DB::table('rides')->where('tenant_id', $id)->whereIn('status', ['assigned', 'collected', 'returning'])->exists()) {
                throw ValidationException::withMessages(['confirm_slug' => 'Conclua ou cancele os pedidos e finalize as entregas em andamento antes de excluir a empresa.']);
            }
            $state->stopNewOffers($id);
            DB::table('tenants')->where('id', $id)->update(['deleted_at' => now(), 'status' => 'suspended', 'updated_at' => now()]);
            Audit::record('tenant.deleted', ['id' => $id, 'slug' => $company->slug, 'history_preserved' => true], $id);
        });

        return redirect('/admin#empresas')->with('success', 'Empresa excluída da operação. Histórico preservado para consulta.');
    }

    public function restore(int $id): RedirectResponse
    {
        DB::transaction(function () use ($id): void {
            DB::table('tenants')->where('id', $id)->whereNotNull('deleted_at')->lockForUpdate()->firstOrFail();
            DB::table('tenants')->where('id', $id)->update(['deleted_at' => null, 'status' => 'suspended', 'updated_at' => now()]);
            Audit::record('tenant.restored', ['id' => $id, 'status' => 'suspended'], $id);
        });

        return back()->with('success', 'Empresa restaurada e mantida suspensa. Reative quando desejar.');
    }
}
