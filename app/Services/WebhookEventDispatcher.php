<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\BaleAccount;
use App\Models\WebhookDelivery;
use Illuminate\Support\Str;

class WebhookEventDispatcher
{
    public function __construct(private readonly BaleResponseSerializer $serializer)
    {
    }

    public function dispatch(BaleAccount $account, string $eventName, mixed $event): void
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'type' => $eventName,
            'account_id' => $account->id,
            'occurred_at' => now()->toAtomString(),
            'data' => $this->serializer->normalize($event),
        ];

        foreach ($account->webhookEndpoints()->where('is_active', true)->get() as $endpoint) {
            if (!in_array($eventName, $endpoint->events, true)) {
                continue;
            }

            $delivery = WebhookDelivery::create([
                'webhook_endpoint_id' => $endpoint->id,
                'event_id' => $payload['id'],
                'event_name' => $eventName,
                'payload' => $payload,
                'status' => 'pending',
                'next_attempt_at' => now(),
            ]);
            DeliverWebhook::dispatch($delivery->id);
        }
    }
}
