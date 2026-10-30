<?php

namespace App\Console\Commands;

use App\Services\OnlinePayment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('payments:sync')]
#[Description('Reconcilia pagamentos online sem recriar cobranças')]
class SyncPayments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OnlinePayment $payments): int
    {
        $ids = DB::table('payment_attempts')->whereIn('state', ['creating', 'waiting', 'uncertain', 'paid'])->where(function ($query) {
            $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
        })->orderBy('next_check_at')->orderBy('id')->limit(8)->pluck('id');
        foreach ($ids as $id) {
            $payments->sync($id);
        }
        $this->info('Pagamentos consultados: '.$ids->count());

        return self::SUCCESS;
    }
}
