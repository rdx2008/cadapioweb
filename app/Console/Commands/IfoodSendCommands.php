<?php

namespace App\Console\Commands;

use App\Services\IfoodOutbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ifood:send-commands')]
#[Description('Send queued iFood test actions without assuming confirmation')]
class IfoodSendCommands extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IfoodOutbox $outbox): int
    {
        $this->info($outbox->run().' solicitação(ões) de teste verificadas.');

        return self::SUCCESS;
    }
}
