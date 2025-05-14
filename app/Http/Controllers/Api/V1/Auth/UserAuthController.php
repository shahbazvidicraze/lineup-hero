<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Illuminate\Validation\Rule;
use Tymon\JWTAuth\Facades\JWTAuth; // Use the Facade
use Illuminate\Support\Facades\DB;      // <-- Import DB
use Illuminate\Support\Facades\Mail;   // <-- Import Mail
use App\Mail\PasswordResetOtpMail;     // <-- Import Mailable
use Illuminate\Support\Str;            // <-- Import Str
use Carbon\Carbon;                     // <-- Import Carbon for time
use Illuminate\Validation\Rules\Password; // Import Password rule

class UserAuthController extends Controller
{
    protected $guard = 'api_user';
    protected const OTP_EXPIRY_MINUTES = 10; // OTP expiry time

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

    /**
     * Send Password Reset OTP to User's Email.
     * Route: POST /user/auth/forgot-password
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            // Should be caught by 'exists' rule, but good to double check
            return response()->json(['error' => 'User not found.'], 404);
        }

        // Generate OTP (6-digit numeric)
        $otp = Str::padLeft((string) random_int(0, 999999), 6, '0');
        $expiresAt = Carbon::now()->addMinutes(self::OTP_EXPIRY_MINUTES);

        // Store or update OTP in the database
        DB::table('password_reset_otps')->updateOrInsert(
            ['email' => $user->email],
            [
                'otp' => $otp,
                'expires_at' => $expiresAt->toDateTimeString(), // Force string conversion
                'created_at' => Carbon::now()->toDateTimeString() // Force string conversion
            ]
        );

        // Send OTP email
        try {
            Mail::to($user->email)->send(new PasswordResetOtpMail($otp, $user->first_name ?? 'User'));
            return response()->json(['message' => 'Password reset OTP has been sent to your email.']);
        } catch (\Exception $e) {
            Log::error('Failed to send password reset OTP email: ' . $e->getMessage(), ['user_email' => $user->email]);
            // Don't expose detailed error to user
            return response()->json(['error' => 'Could not send OTP email. Please try again later.'], 500);
        }
    }

    /**
     * Reset User's Password using OTP.
     * Route: POST /user/auth/reset-password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string|digits:6',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Find OTP record
        $otpRecord = DB::table('password_reset_otps')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->first();

        if (!$otpRecord) {
            return response()->json(['error' => 'Invalid or incorrect OTP.'], 400);
        }

        // Check if OTP has expired
        if (Carbon::parse($otpRecord->expires_at)->isPast()) {
            // Optionally delete expired OTP
            DB::table('password_reset_otps')->where('email', $request->email)->delete();
            return response()->json(['error' => 'OTP has expired. Please request a new one.'], 400);
        }

        // Find user and update password
        $user = User::where('email', $request->email)->first();
        if ($user) {
            $user->password = Hash::make($request->password);
            $user->save();

            // Delete OTP record after successful reset
            DB::table('password_reset_otps')->where('email', $request->email)->delete();

            // Optional: Invalidate all existing tokens for this user if you want them to re-login
            // This requires tymon/jwt-auth to be configured for blacklisting if you use that feature.
            // Or, just let existing tokens expire naturally.

            return response()->json(['message' => 'Password has been reset successfully.']);
        }

        return response()->json(['error' => 'User not found.'], 404); // Should not happen if exists rule works
    }

    /**
     * Change Authenticated User's Password.
     * Route: POST /user/auth/change-password (Requires auth)
     */
    public function changePassword(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user(); // Get authenticated user

        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string', function ($attribute, $value, $fail) use ($user) {
                if (!Hash::check($value, $user->password)) {
                    $fail('The current password does not match our records.');
                }
            }],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        // Optional: Invalidate current token and force re-login
        // try {
        //    auth($this->guard)->logout(); // Logs out current session
        // } catch (\Exception $e) { /* Silently fail or log */ }

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /**
     * Update the authenticated User's profile.
     * Route: PUT /user/auth/profile (Requires User auth)
     */
    public function updateProfile(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user(); // Get authenticated user from the 'api_user' guard

        if (!$user) {
            // Should not happen if middleware is correct
            return response()->json(['error' => 'User not authenticated.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|required|string|max:100',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:100',
                Rule::unique('users', 'email')->ignore($user->id), // Email must be unique, ignoring self
            ],
            'phone' => [
                'nullable', // Allow phone to be set to null
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($user->id), // Phone must be unique (if provided), ignoring self
            ],
            // Add other updatable fields for user profile if any
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update only the validated fields that are present in the request
        $user->fill($validator->validated());
        $user->save();

        // Return the updated user profile.
        // The User model's $hidden array should take care of sensitive fields.
        return response()->json($user);
    }
}
