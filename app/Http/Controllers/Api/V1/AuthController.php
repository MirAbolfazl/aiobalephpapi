<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

#[Group('Authentication', description: 'Register API users, issue personal access tokens, and manage the current API session.', weight: 10)]
class AuthController extends Controller
{
    #[Endpoint(
        operationId: 'registerApiUser',
        title: 'Register an API user',
        description: 'Creates a user and returns a personal Bearer token. Passwords must be at least 12 characters.',
    )]
    #[Response(
        status: 201,
        type: 'array{data: array{user: array{id: int, name: string, email: string}, token: string}}',
        description: 'The account and newly issued API token.',
    )]
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'token_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $token = $user->createToken($data['token_name'] ?? 'api-client', ['*']);

        return response()->json([
            'data' => ['user' => $this->userData($user), 'token' => $token->plainTextToken],
        ], 201);
    }

    #[Endpoint(
        operationId: 'loginApiUser',
        title: 'Log in',
        description: 'Validates API credentials and issues a new personal Bearer token.',
    )]
    #[Response(
        type: 'array{data: array{user: array{id: int, name: string, email: string}, token: string}}',
        description: 'The authenticated user and newly issued API token.',
    )]
    #[Response(
        status: 401,
        type: 'array{error: array{code: string, message: string}}',
        description: 'The supplied email or password is invalid.',
    )]
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
            'token_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if ($user === null || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_credentials',
                    'message' => 'The provided credentials are invalid.',
                ],
            ], 401);
        }

        $token = $user->createToken($data['token_name'] ?? 'api-client', ['*']);

        return response()->json([
            'data' => ['user' => $this->userData($user), 'token' => $token->plainTextToken],
        ]);
    }

    #[Endpoint(
        operationId: 'getCurrentApiUser',
        title: 'Get the current user',
        description: 'Returns the profile associated with the supplied API token.',
    )]
    #[Response(type: 'array{data: array{id: int, name: string, email: string}}')]
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userData($request->user())]);
    }

    #[Endpoint(
        operationId: 'logoutApiUser',
        title: 'Log out',
        description: 'Revokes the token used for this request. Other tokens owned by the user remain valid.',
    )]
    #[Response(type: 'array{message: string}')]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /** @return array{id: int, name: string, email: string} */
    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
