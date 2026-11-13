<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaAssetController extends Controller
{
    /** @response array{data: list<array{id: int, bale_account_id: int|null, original_name: string, mime_type: string, size: int, sha256: string, download_url: string, created_at: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}} */
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

    public function download(Request $request, int $media)
    {
        $asset = $request->user()->mediaAssets()->findOrFail($media);
        abort_unless(Storage::disk($asset->disk)->exists($asset->path), 404);

        return Storage::disk($asset->disk)->download($asset->path, $asset->original_name, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $asset->mime_type,
        ]);
    }

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
