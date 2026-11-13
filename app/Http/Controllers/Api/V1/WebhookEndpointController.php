<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\WebhookUrlPolicy;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('Webhooks', description: 'Configure signed Bale event delivery and inspect delivery attempts.', weight: 70)]
class WebhookEndpointController extends Controller
{
    #[Endpoint(
        operationId: 'listWebhooks',
        title: 'List webhooks',
        description: 'Returns the authenticated user’s webhook endpoints, newest first.',
    )]
    #[Response(type: 'array{data: list<array{id: int, bale_account_id: int, bale_account_label: string|null, url: string, events: list<string>, is_active: bool, last_delivered_at: string|null, created_at: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}')]
    public function index(Request $request): JsonResponse
    {
        $webhooks = $request->user()->webhookEndpoints()->with('baleAccount:id,label')
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'data' => $webhooks->getCollection()->map(fn (WebhookEndpoint $webhook): array => $this->webhookData($webhook)),
            'meta' => [
                'current_page' => $webhooks->currentPage(),
                'last_page' => $webhooks->lastPage(),
                'per_page' => $webhooks->perPage(),
                'total' => $webhooks->total(),
            ],
        ]);
    }

    #[Endpoint(
        operationId: 'createWebhook',
        title: 'Create a webhook',
        description: 'Registers an HTTPS endpoint for selected Bale events. The generated signing secret is returned only once; store it securely.',
    )]
    #[Response(
        status: 201,
        type: 'array{data: array{id: int, bale_account_id: int, bale_account_label: string|null, url: string, events: list<string>, is_active: bool, last_delivered_at: string|null, created_at: string|null}, secret: string, secret_notice: string}',
        description: 'The webhook details and one-time signing secret.',
    )]
    public function store(Request $request, WebhookUrlPolicy $urlPolicy): JsonResponse
    {
        $data = $request->validate([
            'bale_account_id' => ['required', 'integer'],
            'url' => ['required', 'url:https', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'distinct', 'in:' . implode(',', config('bale.events'))],
        ]);

        $account = $request->user()->baleAccounts()->findOrFail($data['bale_account_id']);
        if (!$account->is_active) {
            abort(422, 'Webhooks require an active Bale account.');
        }
        $urlPolicy->validate($data['url']);

        $secret = Str::random(64);
        $webhook = WebhookEndpoint::create([
            'user_id' => $request->user()->id,
            'bale_account_id' => $account->id,
            'url' => $data['url'],
            'secret' => $secret,
            'events' => $data['events'],
            'is_active' => true,
        ]);

        return response()->json([
            'data' => $this->webhookData($webhook),
            'secret' => $secret,
            'secret_notice' => 'Store this secret now; it will not be shown again.',
        ], 201);
    }

    #[Endpoint(
        operationId: 'updateWebhook',
        title: 'Update a webhook',
        description: 'Updates the URL, event subscriptions, active state, or Bale account. Set rotate_secret to true to return a new one-time signing secret.',
    )]
    #[PathParameter('webhook', description: 'ID of the webhook endpoint.', type: 'int')]
    #[Response(type: 'array{data: array{id: int, bale_account_id: int, bale_account_label: string|null, url: string, events: list<string>, is_active: bool, last_delivered_at: string|null, created_at: string|null}, secret?: string, secret_notice?: string}')]
    public function update(Request $request, int $webhook, WebhookUrlPolicy $urlPolicy): JsonResponse
    {
        $endpoint = $request->user()->webhookEndpoints()->findOrFail($webhook);
        $data = $request->validate([
            'bale_account_id' => ['sometimes', 'integer'],
            'url' => ['sometimes', 'url:https', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'distinct', 'in:' . implode(',', config('bale.events'))],
            'is_active' => ['sometimes', 'boolean'],
            'rotate_secret' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['bale_account_id'])) {
            $account = $request->user()->baleAccounts()->findOrFail($data['bale_account_id']);
            if (!$account->is_active) {
                abort(422, 'Webhooks require an active Bale account.');
            }
        }
        if (isset($data['url'])) {
            $urlPolicy->validate($data['url']);
        }

        $rotateSecret = $data['rotate_secret'] ?? false;
        unset($data['rotate_secret']);
        if ($rotateSecret) {
            $data['secret'] = Str::random(64);
        }
        $endpoint->update($data);

        $response = ['data' => $this->webhookData($endpoint->refresh())];
        if ($rotateSecret) {
            $response['secret'] = $endpoint->secret;
            $response['secret_notice'] = 'Store this secret now; it will not be shown again.';
        }

        return response()->json($response);
    }

    #[Endpoint(
        operationId: 'deleteWebhook',
        title: 'Delete a webhook',
        description: 'Deletes the webhook endpoint owned by the authenticated API user.',
    )]
    #[PathParameter('webhook', description: 'ID of the webhook endpoint.', type: 'int')]
    #[Response(status: 204, description: 'The webhook endpoint was deleted.')]
    public function destroy(Request $request, int $webhook): JsonResponse
    {
        $request->user()->webhookEndpoints()->findOrFail($webhook)->delete();

        return response()->json(status: 204);
    }

    #[Endpoint(
        operationId: 'listWebhookDeliveries',
        title: 'List webhook deliveries',
        description: 'Returns delivery attempts for a webhook owned by the authenticated API user, newest first.',
    )]
    #[PathParameter('webhook', description: 'ID of the webhook endpoint.', type: 'int')]
    #[Response(type: 'array{data: list<array{id: int, event_id: string, event_name: string, status: string, attempts: int, next_attempt_at: string|null, response_code: int|null, last_error: string|null, delivered_at: string|null, created_at: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}')]
    public function deliveries(Request $request, int $webhook): JsonResponse
    {
        $endpoint = $request->user()->webhookEndpoints()->findOrFail($webhook);
        $deliveries = $endpoint->deliveries()->latest('id')->paginate(50);

        return response()->json([
            'data' => $deliveries->getCollection()->map(static fn ($delivery): array => [
                'id' => $delivery->id,
                'event_id' => $delivery->event_id,
                'event_name' => $delivery->event_name,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'next_attempt_at' => $delivery->next_attempt_at?->toAtomString(),
                'response_code' => $delivery->response_code,
                'last_error' => $delivery->last_error,
                'delivered_at' => $delivery->delivered_at?->toAtomString(),
                'created_at' => $delivery->created_at?->toAtomString(),
            ]),
            'meta' => [
                'current_page' => $deliveries->currentPage(),
                'last_page' => $deliveries->lastPage(),
                'per_page' => $deliveries->perPage(),
                'total' => $deliveries->total(),
            ],
        ]);
    }

    /** @return array{id: int, bale_account_id: int, bale_account_label: string|null, url: string, events: list<string>, is_active: bool, last_delivered_at: string|null, created_at: string|null} */
    private function webhookData(WebhookEndpoint $webhook): array
    {
        return [
            'id' => $webhook->id,
            'bale_account_id' => $webhook->bale_account_id,
            'bale_account_label' => $webhook->baleAccount?->label,
            'url' => $webhook->url,
            'events' => $webhook->events,
            'is_active' => $webhook->is_active,
            'last_delivered_at' => $webhook->last_delivered_at?->toAtomString(),
            'created_at' => $webhook->created_at?->toAtomString(),
        ];
    }
}
