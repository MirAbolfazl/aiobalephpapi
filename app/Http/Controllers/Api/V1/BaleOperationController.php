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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BaleOperationController extends Controller
{
    /** @response array{data: list<array{name: string, service: string, method: string, fields: array<string, mixed>, route: string}>} */
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

    /** @response array{data: mixed}|array{error: array{code: string, message: string}} */
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
