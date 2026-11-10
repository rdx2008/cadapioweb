<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class EvolutionService
{
    public function connect(object $connection): array
    {
        $url = DB::table('settings')->where('key', 'evolution_url')->value('value');
        $key = DB::table('settings')->where('key', 'evolution_key')->value('value');
        if (! $url || ! $key) {
            throw ValidationException::withMessages(['whatsapp' => 'Administrador precisa configurar o servidor Evolution.']);
        }
        if ($connection->mode !== 'qr') {
            throw ValidationException::withMessages(['whatsapp' => 'WABA requer credenciais e onboarding Meta. Conexão registrada como pendente de homologação.']);
        }
        $client = Http::withHeaders(['apikey' => Crypt::decryptString($key)])->timeout(15)->connectTimeout(5)->acceptJson();
        $state = $client->get($url.'/instance/connectionState/'.$connection->instance);
        if ($state->status() === 404) {
            $created = $client->post($url.'/instance/create', ['instanceName' => $connection->instance, 'integration' => 'WHATSAPP-BAILEYS', 'qrcode' => true, 'number' => $connection->phone, 'webhook' => ['url' => url('/webhooks/evolution/'.$connection->id), 'headers' => ['x-webhook-token' => Crypt::decryptString($connection->secret)], 'enabled' => true, 'events' => ['CONNECTION_UPDATE']]]);
            $created->throw();
        } elseif (! $state->successful()) {
            $state->throw();
        }
        $result = $client->get($url.'/instance/connect/'.$connection->instance)->throw()->json();

        return is_array($result) ? $result : [];
    }
}
