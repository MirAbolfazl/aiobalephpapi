<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Group('Media', description: 'Upload, list, download, and delete private files owned by the authenticated API user.', weight: 60)]
class MediaAssetController extends Controller
{
    #[Endpoint(
        operationId: 'listMedia',
        title: 'List uploaded media',
        description: 'Returns the current user’s stored media, newest first.',
    )]
    #[QueryParameter('per_page', description: 'Number of media items per page (1–100; defaults to 20).', type: 'int', default: 20)]
    #[Response(type: 'array{data: list<array{id: int, bale_account_id: int|null, original_name: string, mime_type: string, size: int, sha256: string, download_url: string, created_at: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}')]
    public function index(Request $request): JsonResponse
    {
        $assets = $request->user()->mediaAssets()->latest('id')
            ->paginate(max(1, min((int) $request->query('per_page', 20), 100)));

        return response()->json([
            'data' => $assets->getCollection()->map(fn (MediaAsset $asset): array => $this->assetData($asset)),
            'meta' => [
                'current_page' => $assets->currentPage(),
                'last_page' => $assets->lastPage(),
                'per_page' => $assets->perPage(),
                'total' => $assets->total(),
            ],
        ]);
    }

    #[Endpoint(
        operationId: 'uploadMedia',
        title: 'Upload media to the API',
        description: 'Stores an allowed media file on the private Laravel disk. Optionally associate it with one of your Bale accounts.',
    )]
    #[Response(
        status: 201,
        type: 'array{data: array{id: int, bale_account_id: int|null, original_name: string, mime_type: string, size: int, sha256: string, download_url: string, created_at: string|null}}',
        description: 'The stored media record and its authenticated download URL.',
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:' . (int) config('bale.max_upload_kilobytes', 51200),
                'mimes:jpg,jpeg,png,gif,webp,mp4,webm,mov,mp3,m4a,ogg,wav,pdf,txt,csv,doc,docx,xls,xlsx',
            ],
            'bale_account_id' => ['nullable', 'integer'],
        ]);

        $accountId = $data['bale_account_id'] ?? null;
        if ($accountId !== null) {
            $request->user()->baleAccounts()->findOrFail($accountId);
        }

        $file = $data['file'];
        $path = $file->store('media/' . $request->user()->id, 'local');
        if ($path === false) {
            abort(500, 'The uploaded file could not be stored.');
        }
        $sha256 = hash_file('sha256', $file->getRealPath());
        if ($sha256 === false) {
            Storage::disk('local')->delete($path);
            abort(500, 'The uploaded file could not be verified.');
        }

        $asset = MediaAsset::create([
            'user_id' => $request->user()->id,
            'bale_account_id' => $accountId,
            'disk' => 'local',
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'sha256' => $sha256,
        ]);

        return response()->json(['data' => $this->assetData($asset)], 201);
    }

    #[Endpoint(
        operationId: 'downloadMedia',
        title: 'Download media',
        description: 'Downloads a file belonging to the authenticated API user.',
    )]
    #[PathParameter('media', description: 'ID of the stored media item.', type: 'int')]
    #[Response(status: 200, mediaType: 'application/octet-stream', type: 'string', format: 'binary', description: 'The file contents.')]
    public function download(Request $request, int $media)
    {
        $asset = $request->user()->mediaAssets()->findOrFail($media);
        abort_unless(Storage::disk($asset->disk)->exists($asset->path), 404);

        return Storage::disk($asset->disk)->download($asset->path, $asset->original_name, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $asset->mime_type,
        ]);
    }

    #[Endpoint(
        operationId: 'deleteMedia',
        title: 'Delete media',
        description: 'Deletes the stored file and its metadata for the authenticated API user.',
    )]
    #[PathParameter('media', description: 'ID of the stored media item.', type: 'int')]
    #[Response(status: 204, description: 'The media item was deleted.')]
    public function destroy(Request $request, int $media): JsonResponse
    {
        $asset = $request->user()->mediaAssets()->findOrFail($media);
        if (!Storage::disk($asset->disk)->delete($asset->path)) {
            abort(500, 'The stored media file could not be deleted.');
        }
        $asset->delete();

        return response()->json(status: 204);
    }

    /** @return array{id: int, bale_account_id: int|null, original_name: string, mime_type: string, size: int, sha256: string, download_url: string, created_at: string|null} */
    private function assetData(MediaAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'bale_account_id' => $asset->bale_account_id,
            'original_name' => $asset->original_name,
            'mime_type' => $asset->mime_type,
            'size' => $asset->size,
            'sha256' => $asset->sha256,
            'download_url' => route('api.v1.media.download', ['media' => $asset->id]),
            'created_at' => $asset->created_at?->toAtomString(),
        ];
    }
}
