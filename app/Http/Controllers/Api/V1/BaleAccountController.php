<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BaleAccount;
use App\Services\BaleClientFactory;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Bale accounts', description: 'Manage Bale accounts connected to the authenticated API user.', weight: 20)]
class BaleAccountController extends Controller
{
    #[Endpoint(
        operationId: 'listBaleAccounts',
        title: 'List Bale accounts',
        description: 'Returns only the current user’s accounts, ordered newest first.',
    )]
    #[QueryParameter('per_page', description: 'Number of accounts per page (1–100; defaults to 20).', type: 'int', default: 20)]
    #[Response(
        type: 'array{data: list<array{id: int, label: string, phone: string|null, bale_user_id: int|null, is_active: bool, last_used_at: string|null, created_at: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}',
    )]
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

    #[Endpoint(
        operationId: 'getBaleAccount',
        title: 'Get a Bale account',
        description: 'Returns an account owned by the authenticated API user.',
    )]
    #[PathParameter('account', description: 'ID of the connected Bale account.', type: 'int')]
    #[Response(type: 'array{data: array{id: int, label: string, phone: string|null, bale_user_id: int|null, is_active: bool, last_used_at: string|null, created_at: string|null}}')]
    public function show(Request $request, int $account): JsonResponse
    {
        return response()->json([
            'data' => $this->accountData($this->ownedAccount($request, $account)),
        ]);
    }

    #[Endpoint(
        operationId: 'updateBaleAccount',
        title: 'Rename a Bale account',
        description: 'Updates the display label of an account owned by the authenticated API user.',
    )]
    #[PathParameter('account', description: 'ID of the connected Bale account.', type: 'int')]
    #[Response(type: 'array{data: array{id: int, label: string, phone: string|null, bale_user_id: int|null, is_active: bool, last_used_at: string|null, created_at: string|null}}')]
    public function update(Request $request, int $account): JsonResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:100']]);
        $baleAccount = $this->ownedAccount($request, $account);
        $baleAccount->update(['label' => $data['label']]);

        return response()->json(['data' => $this->accountData($baleAccount->refresh())]);
    }

    #[Endpoint(
        operationId: 'deleteBaleAccount',
        title: 'Delete a Bale account',
        description: 'Deletes the connected account and its locally stored session.',
    )]
    #[PathParameter('account', description: 'ID of the connected Bale account.', type: 'int')]
    #[Response(status: 204, description: 'The account was deleted.')]
    public function destroy(Request $request, int $account): JsonResponse
    {
        $this->ownedAccount($request, $account)->delete();

        return response()->json(status: 204);
    }

    #[Endpoint(
        operationId: 'signOutBaleAccount',
        title: 'Sign out of Bale',
        description: 'Revokes the Bale session and marks the connected account inactive.',
    )]
    #[PathParameter('account', description: 'ID of the connected Bale account.', type: 'int')]
    #[Response(type: 'array{message: string}')]
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
