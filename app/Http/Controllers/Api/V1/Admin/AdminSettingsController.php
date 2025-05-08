<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminSettingsController extends Controller
{
    /**
     * Display the application settings.
     * Route: GET /admin/settings
     */
    public function show()
    {
        $settings = Settings::instance(); // Get the singleton instance
        return response()->json($settings);
    }

    /**
     * Update the application settings.
     * Route: PUT /admin/settings
     */
    public function update(Request $request)
    {
        $settings = Settings::instance(); // Get the singleton instance

        $validator = Validator::make($request->all(), [
            'optimizer_service_url' => 'sometimes|required|url',
            'unlock_price_amount' => 'sometimes|required|integer|min:0', // Store in cents
            'unlock_currency' => [
                'sometimes', 'required', 'string', 'size:3',
                // Add validation rule for supported currencies if needed
                // Rule::in(['usd', 'eur', 'gbp', ...])
            ],
            'unlock_currency_symbol' => 'sometimes|required|string|max:5',
            'unlock_currency_symbol_position' => ['sometimes', 'required', Rule::in(['before', 'after'])],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update the settings record
        $settings->fill($validator->validated());
        $settings->save();

        // Clear cache so next request gets fresh settings
        Settings::clearCache();

        return response()->json($settings);
    }
}
