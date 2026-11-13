<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BaleAccount;
use App\Services\BaleClientFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BaleAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()->baleAccounts()
            ->latest('id')
            ->paginate(max(1, min((int) $request->query('per_page', 20), 100)));

        return response()->json([
            'data' => $accounts->getCollection()->map(fn (BaleAccount $account): array => $this->accountData($account)),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    public function show(Request $request, int $account): JsonResponse
    {
        return response()->json([
            'data' => $this->accountData($this->ownedAccount($request, $account)),
        ]);
    }

    public function update(Request $request, int $account): JsonResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:100']]);
        $baleAccount = $this->ownedAccount($request, $account);
        $baleAccount->update(['label' => $data['label']]);

        return response()->json(['data' => $this->accountData($baleAccount->refresh())]);
    }

    public function destroy(Request $request, int $account): JsonResponse
    {
        $this->ownedAccount($request, $account)->delete();

        return response()->json(status: 204);
    }

    public function signOut(Request $request, int $account): JsonResponse
    {
        $baleAccount = $this->ownedAccount($request, $account);
        $client = app(BaleClientFactory::class)->forAccount($baleAccount);
        $client->signOut()->await();
        $baleAccount->update(['access_token' => null, 'is_active' => false]);

        return response()->json(['message' => 'The Bale account session was revoked.']);
    }

    private function ownedAccount(Request $request, int $id): BaleAccount
    {
        return $request->user()->baleAccounts()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function accountData(BaleAccount $account): array
    {
        return [
            'id' => $account->id,
            'label' => $account->label,
            'phone' => $account->phone,
            'bale_user_id' => $account->bale_user_id,
            'is_active' => $account->is_active,
            'last_used_at' => $account->last_used_at?->toAtomString(),
            'created_at' => $account->created_at?->toAtomString(),
        ];
    }
}
