<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookUrlPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, Queueable;

    private const BACKOFF_SECONDS = [30, 120, 600, 1800];

    public int $tries;

    public function __construct(public readonly int $deliveryId)
    {
        $this->tries = max(1, (int) config('bale.webhook_max_attempts', 5));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return self::BACKOFF_SECONDS;
    }

    public function handle(WebhookUrlPolicy $urlPolicy): void
    {
        $delivery = WebhookDelivery::with('endpoint')->find($this->deliveryId);
        if ($delivery === null || in_array($delivery->status, ['delivered', 'failed', 'cancelled'], true)) {
            return;
        }

        $endpoint = $delivery->endpoint;
        if (!$endpoint->is_active || !$endpoint->user || !$endpoint->baleAccount?->is_active) {
            $delivery->update(['status' => 'cancelled', 'next_attempt_at' => null]);
            return;
        }

        $destination = $urlPolicy->resolvePublicAddresses($endpoint->url);
        $body = json_encode(
            $delivery->payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $endpoint->secret);
        $attempt = max(1, $this->attempts());
        $options = ['verify' => true, 'proxy' => ''];
        if (!$destination['is_ip']) {
            if (!extension_loaded('curl')) {
                throw new RuntimeException('Webhook delivery requires cURL to pin DNS resolution safely.');
            }

            $ip = $destination['addresses'][0];
            $resolvedAddress = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $options['curl'] = [
                constant('CURLOPT_RESOLVE') => [
                    $destination['host'] . ':' . $destination['port'] . ':' . $resolvedAddress,
                ],
            ];
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout((int) config('bale.webhook_timeout_seconds', 8))
                ->withoutRedirecting()
                ->withOptions($options)
                ->withHeaders([
                    'X-Bale-Event' => $delivery->event_name,
                    'X-Bale-Delivery' => $delivery->event_id,
                    'X-Bale-Timestamp' => $timestamp,
                    'X-Bale-Signature' => 'sha256=' . $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $exception) {
            $this->recordFailure($delivery, $attempt, null, 'Webhook connection failed.');
            if ($attempt < $this->tries) {
                throw $exception;
            }

            return;
        }

        $success = $response->successful();
        if ($success) {
            $delivery->update([
                'attempts' => $attempt,
                'status' => 'delivered',
                'next_attempt_at' => null,
                'response_code' => $response->status(),
                'last_error' => null,
                'delivered_at' => now(),
            ]);
            $endpoint->update(['last_delivered_at' => now()]);
            return;
        }

        $this->recordFailure(
            $delivery,
            $attempt,
            $response->status(),
            'Webhook returned HTTP ' . $response->status(),
        );

        if ($attempt < $this->tries) {
            throw new RuntimeException('Webhook delivery failed with HTTP ' . $response->status() . '.');
        }
    }

    public function failed(Throwable $exception): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);
        if ($delivery === null || $delivery->status === 'delivered') {
            return;
        }

        $delivery->update([
            'status' => 'failed',
            'next_attempt_at' => null,
            'last_error' => 'Webhook delivery failed (' . $exception::class . ').',
        ]);
        Log::warning('Webhook delivery failed.', [
            'delivery_id' => $delivery->id,
            'exception' => $exception::class,
        ]);
    }

    private function recordFailure(
        WebhookDelivery $delivery,
        int $attempt,
        ?int $responseCode,
        string $message,
    ): void {
        $finalAttempt = $attempt >= $this->tries;
        $delivery->update([
            'attempts' => $attempt,
            'status' => $finalAttempt ? 'failed' : 'pending',
            'next_attempt_at' => $finalAttempt
                ? null
                : now()->addSeconds(
                    self::BACKOFF_SECONDS[$attempt - 1]
                    ?? self::BACKOFF_SECONDS[array_key_last(self::BACKOFF_SECONDS)],
                ),
            'response_code' => $responseCode,
            'last_error' => $message,
            'delivered_at' => null,
        ]);
    }
}
