<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{

    /**
     * POST /api/register
     * Create a new user account and return a Sanctum Bearer token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully.',
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'token'   => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * POST /api/login
     * Authenticate and return a Sanctum Bearer token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        // Revoke old tokens to keep things clean (optional – remove if multi-device)
        $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully.',
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'token'      => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * POST /api/logout
     * Revoke the current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
