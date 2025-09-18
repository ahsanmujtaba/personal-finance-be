<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Validation\ValidationException;

class SocialAuthController extends Controller
{
    /**
     * Redirect to Google OAuth provider
     */
    public function redirectToGoogle()
    {
        try {
            $redirectUrl = Socialite::driver('google')->redirect()->getTargetUrl();
            
            return response()->json([
                'redirect_url' => $redirectUrl
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate Google OAuth URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle Google OAuth callback
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $request->validate([
                'code' => 'required|string',
            ]);

            // Get user from Google
            $googleUser = Socialite::driver('google')->user();
            
            return $this->handleSocialLogin($googleUser, 'google');
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Google authentication failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Redirect to Facebook OAuth provider
     */
    public function redirectToFacebook()
    {
        try {
            $redirectUrl = Socialite::driver('facebook')->redirect()->getTargetUrl();
            
            return response()->json([
                'redirect_url' => $redirectUrl
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate Facebook OAuth URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle Facebook OAuth callback
     */
    public function handleFacebookCallback(Request $request)
    {
        try {
            $request->validate([
                'code' => 'required|string',
            ]);

            // Get user from Facebook
            $facebookUser = Socialite::driver('facebook')->user();
            
            return $this->handleSocialLogin($facebookUser, 'facebook');
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Facebook authentication failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle social login for both Google and Facebook
     */
    private function handleSocialLogin($socialUser, $provider)
    {
        try {
            // Check if user already exists with this provider
            $existingUser = User::where('provider', $provider)
                               ->where('provider_id', $socialUser->getId())
                               ->first();

            if ($existingUser) {
                // Update user info
                $existingUser->update([
                    'name' => $socialUser->getName(),
                    'avatar' => $socialUser->getAvatar(),
                ]);
                
                $user = $existingUser;
            } else {
                // Check if user exists with same email
                $userWithEmail = User::where('email', $socialUser->getEmail())->first();
                
                if ($userWithEmail) {
                    // Link social account to existing user
                    $userWithEmail->update([
                        'provider' => $provider,
                        'provider_id' => $socialUser->getId(),
                        'avatar' => $socialUser->getAvatar(),
                    ]);
                    
                    $user = $userWithEmail;
                } else {
                    // Create new user
                    $user = User::create([
                        'name' => $socialUser->getName(),
                        'email' => $socialUser->getEmail(),
                        'provider' => $provider,
                        'provider_id' => $socialUser->getId(),
                        'avatar' => $socialUser->getAvatar(),
                        'password' => null, // No password for social login
                        'currency_code' => 'USD',
                        'timezone' => 'UTC',
                    ]);
                }
            }

            // Create token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Authentication successful',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'currency_code' => $user->currency_code,
                    'timezone' => $user->timezone,
                    'provider' => $user->provider,
                ],
                'token' => $token
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Social authentication failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
