<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeliveryRecords
{
    public const KINDS = ['customer_absent' => 'Cliente ausente', 'address_issue' => 'Problema no endereço', 'vehicle_issue' => 'Problema com a moto', 'no_courier' => 'Sem motoboy disponível', 'other' => 'Outra ocorrência'];

    public const STATUSES = ['searching' => 'Buscando motoboy', 'offered' => 'Ofertada na fila', 'broadcast' => 'Ofertada na região', 'unassigned' => 'Sem motoboy', 'assigned' => 'Atribuída', 'collected' => 'Em entrega', 'returning' => 'Em retorno à loja', 'returned' => 'Devolvido à loja', 'completed' => 'Concluída', 'cancelled' => 'Cancelada'];

    public const PHOTO_RULES = 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=4000,max_height=4000';

    public function photo(?UploadedFile $file, int $tenantId): ?string
    {
        if (! $file) {
            return null;
        }
        if (! function_exists('imagewebp')) {
            throw ValidationException::withMessages(['photo' => 'O envio de fotos está temporariamente indisponível. Tente novamente ou registre sem foto.']);
        }
        $image = @imagecreatefromstring($file->get());
        if (! $image) {
            throw ValidationException::withMessages(['photo' => 'Não foi possível abrir a foto. Use JPG, PNG ou WebP.']);
        }
        ob_start();
        imagewebp($image, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($image);
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 2097152) {
            throw ValidationException::withMessages(['photo' => 'Não foi possível preparar a foto. Escolha uma imagem menor.']);
        }
        $path = 'tenants/'.$tenantId.'/delivery-evidence/'.Str::uuid().'.webp';
        if (! Storage::disk('local')->put($path, $bytes) || ! Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['photo' => 'Não foi possível salvar a foto. Tente novamente.']);
        }

        return $path;
    }

    /**
     * Records an incident on a ride already scoped to the acting user. Repeating the same request key is a no-op.
     *
     * @param  array{request_key: string, kind: string, description: string}  $data
     */
    public function recordIncident(object $user, object $scoped, array $data, ?UploadedFile $photo): void
    {
        $path = null;
        try {
            DB::transaction(function () use ($user, $scoped, $data, $photo, &$path): void {
                DB::table('tenants')->where('id', $scoped->tenant_id)->lockForUpdate()->firstOrFail();
                $ride = DB::table('rides')->where('id', $scoped->id)->lockForUpdate()->firstOrFail();
                abort_unless($ride->tenant_id === $scoped->tenant_id && ($user->role !== 'courier' || $ride->driver_id === $scoped->driver_id), 409, 'A corrida já foi encerrada.');
                $existing = DB::table('delivery_incidents')->where('ride_id', $ride->id)->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    abort_unless($existing->author_id === $user->id, 409);

                    return;
                }
                $allowed = $user->role === 'courier' ? ['assigned', 'collected', 'returning'] : ['searching', 'offered', 'broadcast', 'unassigned', 'assigned', 'collected', 'returning'];
                abort_unless(in_array($ride->status, $allowed, true), 409, 'A corrida já foi encerrada.');
                abort_if($user->role === 'courier' && $data['kind'] === 'no_courier', 422);
                $path = $this->photo($photo, $ride->tenant_id);
                $incidentId = DB::table('delivery_incidents')->insertGetId(['tenant_id' => $ride->tenant_id, 'ride_id' => $ride->id, 'author_id' => $user->id, 'request_key' => $data['request_key'], 'kind' => $data['kind'], 'description' => $data['description'], 'photo_path' => $path, 'created_at' => now(), 'updated_at' => now()]);
                Audit::record('delivery.incident.created', ['incident_id' => $incidentId, 'ride_id' => $ride->id, 'kind' => $data['kind']], $ride->tenant_id);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }
}
