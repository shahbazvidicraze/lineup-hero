<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Http\Request;

class PositionController extends Controller
{
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
         // Admin only logic here
         return response()->json(['message' => 'Admin endpoint not implemented yet'], 501);
     }
     public function show(Position $position) {
         // Admin only logic here
         return response()->json($position); // Or restricted view for non-admins?
     }
      public function update(Request $request, Position $position) {
         // Admin only logic here
         return response()->json(['message' => 'Admin endpoint not implemented yet'], 501);
      }
     public function destroy(Position $position) {
         // Admin only logic here
          return response()->json(['message' => 'Admin endpoint not implemented yet'], 501);
     }

}
