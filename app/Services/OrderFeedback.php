<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class OrderFeedback
{
    public function eligible(object $order): bool
    {
        return in_array($order->source, ['menu', 'pos'], true) && $order->status === 'completed'
            && $order->payment_status === 'paid' && app(TenantState::class)->active($order->tenant_id);
    }

    public function submit(string $publicId, array $data): void
    {
        DB::transaction(function () use ($publicId, $data): void {
            $order = DB::table('orders')->where('public_id', $publicId)->firstOrFail();
            DB::table('tenants')->where('id', $order->tenant_id)->lockForUpdate()->firstOrFail();
            DB::table('stores')->where('tenant_id', $order->tenant_id)->where('id', $order->store_id)->lockForUpdate()->firstOrFail();
            $order = DB::table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->eligible($order), 409, 'A avaliação fica disponível após a conclusão e confirmação do pagamento.');
            $comment = trim($data['comment'] ?? '');
            $comment = $comment === '' ? null : $comment;
            $existing = DB::table('order_feedback')->where('order_id', $order->id)->first();
            if ($existing) {
                abort_unless($existing->rating === (int) $data['rating'] && $existing->comment === $comment, 409, 'Este pedido já foi avaliado. A avaliação enviada foi preservada.');

                return;
            }
            $id = DB::table('order_feedback')->insertGetId([
                'tenant_id' => $order->tenant_id, 'store_id' => $order->store_id, 'order_id' => $order->id,
                'rating' => (int) $data['rating'], 'comment' => $comment, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('feedback.received', ['feedback_id' => $id, 'order_id' => $order->id, 'rating' => (int) $data['rating']], $order->tenant_id);
        }, 3);
    }

    public function treat(object $user, int $id, string $note): void
    {
        $feedback = DB::table('order_feedback')->where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();
        DB::table('stores')->where('tenant_id', $user->tenant_id)->where('id', $feedback->store_id)->lockForUpdate()->firstOrFail();
        $feedback = DB::table('order_feedback')->where('id', $id)->lockForUpdate()->firstOrFail();
        $note = trim($note);
        abort_if($note === '', 422, 'Descreva o tratamento realizado.');
        if ($feedback->treated_at) {
            abort_unless($feedback->treated_by === $user->id && $feedback->treatment_note === $note, 409, 'Esta avaliação já foi tratada. Atualize a página para conferir.');

            return;
        }
        DB::table('order_feedback')->where('id', $id)->update(['treated_at' => now(), 'treated_by' => $user->id, 'treatment_note' => $note, 'updated_at' => now()]);
        Audit::record('feedback.treated', ['feedback_id' => $id, 'order_id' => $feedback->order_id], $user->tenant_id);
    }
}
