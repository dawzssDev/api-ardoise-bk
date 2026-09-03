<?php

namespace App\Console\Commands;

use App\Services\SubscriptionAccessService;
use Illuminate\Console\Command;

class SyncSubscriptionAccessCommand extends Command
{
    protected $signature = 'subscriptions:sync-access';

    protected $description = 'Actualiza block_POS según la fecha de corte de cada suscripción';

    public function handle(SubscriptionAccessService $access): int
    {
        $updated = $access->syncAll();

        $this->info("Usuarios con block_POS actualizado: {$updated}");

        return self::SUCCESS;
    }
}
