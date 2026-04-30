<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()->orderByDesc('id')->paginate(20);
        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name'                  => 'required|string|max:255',
                'username'              => 'required|string|max:255|unique:users',
                'password'              => 'required|string|min:6|confirmed',
                'is_admin'              => 'nullable|boolean',
                'permissions'           => 'nullable|array',
                'permissions.*'         => 'string',
            ]);

            $validated['password']    = Hash::make($validated['password']);
            $validated['is_admin']    = $validated['is_admin'] ?? false;
            $validated['permissions'] = $validated['permissions'] ?? [];

            $user = User::create($validated);

            return response()->json($user->makeHidden('password'), 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->makeHidden('password'));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name'          => 'required|string|max:255',
                'username'      => 'required|string|max:255|unique:users,username,' . $user->id,
                'password'      => 'nullable|string|min:6|confirmed',
                'is_admin'      => 'nullable|boolean',
                'permissions'   => 'nullable|array',
                'permissions.*' => 'string',
            ]);

            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }

            $validated['is_admin']    = $validated['is_admin'] ?? $user->is_admin;
            $validated['permissions'] = $validated['permissions'] ?? $user->permissions ?? [];

            $user->update($validated);

            return response()->json($user->fresh()->makeHidden('password'));
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    public function destroy(User $user): JsonResponse
    {
        if (User::count() <= 1) {
            return response()->json(['message' => 'Cannot delete the last user'], 422);
        }

        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }
}
