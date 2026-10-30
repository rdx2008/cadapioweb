<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordRecoveryController extends Controller
{
    public function requestForm(): View
    {
        return view('auth.forgot-password', ['available' => $this->available()]);
    }

    private function available(): bool
    {
        if (config('mail.default') === 'smtp' && trim((string) config('mail.mailers.smtp.host')) === '') {
            return false;
        }

        return in_array(config('mail.default'), ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'sendmail'], true);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:190']);
        if (! $this->available()) {
            return back()->withErrors(['email' => 'A recuperação por e-mail está indisponível no momento. Contate o suporte.'])->onlyInput('email');
        }
        try {
            Password::sendResetLink($data);
        } catch (TransportExceptionInterface) {
            Log::warning('Não foi possível enviar uma mensagem de recuperação de senha. Verifique o serviço de e-mail.');
        }

        return back()->with('success', 'Se este e-mail estiver cadastrado, você receberá um link para redefinir a senha. Confira também a pasta de spam.')->onlyInput('email');
    }

    public function resetForm(Request $request, string $token): Response
    {
        $data = $request->validate(['email' => 'nullable|email|max:190']);

        return response()->view('auth.reset-password', ['token' => $token, 'email' => $data['email'] ?? ''])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:190', 'token' => 'required|string|max:256', 'password' => 'required|string|min:12|max:200|confirmed']);
        $status = DB::transaction(function () use ($data) {
            DB::table(config('auth.passwords.users.table'))->where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($data, function ($user, string $password): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                Audit::record('account.password_reset', ['user_id' => $user->id], $user->tenant_id);
                event(new PasswordReset($user));
            });
        });
        if ($status !== Password::PasswordReset) {
            return back()->withErrors(['email' => 'Este link é inválido ou expirou. Solicite um novo link de recuperação.'])->onlyInput('email');
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Senha alterada. Entre novamente com sua nova senha.');
    }
}
