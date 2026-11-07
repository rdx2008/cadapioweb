<?php

use App\Support\RuntimeEnvironment;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$connection = null;
$locked = false;

try {
    RuntimeEnvironment::validateKey((string) getenv('APP_KEY'));
    $resolvedUrl = RuntimeEnvironment::url(getenv());
    if ($resolvedUrl === 'http://localhost') {
        fwrite(STDERR, "[startup] Domínio não resolvido pelo Coolify. Configure APP_URL com o domínio público; usando localhost temporariamente.\n");
    }
    putenv('APP_URL='.$resolvedUrl);
    $_ENV['APP_URL'] = $_SERVER['APP_URL'] = $resolvedUrl;

    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    if (filter_var(getenv('RUN_MIGRATIONS') ?: 'true', FILTER_VALIDATE_BOOL)) {
        $connection = $app->make('db')->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('A inicialização automática do container requer PostgreSQL.');
        }
        fwrite(STDOUT, "[startup] Aguardando trava de migração PostgreSQL.\n");
        $connection->select('SELECT pg_advisory_lock(93820176)');
        $locked = true;
        $result = $kernel->call('migrate', ['--force' => true, '--no-interaction' => true]);
        fwrite(STDOUT, $kernel->output());
        if ($result !== 0) {
            throw new RuntimeException('Migrações falharam; o serviço não será iniciado com schema incompleto.');
        }
        fwrite(STDOUT, "[startup] Banco preparado.\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[startup] '.$exception->getMessage()."\n");
    exit(78);
} finally {
    if ($locked && $connection !== null) {
        $connection->select('SELECT pg_advisory_unlock(93820176)');
    }
}
