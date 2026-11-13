<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BaleAccount;
use App\Services\BaleClientFactory;
use App\Services\BaleMethodCatalog;
use App\Services\BaleMethodPayloadValidator;
use App\Services\BaleResponseSerializer;
use Aiobale\Exception\AiobaleException;
use Aiobale\Exception\BaleApiException;
use Aiobale\Methods\MethodRegistry;
use Aiobale\Model\BaleMethod;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Bale operations', description: 'Discover Bale RPC schemas and invoke operations on connected accounts.', weight: 50)]
class BaleOperationController extends Controller
{
    #[Endpoint(
        operationId: 'listBaleOperations',
        title: 'List available Bale operations',
        description: 'Returns the registered operation names, Bale services, request fields, and invocation routes. Use the field schema to construct the payload for an operation.',
    )]
    #[Response(type: 'array{data: list<array{name: string, service: string, method: string, fields: array<string, mixed>, route: string}>}')]
    public function index(BaleMethodCatalog $catalog): JsonResponse
    {
        $methods = [];
        foreach (MethodRegistry::all() as $name => $class) {
            if (!is_subclass_of($class, BaleMethod::class)) {
                continue;
            }
            $methods[] = $catalog->describe($name, $class);
        }

        return response()->json(['data' => $methods]);
    }

    #[Endpoint(
        operationId: 'invokeBaleOperation',
        title: 'Invoke a Bale operation',
        description: 'Invokes a registered RPC for the selected account. Put operation-specific fields inside payload; inspect GET /bale/methods for field names and types.',
    )]
    #[PathParameter('account', description: 'ID of the connected Bale account.', type: 'int')]
    #[PathParameter('method', description: 'Registered operation name, as returned by GET /bale/methods.', type: 'string')]
    #[Response(type: 'array{data: mixed}', description: 'The serialized result returned by Bale.')]
    #[Response(
        status: 404,
        type: 'array{message: string}',
        description: 'The account or operation was not found, or the operation is not available on this route.',
    )]
    #[Response(
        status: 502,
        type: 'array{error: array{code: string, message: string}}',
        description: 'Bale returned an API error or the client could not complete the request.',
    )]
    public function invoke(
        Request $request,
        int $account,
        string $method,
        BaleMethodCatalog $catalog,
        BaleMethodPayloadValidator $validator,
        BaleClientFactory $clients,
        BaleResponseSerializer $serializer,
    ): JsonResponse {
        $baleAccount = $request->user()->baleAccounts()->findOrFail($account);
        $methodClass = MethodRegistry::resolve($method);

        if ($methodClass === null || !in_array($methodClass, $catalog->methods(), true)) {
            abort(404, 'The requested Bale operation is not available through this route.');
        }

        $data = $request->validate(['payload' => ['sometimes', 'array']]);
        $payload = $data['payload'] ?? [];
        $validatedPayload = $validator->validate($methodClass, $payload);
        $baleMethod = new $methodClass($validatedPayload);
        $client = $clients->forAccount($baleAccount);

        try {
            $result = $client->invoke($baleMethod)->await();
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

        return response()->json(['data' => $serializer->normalize($result)]);
    }
}
