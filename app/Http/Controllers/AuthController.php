<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TenantState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($data + ['access_enabled' => true])) {
            return back()->withErrors(['email' => 'E-mail ou senha inválidos.'])->onlyInput('email');
        }
        if (in_array(Auth::user()->role, ['owner', 'staff'], true) && ! app(TenantState::class)->active(Auth::user()->tenant_id)) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return back()->withErrors(['email' => 'O acesso desta empresa está indisponível. Entre em contato com a administração.'])->onlyInput('email');
        }
        $r->session()->regenerate();

        return redirect('/app');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function register(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190|unique:users', 'password' => 'required|string|min:12|confirmed', 'phone' => 'required|string|max:25', 'vehicle' => 'required|string|max:80']);
        DB::transaction(function () use ($d) {
            $u = new User;
            $u->name = $d['name'];
            $u->email = $d['email'];
            $u->password = $d['password'];
            $u->role = 'courier';
            $u->save();
            DB::table('drivers')->insert(['user_id' => $u->id, 'phone' => $d['phone'], 'vehicle' => $d['vehicle'], 'created_at' => now(), 'updated_at' => now()]);
        });

        return redirect('/login')->with('success', 'Cadastro recebido. Entre para acompanhar sua aprovação.');
    }
}
