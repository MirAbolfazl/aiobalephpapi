<?php

namespace App\Console\Commands;

use Aiobale\Client\Client;
use App\Models\BaleAccount;
use App\Services\BaleClientFactory;
use App\Services\WebhookEventDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use function Amp\delay;

#[Signature('bale:watch-events {account}')]
#[Description('Connect to one Bale account and queue subscribed webhook events.')]
class WatchBaleEvents extends Command
{
    public function handle(BaleClientFactory $clients, WebhookEventDispatcher $webhooks): int
    {
        $account = BaleAccount::whereKey($this->argument('account'))
            ->where('is_active', true)
            ->firstOrFail();
        $client = $clients->forAccount($account);

        foreach (config('bale.events') as $eventName) {
            $client->on($eventName, static function (mixed $event) use ($account, $eventName, $webhooks): void {
                $webhooks->dispatch($account, $eventName, $event);
            });
        }

        $client->connect()->await();
        $this->info("Watching Bale events for account {$account->id}. Press Ctrl+C to stop.");

        while (true) {
            if (!$client->isRunning()) {
                $this->warn('Bale connection closed. Reconnecting in 5 seconds.');
                delay(5);
                $client->connect()->await();
            }
            delay(1);
        }

        return self::SUCCESS;
    }
}
