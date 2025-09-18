<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'currency_code' => 'nullable|string|max:3',
            'timezone' => 'nullable|string|max:50',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'currency_code' => $request->currency_code ?? 'USD',
            'timezone' => $request->timezone ?? 'UTC',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return ApiResponse::created([
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'User registered successfully');
    }

    /**
     * Login user
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return ApiResponse::error('The provided credentials are incorrect.', Response::HTTP_UNAUTHORIZED);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return ApiResponse::success([
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Login successful');
    }

    /**
     * Get authenticated user
     */
    public function me(Request $request)
    {
        return ApiResponse::success($request->user(), 'User profile retrieved successfully');
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Successfully logged out');
    }

    /**
     * Logout from all devices
     */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return ApiResponse::success(null, 'Successfully logged out from all devices');
    }

    /**
     * Update user password
     */
    public function updatePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => 'required_if:has_password,true|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            /** @var User $user */
            $user = Auth::user();
            
            // Check if user has a password (not social login only)
            if ($user->password) {
                // Verify current password
                if (!Hash::check($request->current_password, $user->password)) {
                    return ApiResponse::error('Current password is incorrect', Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            // Update password
            User::where('id', $user->id)->update([
                'password' => Hash::make($request->new_password)
            ]);

            return ApiResponse::success(null, 'Password updated successfully');
        } catch (ValidationException $e) {
            return ApiResponse::errorWithValidation($e);
        } catch (\Exception $e) {
            return ApiResponse::exception($e, 'Failed to update password');
        }
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request)
    {
        try {
            $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . Auth::id(),
                'currency_code' => 'sometimes|required|string|size:3',
                'timezone' => 'sometimes|required|string|max:255',
                'avatar' => 'sometimes|nullable|string|max:500',
            ]);

            /** @var User $user */
            $user = Auth::user();
            
            // Update only provided fields
            $updateData = $request->only(['name', 'email', 'currency_code', 'timezone', 'avatar']);
            
            User::where('id', $user->id)->update($updateData);
            
            // Refresh user data
            $updatedUser = User::find($user->id);

            return ApiResponse::success($updatedUser, 'Profile updated successfully');
        } catch (ValidationException $e) {
            return ApiResponse::errorWithValidation($e);
        } catch (\Exception $e) {
            return ApiResponse::exception($e, 'Failed to update profile');
        }
    }
}
