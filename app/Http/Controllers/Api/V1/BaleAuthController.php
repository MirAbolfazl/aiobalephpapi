<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BaleAccount;
use App\Models\BaleAuthChallenge;
use App\Services\BaleResponseSerializer;
use Aiobale\Client\Client;
use Aiobale\Exception\BaleApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BaleAuthController extends Controller
{
    /** @response array{data: array{challenge_id: int, sent_code_type: string|null, code_timeout_seconds: int|null, expires_at: string}} */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[1-9]\d{6,14}$/'],
            'device_title' => ['sometimes', 'string', 'max:120'],
        ]);

        $client = new Client();
        $response = $client->startPhoneAuth(
            $data['phone'],
            deviceTitle: $data['device_title'] ?? 'aiobalephpapi',
        )->await();

        $challenge = $request->user()->baleAuthChallenges()->create([
            'phone' => $data['phone'],
            'transaction_hash' => $response->transaction_hash,
            'device_title' => $data['device_title'] ?? 'aiobalephpapi',
            'expires_at' => now()->addMinutes((int) config('bale.auth_challenge_ttl_minutes', 10)),
        ]);

        return response()->json([
            'data' => [
                'challenge_id' => $challenge->id,
                'sent_code_type' => $response->sent_code_type?->name,
                'code_timeout_seconds' => $response->code_timeout?->value,
                'expires_at' => $challenge->expires_at->toAtomString(),
            ],
        ], 201);
    }

    public function verifyCode(Request $request, int $challenge): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'label' => ['sometimes', 'string', 'max:100'],
        ]);
        $authChallenge = $this->challenge($request, $challenge);
        $this->recordAttempt($authChallenge);

        $client = new Client();
        try {
            $client->validateCode($data['code'], $authChallenge->transaction_hash)->await();
        } catch (BaleApiException $exception) {
            if (stripos($exception->getMessage(), 'password needed') !== false) {
                return response()->json([
                    'error' => [
                        'code' => 'two_step_password_required',
                        'message' => 'This Bale account requires two-step verification.',
                    ],
                    'data' => ['challenge_id' => $authChallenge->id],
                ], 409);
            }
            throw $exception;
        }

        return $this->completeLogin(
            $request,
            $authChallenge,
            $client,
            $data['label'] ?? $authChallenge->phone,
        );
    }

    public function verifyPassword(Request $request, int $challenge): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:256'],
            'label' => ['sometimes', 'string', 'max:100'],
        ]);
        $authChallenge = $this->challenge($request, $challenge);
        $this->recordAttempt($authChallenge);

        $client = new Client();
        $client->validatePassword($data['password'], $authChallenge->transaction_hash)->await();

        return $this->completeLogin(
            $request,
            $authChallenge,
            $client,
            $data['label'] ?? $authChallenge->phone,
        );
    }

    public function signUp(Request $request, int $challenge): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $authChallenge = $this->challenge($request, $challenge);
        $this->recordAttempt($authChallenge);

        $result = (new Client())->signUp($authChallenge->transaction_hash, $data['name'])->await();
        $serialized = app(BaleResponseSerializer::class)->normalize($result);
        if (is_string($serialized)) {
            $serialized = ['base64' => base64_encode($serialized), 'encoding' => 'base64'];
        }

        return response()->json(['data' => ['result' => $serialized]]);
    }

    private function challenge(Request $request, int $id): BaleAuthChallenge
    {
        $challenge = $request->user()->baleAuthChallenges()->findOrFail($id);
        if ($challenge->expires_at->isPast() || $challenge->attempts >= 5) {
            $challenge->delete();
            abort(410, 'The Bale authentication challenge has expired.');
        }

        return $challenge;
    }

    private function recordAttempt(BaleAuthChallenge $challenge): void
    {
        $challenge->increment('attempts');
    }

    private function completeLogin(
        Request $request,
        BaleAuthChallenge $challenge,
        Client $client,
        string $label,
    ): JsonResponse {
        $token = $client->token();
        $baleUserId = $client->userId();
        if ($token === null || $baleUserId === null) {
            abort(502, 'The Bale authentication response did not contain a usable session.');
        }

        $account = DB::transaction(function () use ($request, $challenge, $label, $token, $baleUserId): BaleAccount {
            $account = $request->user()->baleAccounts()->updateOrCreate(
                ['bale_user_id' => $baleUserId],
                [
                    'label' => $label,
                    'phone' => $challenge->phone,
                    'access_token' => $token,
                    'is_active' => true,
                ],
            );
            $challenge->delete();

            return $account;
        });

        return response()->json([
            'data' => [
                'account' => [
                    'id' => $account->id,
                    'label' => $account->label,
                    'phone' => $account->phone,
                    'bale_user_id' => $account->bale_user_id,
                    'is_active' => $account->is_active,
                ],
            ],
        ], 201);
    }
}
