<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('delivery-progress', fn ($request) => Limit::perMinute(6)->by('delivery:'.$request->user()->id.':'.$request->route('id')));
        TrustProxies::at(config('platform.trusted_proxies'));
        $resetUrl = fn ($user, string $token): string => rtrim(config('app.url'), '/').route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false);
        ResetPassword::createUrlUsing($resetUrl);
        ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
            ->subject('Redefina sua senha · CadapioWeb')->greeting('Olá!')
            ->line('Recebemos uma solicitação para redefinir a senha da sua conta.')
            ->action('Redefinir minha senha', $resetUrl($user, $token))
            ->line('Este link expira em '.config('auth.passwords.users.expire').' minutos e pode ser utilizado uma única vez.')
            ->line('Se você não solicitou esta alteração, ignore esta mensagem.')->salutation('Equipe CadapioWeb'));
        if (app()->isProduction() && str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
