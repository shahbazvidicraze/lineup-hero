<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Admin; // Use Admin model
use Tymon\JWTAuth\Facades\JWTAuth;

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
}
