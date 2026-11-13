<?php

namespace App\Http\Controllers\Api\V1;

use Aiobale\Exception\AiobaleException;
use Aiobale\Exception\BaleApiException;
use Aiobale\Enums\PeerType;
use Aiobale\Types\Peer;
use App\Http\Controllers\Controller;
use App\Services\BaleFileUploadService;
use App\Services\BaleResponseSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BaleFileController extends Controller
{
    public function store(
        Request $request,
        int $account,
        BaleFileUploadService $uploads,
        BaleResponseSerializer $serializer,
    ): JsonResponse {
        $baleAccount = $request->user()->baleAccounts()->findOrFail($account);
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:' . (int) config('bale.max_upload_kilobytes', 51200),
            ],
            'peer_id' => ['nullable', 'integer', 'min:1', 'required_with:peer_type'],
            'peer_type' => [
                'nullable',
                'integer',
                Rule::in([PeerType::PRIVATE->value, PeerType::GROUP->value]),
                'required_with:peer_id',
            ],
        ]);
        $file = $data['file'];
        $peer = isset($data['peer_id'], $data['peer_type'])
            ? new Peer([
                'type' => PeerType::from((int) $data['peer_type']),
                'id' => (int) $data['peer_id'],
                'access_hash' => 1,
            ])
            : null;

        try {
            $details = $uploads->upload(
                $baleAccount,
                $file->getPathname(),
                $file->getMimeType() ?: 'application/octet-stream',
                $peer,
            );
        } catch (BaleApiException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'bale_api_error',
                    'message' => $exception->getMessage(),
                ],
            ], 502);
        } catch (AiobaleException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'bale_client_error',
                    'message' => $exception->getMessage(),
                ],
            ], 502);
        }

        $baleAccount->update(['last_used_at' => now()]);

        return response()->json(['data' => $serializer->normalize($details)], 201);
    }
}
