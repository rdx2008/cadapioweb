<?php

namespace App\Console\Commands;

use App\Services\IfoodInbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('ifood:sync')]
#[Description('Receive and process iFood test events without accepting orders')]
class IfoodSync extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IfoodInbox $inbox): int
    {
        $failed = 0;
        foreach (DB::table('ifood_apps')->where('environment', 'test')->pluck('id') as $id) {
            try {
                $result = $inbox->sync((int) $id);
                $failed += $result['failed'];
                $this->line('iFood: '.$result['received'].' eventos novos; '.$result['processed'].' processados; '.$result['failed'].' pendências.');
            } catch (Throwable) {
                $this->error('Falha ao sincronizar aplicativo iFood. Consulte o diagnóstico no painel.');
                $failed++;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
