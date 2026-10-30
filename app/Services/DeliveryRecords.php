<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
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
}
