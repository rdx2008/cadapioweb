<?php

use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:admin {email} {--name=Administrador}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $this->error('E-mail inválido.');
    }if (User::where('email', $email)->exists()) {
        return $this->error('Usuário já existe.');
    }$password = $this->secret('Senha (mínimo 12 caracteres)');
    if (strlen($password ?? '') < 12) {
        return $this->error('Senha muito curta.');
    }$u = new User;
    $u->name = $this->option('name');
    $u->email = $email;
    $u->password = $password;
    $u->role = 'admin';
    $u->save();
    $this->info('Administrador criado.');
});
Artisan::command('dispatch:tick', function (DispatchService $s) {
    DB::table('rides')->whereIn('status', ['searching', 'offered', 'broadcast'])->orderBy('id')->pluck('id')->each(fn ($id) => $s->dispatch($id));
    $this->info('Despacho reconciliado.');
});
Schedule::command('dispatch:tick')->everyTenSeconds()->withoutOverlapping();
