<?php

namespace Tests\Feature;

use Aiobale\Enums\PeerType;
use Aiobale\Methods\MethodRegistry;
use Aiobale\Methods\Messaging\SendMessage;
use Aiobale\Types\Peer;
use App\Jobs\DeliverWebhook;
use App\Models\BaleAccount;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\BaleFileUploadService;
use App\Services\BaleMethodPayloadValidator;
use App\Services\WebhookUrlPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HttpApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_registration_issues_a_bearer_token_and_protects_the_user_route(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'API User',
            'email' => 'api@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'api@example.com')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]]);

        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'api@example.com');

        $this->assertDatabaseHas('users', ['email' => 'api@example.com', 'is_admin' => false]);
    }

    public function test_bale_accounts_are_isolated_by_api_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = $owner->baleAccounts()->create([
            'label' => 'Primary',
            'phone' => '+989123456789',
            'bale_user_id' => 42,
            'access_token' => 'encrypted-bale-token',
            'is_active' => true,
        ]);
        $token = $otherUser->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/bale/accounts/{$account->id}")
            ->assertNotFound();
    }

    public function test_method_catalog_lists_registered_client_operations(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/bale/methods')->assertOk();

        $this->assertCount(count(MethodRegistry::all()), $response->json('data'));
        $response
            ->assertJsonFragment(['name' => 'SendMessage'])
            ->assertJsonFragment([
                'name' => 'GetMyWallets',
                'service' => 'bale.wallet.v1.Wallet',
                'method' => 'GetMyWallets',
            ])
            ->assertJsonFragment([
                'name' => 'bale.groups.v1.Groups.PinMessage',
                'method' => 'PinMessage',
            ]);
    }

    public function test_service_qualified_bale_method_names_match_the_generic_route(): void
    {
        $route = app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create(
                '/api/v1/bale/accounts/1/methods/bale.groups.v1.Groups.PinMessage',
                'POST',
            ),
        );

        $this->assertSame(
            'App\\Http\\Controllers\\Api\\V1\\BaleOperationController@invoke',
            $route->getActionName(),
        );
        $this->assertSame('bale.groups.v1.Groups.PinMessage', $route->parameter('method'));
    }

    public function test_authentication_operations_cannot_be_invoked_through_the_generic_method_route(): void
    {
        $user = User::factory()->create();
        $account = $user->baleAccounts()->create([
            'label' => 'Primary',
            'bale_user_id' => 42,
            'access_token' => 'encrypted-bale-token',
            'is_active' => true,
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson(
            "/api/v1/bale/accounts/{$account->id}/methods/StartPhoneAuth",
            ['payload' => ['phone_number' => 989123456789]],
        )->assertNotFound();
    }

    public function test_method_payload_validation_checks_required_fields_and_builds_nested_models(): void
    {
        $validator = app(BaleMethodPayloadValidator::class);
        $payload = $validator->validate(SendMessage::class, [
            'peer' => ['type' => 1, 'id' => 42],
            'message_id' => 7,
            'content' => ['text' => ['value' => 'hello']],
            'chat' => ['type' => 1, 'id' => 42],
        ]);
        $method = new SendMessage($payload);

        $this->assertSame(7, $method->message_id);
        $this->assertSame(42, $method->peer->id);
        $this->assertSame('hello', $method->content->text->value);

        try {
            $validator->validate(SendMessage::class, ['message_id' => '7']);
            $this->fail('Invalid message payload should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payload', $exception->errors());
        }
    }

    public function test_generated_rpc_map_fields_are_validated(): void
    {
        $methodClass = MethodRegistry::resolve('SubmitMarketFeedback');
        $this->assertNotNull($methodClass);

        $payload = app(BaleMethodPayloadValidator::class)->validate($methodClass, [
            'extra_fields' => ['topic' => 'feedback'],
        ]);

        $this->assertSame(['topic' => 'feedback'], $payload['extra_fields']);
    }

    public function test_media_upload_is_stored_on_the_private_disk(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $token = $owner->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->createWithContent('private.txt', 'private content'),
        ])->assertCreated();

        $assetId = $response->json('data.id');
        $asset = MediaAsset::findOrFail($assetId);
        $this->assertSame($owner->id, $asset->user_id);
        Storage::disk('local')->assertExists($asset->path);
    }

    public function test_bale_file_upload_returns_a_reusable_reference_without_persisting_the_file(): void
    {
        $owner = User::factory()->create();
        $account = $owner->baleAccounts()->create([
            'label' => 'Primary',
            'bale_user_id' => 42,
            'access_token' => 'encrypted-bale-token',
            'is_active' => true,
        ]);
        $token = $owner->createToken('test')->plainTextToken;
        $file = UploadedFile::fake()->createWithContent('bundle.zip', 'file contents');
        $details = new \Aiobale\Types\FileDetails([
            'file_id' => 123,
            'access_hash' => 42,
            'size' => 13,
            'name' => 'bundle.zip',
            'mime_type' => 'text/plain',
        ]);
        $uploads = \Mockery::mock(BaleFileUploadService::class);
        $uploads->shouldReceive('upload')
            ->once()
            ->withArgs(fn (BaleAccount $uploadedAccount, string $path, string $mimeType, ?Peer $peer): bool =>
                $uploadedAccount->is($account)
                && file_get_contents($path) === 'file contents'
                && $mimeType !== ''
                && $peer?->type === PeerType::GROUP
                && $peer?->id === 42
                && $peer?->access_hash === 1
            )
            ->andReturn($details);
        $this->app->instance(BaleFileUploadService::class, $uploads);

        $this->withToken($token)
            ->post('/api/v1/bale/accounts/' . $account->id . '/files', [
                'file' => $file,
                'peer_id' => 42,
                'peer_type' => PeerType::GROUP->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.file_id', 123)
            ->assertJsonPath('data.access_hash', 42)
            ->assertJsonPath('data.name', 'bundle.zip')
            ->assertJsonPath('data.mime_type', 'text/plain');

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_webhook_index_returns_data_and_pagination_metadata(): void
    {
        $user = User::factory()->create();
        $account = $user->baleAccounts()->create([
            'label' => 'Primary',
            'bale_user_id' => 42,
            'access_token' => 'encrypted-bale-token',
            'is_active' => true,
        ]);
        $webhook = WebhookEndpoint::create([
            'user_id' => $user->id,
            'bale_account_id' => $account->id,
            'url' => 'https://8.8.8.8/webhook',
            'secret' => 'webhook-secret',
            'events' => ['message'],
            'is_active' => true,
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/webhooks')
            ->assertOk()
            ->assertJsonPath('data.0.id', $webhook->id)
            ->assertJsonPath('data.0.bale_account_label', 'Primary')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_media_cannot_be_downloaded_by_another_user(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $path = 'media/' . $owner->id . '/private.txt';
        Storage::disk('local')->put($path, 'private content');
        $asset = MediaAsset::create([
            'user_id' => $owner->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'private.txt',
            'mime_type' => 'text/plain',
            'size' => strlen('private content'),
            'sha256' => hash('sha256', 'private content'),
        ]);
        $otherUser = User::factory()->create();
        $otherToken = $otherUser->createToken('test')->plainTextToken;

        $this->withToken($otherToken)
            ->get("/api/v1/media/{$asset->id}/download")
            ->assertNotFound();
    }

    public function test_webhook_delivery_is_signed_and_records_success(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $user = User::factory()->create();
        $account = $user->baleAccounts()->create([
            'label' => 'Primary',
            'bale_user_id' => 42,
            'access_token' => 'encrypted-bale-token',
            'is_active' => true,
        ]);
        $endpoint = WebhookEndpoint::create([
            'user_id' => $user->id,
            'bale_account_id' => $account->id,
            'url' => 'https://8.8.8.8/webhook',
            'secret' => 'webhook-secret',
            'events' => ['message'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event_id' => '00000000-0000-4000-8000-000000000001',
            'event_name' => 'message',
            'payload' => ['id' => '00000000-0000-4000-8000-000000000001', 'type' => 'message'],
            'status' => 'pending',
        ]);

        (new DeliverWebhook($delivery->id))->handle(app(WebhookUrlPolicy::class));

        $this->assertDatabaseHas('webhook_deliveries', [
            'id' => $delivery->id,
            'status' => 'delivered',
            'attempts' => 1,
            'response_code' => 204,
        ]);
        Http::assertSent(static function ($request): bool {
            $timestamp = $request->header('X-Bale-Timestamp')[0] ?? '';
            $expected = 'sha256=' . hash_hmac(
                'sha256',
                $timestamp . '.' . $request->body(),
                'webhook-secret',
            );

            return $request->hasHeader('X-Bale-Signature')
                && hash_equals($expected, $request->header('X-Bale-Signature')[0] ?? '');
        });
    }

    public function test_webhook_url_policy_rejects_local_addresses(): void
    {
        $this->expectException(ValidationException::class);

        app(WebhookUrlPolicy::class)->validate('https://127.0.0.1/hook');
    }

    public function test_webhook_url_policy_rejects_noncanonical_numeric_hosts(): void
    {
        $this->expectException(ValidationException::class);

        app(WebhookUrlPolicy::class)->validate('https://2130706433/hook');
    }

    public function test_webhook_connection_failures_are_recorded_before_retrying(): void
    {
        Http::fake(static fn () => throw new ConnectionException('Connection failed.'));
        $user = User::factory()->create();
        $account = $user->baleAccounts()->create([
            'label' => 'Primary',
            'bale_user_id' => 42,
            'access_token' => 'encrypted-bale-token',
            'is_active' => true,
        ]);
        $endpoint = WebhookEndpoint::create([
            'user_id' => $user->id,
            'bale_account_id' => $account->id,
            'url' => 'https://8.8.8.8/webhook',
            'secret' => 'webhook-secret',
            'events' => ['message'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event_id' => '00000000-0000-4000-8000-000000000002',
            'event_name' => 'message',
            'payload' => ['type' => 'message'],
            'status' => 'pending',
        ]);

        try {
            (new DeliverWebhook($delivery->id))->handle(app(WebhookUrlPolicy::class));
            $this->fail('A connection failure should be retried.');
        } catch (ConnectionException) {
            $this->assertDatabaseHas('webhook_deliveries', [
                'id' => $delivery->id,
                'status' => 'pending',
                'attempts' => 1,
                'last_error' => 'Webhook connection failed.',
            ]);
            $this->assertNotNull($delivery->fresh()->next_attempt_at);
        }
    }
}
