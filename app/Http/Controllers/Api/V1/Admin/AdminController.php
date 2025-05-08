<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User; // Use the User model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password; // Use the Password rule object

class AdminController extends Controller
{
    /**
     * Display a listing of the users.
     * Route: GET /admin/users
     */
    public function index(Request $request)
    {
        // Admins can see all users
        // Add filtering/searching later if needed
        $users = User::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'phone', 'created_at']) // Select specific fields
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($request->input('per_page', 25)); // Paginate results

        if($users->count() > 0){
            return response()->json($users);
        }

        return response()->json(['message'=>'No user created yet.']);
    }

    /**
     * Store a newly created user in storage.
     * Route: POST /admin/users
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|string|email|max:100|unique:users,email',
            'phone' => 'nullable|string|max:20|unique:users,phone',
            'password' => ['required', Password::defaults()], // Use default password rules
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();

        $user = User::create([
            'first_name' => $validatedData['first_name'],
            'last_name' => $validatedData['last_name'],
            'email' => $validatedData['email'],
            'phone' => $validatedData['phone'] ?? null,
            'password' => Hash::make($validatedData['password']),
            // Admins might set email_verified_at directly if needed
            // 'email_verified_at' => now(),
        ]);

        // Don't return password hash
        $user->makeHidden('password');

        return response()->json($user, 201);
    }

    /**
     * Display the specified user.
     * Route: GET /admin/users/{user}
     */
    public function show(User $user)
    {
        // Load relationships an admin might want to see
        $user->load('teams:id,name,user_id'); // Load associated teams (basic info)

        return response()->json($user);
    }

    /**
     * Update the specified user in storage.
     * Route: PUT /admin/users/{user}
     */
    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|required|string|max:100',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:100',
                Rule::unique('users', 'email')->ignore($user->id), // Ignore self on update
            ],
            'phone' => [
                'nullable', // Allow setting phone to null
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($user->id), // Ignore self
            ],
            'password' => ['nullable', Password::defaults()], // Allow optional password change
             // Add other fields Admins can update (e.g., status, roles if implemented)
             // 'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();

        // Update user fields except password
        $user->fill(collect($validatedData)->except('password')->toArray());

        // Update password only if provided
        if (!empty($validatedData['password'])) {
            $user->password = Hash::make($validatedData['password']);
        }

        $user->save();

        // Reload relationships if needed
        $user->load('teams:id,name,user_id');

        return response()->json($user);
    }

    /**
     * Remove the specified user from storage.
     * Route: DELETE /admin/users/{user}
     */
    public function destroy(Request $request, User $user)
    {
        // IMPORTANT: Prevent an admin from deleting themselves (optional but recommended)
        $admin = $request->user('api_admin'); // Get the authenticated admin
        if ($admin && $admin->id === $user->id) {
            return response()->json(['error' => 'Administrators cannot delete their own account.'], 403); // Forbidden
        }

        // Reminder: Deleting a user will cascade delete their associated teams, players, games
        // due to the foreign key constraints defined in the migrations. Ensure this is intended.

        try {
            $user->delete();
             return response()->json(null, 204); // No Content
        } catch (\Exception $e) {
            // Log the error maybe: Log::error("Failed to delete user {$user->id}: " . $e->getMessage());
            // Check for foreign key constraint issues if cascade isn't working as expected
            return response()->json(['error' => 'Failed to delete user. Check if they have related data that prevents deletion.'], 500);
        }
    }
}
