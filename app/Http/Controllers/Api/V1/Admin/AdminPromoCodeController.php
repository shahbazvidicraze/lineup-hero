<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str; // For potential code generation

class AdminPromoCodeController extends Controller
{
    /**
     * Display a listing of the promo codes.
     * Route: GET /admin/promo-codes
     */
    public function index(Request $request)
    {
        $promoCodes = PromoCode::orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 25));
        return response()->json($promoCodes);
    }

    /**
     * Store a newly created promo code.
     * Route: POST /admin/promo-codes
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Allow code to be optional (auto-generate?) or required
            'code' => 'nullable|string|max:50|unique:promo_codes,code',
            'description' => 'nullable|string|max:1000',
            'expires_at' => 'nullable|date|after:now',
            'max_uses' => 'nullable|integer|min:1',
            'max_uses_per_user' => 'sometimes|required|integer|min:1',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();

        // Auto-generate code if not provided
        if (empty($validatedData['code'])) {
            $validatedData['code'] = strtoupper(Str::random(10)); // Example: 10 char uppercase random string
            // Ensure generated code is unique (rare conflict, but possible)
            while (PromoCode::where('code', $validatedData['code'])->exists()) {
                $validatedData['code'] = strtoupper(Str::random(10));
            }
        } else {
             // Ensure provided code is uppercase or handle case sensitivity consistently
             $validatedData['code'] = strtoupper($validatedData['code']);
        }

        // Ensure defaults
        $validatedData['max_uses_per_user'] = $validatedData['max_uses_per_user'] ?? 1;
        $validatedData['is_active'] = $validatedData['is_active'] ?? true;

        $promoCode = PromoCode::create($validatedData);

        return response()->json($promoCode, 201);
    }

    /**
     * Display the specified promo code.
     * Route: GET /admin/promo-codes/{promo_code}
     */
    public function show(PromoCode $promoCode)
    {
        $promoCode->loadCount('redemptions'); // Show how many times it has been used
        // Optional: Load recent redemptions? -> $promoCode->load('redemptions.user:id,first_name,last_name');
        return response()->json($promoCode);
    }

    /**
     * Update the specified promo code.
     * Route: PUT /admin/promo-codes/{promo_code}
     */
    public function update(Request $request, PromoCode $promoCode)
    {
        $validator = Validator::make($request->all(), [
             // Code cannot be changed after creation
            'description' => 'nullable|string|max:1000',
            'expires_at' => 'nullable|date', // Allow setting past date to expire immediately
            'max_uses' => 'nullable|integer|min:' . $promoCode->use_count, // Cannot set max uses below current count
            'max_uses_per_user' => 'sometimes|required|integer|min:1',
            'is_active' => 'sometimes|boolean',
        ]);

         if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $promoCode->update($validator->validated());
        $promoCode->loadCount('redemptions');

        return response()->json($promoCode);
    }

    /**
     * Remove the specified promo code.
     * Route: DELETE /admin/promo-codes/{promo_code}
     * Consider making this a soft delete or deactivate via PUT instead.
     * Current implementation: Hard delete, but only if never used.
     */
    public function destroy(PromoCode $promoCode)
    {
        // Prevent deleting codes that have been used. Admins should deactivate instead.
        if ($promoCode->use_count > 0) {
            return response()->json(['error' => 'Cannot delete a promo code that has already been used. Deactivate it instead.'], 409); // Conflict
        }

        $promoCode->delete();

        return response()->json(null, 204);
    }
}
