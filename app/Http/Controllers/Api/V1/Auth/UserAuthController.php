<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth; // Use the Facade

class UserAuthController extends Controller
{
    protected $guard = 'api_user'; // Specify the guard

    // public function __construct()
    // {
    //     // Apply middleware, except for login and register
    //     $this->middleware('auth:' . $this->guard, ['except' => ['login', 'register']]);
    // }

    /**
     * User Registration
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // 'name' => 'required|string|between:2,100', // Removed 'name'
            'first_name' => 'required|string|max:100',    // Added
            'last_name' => 'required|string|max:100',     // Added
            'email' => 'required|string|email|max:100|unique:users,email', // Unique rule is important
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:20|unique:users,phone', // Added, make unique and nullable
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Use validated data, which now includes the new fields
        $validatedData = $validator->validated();

        $user = User::create([
            'first_name' => $validatedData['first_name'], // Use first_name
            'last_name' => $validatedData['last_name'],   // Use last_name
            'email' => $validatedData['email'],
            'phone' => $validatedData['phone'] ?? null,     // Use phone, handle null
            'password' => Hash::make($validatedData['password']),
        ]);

        // Generate token after successful registration
        $token = JWTAuth::fromUser($user);

        return $this->respondWithToken($token, $user);
    }

    /**
     * User Login
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

         $user = auth($this->guard)->user(); // Get authenticated user from the guard
         return $this->respondWithToken($token, $user);
    }

    /**
     * User Logout
     */
    public function logout()
    {
        try {
            auth($this->guard)->logout();
            return response()->json(['message' => 'User successfully signed out']);
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
     * Get Authenticated User Profile
     */
    public function profile()
    {
        try {
            // auth($this->guard)->userOrFail() will fetch the user with the new fields
            $user = auth($this->guard)->userOrFail();
            return response()->json($user);
        } catch (\Tymon\JWTAuth\Exceptions\UserNotDefinedException $e) {
             return response()->json(['error' => 'User not found or token invalid'], 404);
        }
    }

    /**
     * Helper function to format the token response
     */
    protected function respondWithToken($token, $user)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth($this->guard)->factory()->getTTL() * 60,
            'user' => $user // The $user object now contains the new fields
        ]);
    }
}
