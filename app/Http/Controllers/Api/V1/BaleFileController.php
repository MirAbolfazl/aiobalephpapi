<?php

namespace App\Http\Controllers\Api\V1;

use Aiobale\Exception\AiobaleException;
use Aiobale\Exception\BaleApiException;
use App\Http\Controllers\Controller;
use App\Services\BaleFileUploadService;
use App\Services\BaleResponseSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);
        $file = $data['file'];

        try {
            $details = $uploads->upload(
                $baleAccount,
                $file->getPathname(),
                $file->getMimeType() ?: 'application/octet-stream',
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
