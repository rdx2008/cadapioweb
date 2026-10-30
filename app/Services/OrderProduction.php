<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderProduction
{
    public function transition(object $order, string $status): array
    {
        return match ($status) {
            'accepted' => ['accepted_at' => now()],
            'preparing' => ['preparation_started_at' => now(), 'preparation_target_minutes' => DB::table('stores')->where('id', $order->store_id)->where('tenant_id', $order->tenant_id)->value('preparation_target_minutes')],
            'ready' => ['ready_at' => now()],
            default => [],
        };
    }

    public function timer(object $order): array
    {
        $now = now()->timestamp;
        $started = $order->preparation_started_at ? Carbon::parse($order->preparation_started_at, 'UTC')->timestamp : 0;
        $finished = $order->ready_at ? Carbon::parse($order->ready_at, 'UTC')->timestamp : 0;
        $accepted = $order->accepted_at ? Carbon::parse($order->accepted_at, 'UTC')->timestamp : 0;
        $prepareAfter = $order->prepare_at ? Carbon::parse($order->prepare_at, 'UTC')->timestamp : 0;
        $target = $order->preparation_target_minutes ?? 0;
        $deadline = $started && $target ? $started + $target * 60 : 0;
        $late = $order->status === 'preparing' && $deadline && $now > $deadline;
        $minutes = (int) floor(max(0, ($finished ?: $now) - $started) / 60);
        $text = match ($order->status) {
            'preparing' => $started ? 'Em preparo há '.$minutes.' min'.($target ? ' · Meta '.$target.' min' : '').($late ? ' · Atrasado '.(int) ceil(($now - $deadline) / 60).' min' : '') : 'Em preparo · tempo não registrado',
            'ready', 'completed' => $started && $finished ? 'Preparo concluído em '.$minutes.' min' : 'Tempo de preparo não registrado',
            'accepted' => ! app(OrderScheduling::class)->canPrepare($order) ? 'Agendado: aguarde o horário de preparo' : ($accepted ? 'Na fila há '.(int) floor(max(0, $now - max($accepted, $prepareAfter)) / 60).' min' : 'Na fila · horário de aceite não registrado'),
            'cancelled' => $started ? 'Produção interrompida' : 'Pedido cancelado',
            default => 'Aguardando aceite',
        };

        return compact('started', 'finished', 'accepted', 'prepareAfter', 'target', 'deadline', 'late', 'text', 'now');
    }
}
