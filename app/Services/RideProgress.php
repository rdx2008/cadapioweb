<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Collection and completion of a ride by its assigned courier.
 */
class RideProgress
{
    public const RULES = ['status' => 'required|in:collected,completed', 'expected_round' => 'nullable|integer|min:1', 'delivery_code' => 'prohibited_unless:status,completed|nullable|string|regex:/^[0-9]{6}$/D', 'received_by' => 'prohibited_unless:status,completed|nullable|string|max:100', 'photo' => 'prohibited_unless:status,completed|'.DeliveryRecords::PHOTO_RULES];

    public function __construct(private DeliveryConfirmation $confirmation, private DeliveryRecords $records, private OnlinePayment $payment) {}

    /**
     * Advances the ride and returns its fresh row. When $tolerateRepeat is true, re-sending an
     * already applied step (an offline retry from the app) is accepted without side effects.
     *
     * @param  array{status: string, expected_round?: int|string|null, delivery_code?: string|null, received_by?: string|null}  $data
     */
    public function advance(object $driver, int $rideId, array $data, ?UploadedFile $photo, bool $tolerateRepeat = false): object
    {
        $status = $data['status'];
        $expectedRound = isset($data['expected_round']) && $data['expected_round'] !== '' ? (int) $data['expected_round'] : null;
        $path = null;
        try {
            DB::transaction(function () use ($driver, $rideId, $data, $photo, $status, $expectedRound, $tolerateRepeat, &$path): void {
                $tenantId = DB::table('rides')->where('id', $rideId)->where('driver_id', $driver->id)->value('tenant_id');
                abort_unless($tenantId, 404);
                DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->firstOrFail();
                $ride = DB::table('rides')->where('id', $rideId)->where('driver_id', $driver->id)->lockForUpdate()->firstOrFail();
                abort_if($expectedRound !== null && $ride->dispatch_round !== $expectedRound, 409, 'A tentativa mudou. Atualize a página.');
                if ($tolerateRepeat && $ride->status === $status) {
                    return;
                }
                abort_unless(($ride->status === 'assigned' && $status === 'collected') || ($ride->status === 'collected' && $status === 'completed'), 409);
                $order = DB::table('orders')->where('id', $ride->order_id)->lockForUpdate()->firstOrFail();
                $this->payment->requirePaid($order);
                abort_if($status === 'completed' && $order->payment_method !== 'cash' && $order->payment_status !== 'paid', 409, 'A loja precisa confirmar o recebimento do Pix ou cartão antes de concluir a entrega.');
                $evidence = [];
                if ($status === 'collected' && $ride->confirmation_required) {
                    $evidence['confirmation_cipher'] = $this->confirmation->generate();
                }
                if ($status === 'completed') {
                    $this->confirmation->verify($ride, $data['delivery_code'] ?? null, (int) $expectedRound);
                    $path = $this->records->photo($photo, $ride->tenant_id);
                    $receivedBy = isset($data['received_by']) && $data['received_by'] !== '' ? $data['received_by'] : null;
                    $evidence = ['confirmation_verified_at' => $ride->confirmation_required ? now() : null, 'confirmation_cipher' => null, 'received_by' => $receivedBy, 'proof_photo_path' => $path, 'proof_recorded_at' => $path || $receivedBy !== null ? now() : null];
                }
                DB::table('rides')->where('id', $rideId)->update($evidence + ['status' => $status, 'completed_at' => $status === 'completed' ? now() : null, 'updated_at' => now()]);
                if ($status === 'completed') {
                    DB::table('orders')->where('id', $ride->order_id)->update(['status' => 'completed', 'payment_status' => 'paid', 'updated_at' => now()]);
                    DB::table('driver_claims')->where('driver_id', $driver->id)->where('ride_id', $rideId)->delete();
                    $other = DB::table('rides')->where('tenant_id', $ride->tenant_id)->whereIn('status', ['assigned', 'collected', 'returning'])->where('id', '!=', $rideId)->exists();
                    if (! $other) {
                        DB::table('tenants')->where('id', $ride->tenant_id)->where('courier_module', 'closing')->update(['courier_module' => 'disabled']);
                    }
                }
                Audit::record('ride.progress', ['id' => $rideId, 'status' => $status], $ride->tenant_id);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return DB::table('rides')->where('id', $rideId)->firstOrFail();
    }
}
