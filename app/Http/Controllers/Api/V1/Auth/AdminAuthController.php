<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Admin; // Use Admin model
use Illuminate\Validation\Rule;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\Log;

class AdminAuthController extends Controller
{
    protected $guard = 'api_admin'; // Specify the admin guard

    // public function __construct()
    // {
    //     $this->middleware('auth:' . $this->guard, ['except' => ['login']]); // No public registration for admins typically
    // }

    // NOTE: No public register function for Admins usually. They are created manually or by other Admins.

    /**
     * Admin Login
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if (! $token = auth($this->guard)->attempt($validator->validated())) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        $admin = auth($this->guard)->user();
        return $this->respondWithToken($token, $admin);
    }

    /**
     * Admin Logout
     */
    public function logout()
    {
         try {
            auth($this->guard)->logout();
            return response()->json(['message' => 'Admin successfully signed out']);
         } catch (\Exception $e) {
            return response()->json(['error' => 'Could not sign out, please try again.'], 500);
         }
    }

    /**
     * Refresh Token
     */
    public function refresh()
    {
        try {
             $newToken = auth($this->guard)->refresh();
             return $this->respondWithToken($newToken, auth($this->guard)->user());
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
             return response()->json(['error' => 'Token is invalid'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
             return response()->json(['error' => 'Could not refresh token'], 500);
        }
    }

    /**
     * Get Authenticated Admin Profile
     */
    public function profile()
    {
        try {
            $admin = auth($this->guard)->userOrFail();
            return response()->json($admin);
        } catch (\Tymon\JWTAuth\Exceptions\UserNotDefinedException $e) {
             return response()->json(['error' => 'Admin not found or token invalid'], 404);
        }
    }

    /**
     * Helper function to format the token response
     */
    protected function respondWithToken($token, $admin)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth($this->guard)->factory()->getTTL() * 60,
            'user' => $admin // Or use 'admin' key if preferred
        ]);
    }

    public function changePassword(Request $request)
    {
        /** @var \App\Models\Admin $admin */
        $admin = $request->user(); // Get authenticated admin from the 'api_admin' guard

        if (!$admin) {
            // This should theoretically not happen if middleware is applied correctly
            return response()->json(['error' => 'Admin not authenticated.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string', function ($attribute, $value, $fail) use ($admin) {
                if (!Hash::check($value, $admin->password)) {
                    $fail('The current password does not match our records.');
                }
            }],
            'password' => [
                'required',
                'confirmed',
                Password::defaults(), // Use Laravel's default password strength rules
                'different:current_password' // New password must be different
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update password
        $admin->password = Hash::make($request->password);
        $admin->save();

        // Optional: Invalidate current token to force re-login for enhanced security.
        // This depends on your desired UX. If you do this, the client will get a 200 OK
        // but their current token will no longer work for subsequent requests.
        // try {
        //    auth($this->guard)->logout(); // Logs out current admin session
        //    Log::info("Admin ID {$admin->id} changed password and was logged out.");
        // } catch (\Exception $e) {
        //    Log::error("Error logging out admin after password change: " . $e->getMessage());
        // }

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /**
     * Update the authenticated Admin's profile.
     * Route: PUT /admin/auth/profile (Requires Admin auth)
     */
    public function updateProfile(Request $request)
    {
        /** @var \App\Models\Admin $admin */
        $admin = $request->user(); // Get authenticated admin

        if (!$admin) {
            return response()->json(['error' => 'Admin not authenticated.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('admins', 'email')->ignore($admin->id), // Email must be unique, ignoring self
            ],
            // Add other fields if Admins have more updatable profile attributes
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update only the validated fields that are present in the request
        $admin->fill($validator->validated());
        $admin->save();

        return response()->json($admin);
    }
}
