<?php

namespace App\Http\Controllers\Api\V1;

use Aiobale\Exception\AiobaleException;
use Aiobale\Exception\BaleApiException;
use Aiobale\Enums\PeerType;
use Aiobale\Types\Peer;
use App\Http\Controllers\Controller;
use App\Services\BaleFileUploadService;
use App\Services\BaleResponseSerializer;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Bale files', description: 'Upload files directly to Bale and receive reusable file references.', weight: 40)]
class BaleFileController extends Controller
{
    #[Endpoint(
        operationId: 'uploadFileToBale',
        title: 'Upload a file to Bale',
        description: 'Uploads a multipart file to the selected Bale account. It does not send the file to a chat. Set both peer_id and peer_type when the upload should be associated with a destination.',
    )]
    #[PathParameter('account', description: 'ID of the connected Bale account.', type: 'int')]
    #[Response(
        status: 201,
        type: 'array{data: array{file_id: int, access_hash: int, size: int, name: string, mime_type: string}}',
        description: 'A Bale file reference that can be used in a later operation.',
    )]
    #[Response(
        status: 502,
        type: 'array{error: array{code: string, message: string}}',
        description: 'The Bale API or client could not complete the upload.',
    )]
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
