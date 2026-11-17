<?php

namespace App\Console\Commands;

use App\Services\FinancialRecurrences;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('finance:recurrences')]
#[Description('Gera contas mensais programadas sem registrar pagamentos ou recebimentos')]
class GenerateFinancialRecurrences extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FinancialRecurrences $recurrences): int
    {
        $created = 0;
        $failed = 0;
        foreach (DB::table('financial_recurrences')->where('enabled', true)->lazyById(100) as $plan) {
            try {
                $created += $recurrences->generate($plan->id);
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
                $this->warn('Falha na programação #'.$plan->id.'. Consulte os logs.');
            }
        }
        $this->info('Contas programadas: '.$created.'; falhas: '.$failed.'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
