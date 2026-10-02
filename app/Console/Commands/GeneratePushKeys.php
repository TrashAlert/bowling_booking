<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GeneratePushKeys extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Make the key pair for push notifications, to be copied into .env';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('Add these lines to your .env file. Keep the private key secret.');
        $this->line('Making new keys later stops notifications for phones that turned them on with the old ones.');
        $this->newLine();
        $this->line("VAPID_PUBLIC_KEY={$keys['publicKey']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['privateKey']}");

        return self::SUCCESS;
    }
}
