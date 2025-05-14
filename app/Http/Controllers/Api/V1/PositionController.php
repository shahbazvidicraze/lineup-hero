<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    private $corePositions = ['P', 'C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF', 'OUT'];
    private $nonEditablePositions = ['OUT']; // Define positions whose 'is_editable' flag or name cannot be changed
    /**
     * Display a listing of the resource.
     * Used by Users (Coaches) to get available positions for preferences.
     * Used by Admins (potentially with more detail or filtering).
     */
    public function index()
    {
        // Return all positions, potentially sorted
        $positions = Position::orderBy('category')->orderBy('name')->get();
        return response()->json($positions);
    }

    // store, show, update, destroy methods will be used by Admins
    // We'll implement them when building Admin features, adding checks for is_editable etc.
    public function store(Request $request) {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:10|unique:positions,name',
            'display_name' => 'required|string|max:50', // <-- ADDED VALIDATION
            'category' => 'required|string|max:50',
            'is_editable' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();
        $validatedData['is_editable'] = $validatedData['is_editable'] ?? true;

        $position = Position::create($validatedData);
        return response()->json($position, 201);
    }
    public function show(Position $position) {
        // Admin only logic here
        return response()->json($position); // Or restricted view for non-admins?
    }
    public function update(Request $request, Position $position)
    {
        // Prevent critical changes to non-editable positions
        if (!$position->is_editable || in_array($position->name, $this->nonEditablePositions)) {
            if (($request->has('name') && $request->input('name') !== $position->name) ||
                ($request->has('is_editable') && $request->boolean('is_editable') != $position->is_editable) ) {
                return response()->json(['error' => "Cannot change core attributes of the non-editable position '{$position->name}'."], 403);
            }
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'sometimes', 'required', 'string', 'max:10',
                Rule::unique('positions', 'name')->ignore($position->id),
            ],
            'display_name' => 'sometimes|required|string|max:50', // <-- ADDED VALIDATION
            'category' => 'sometimes|required|string|max:50',
            'is_editable' => 'sometimes|required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $validatedData = $validator->validated();

        // Prevent making essential non-editable positions editable
        if (in_array($position->name, $this->nonEditablePositions) && isset($validatedData['is_editable']) && $validatedData['is_editable']) {
            return response()->json(['error' => "Position '{$position->name}' cannot be made editable."], 403);
        }

        $position->update($validator->validated());
        return response()->json($position);
    }

    public function destroy(Position $position)
    {
        // ... (destroy logic remains the same) ...
        if (!$position->is_editable || in_array($position->name, $this->corePositions)) {
            return response()->json(['error' => "Cannot delete the core/non-editable position '{$position->name}'."], 403);
        }
        if ($position->playerPreferences()->exists()) {
            return response()->json(['error' => "Cannot delete position '{$position->name}' as it is used in preferences."], 409);
        }
        try {
            $position->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete position.'], 500);
        }
    }

}
