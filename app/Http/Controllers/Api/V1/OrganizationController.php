<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // Need this if checking admin type explicitly
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    /**
     * Display a listing of organizations.
     * Accessible by **Users** (Coaches) for team assignment (simple list).
     * Accessible by **Admins** for management (potentially with more detail/pagination).
     * Route: GET /organizations (for Users via User middleware)
     * Route: GET /admin/organizations (for Admins via Admin middleware)
     */
    public function index(Request $request)
    {
        // Check if the request is coming through the admin guard/route context
        // Since routes are separate, middleware handles access control.
        // We might return different data based on who is asking.
        if ($request->user('api_admin')) { // Check if authenticated via admin guard
            // Admin view: Paginated, more details?
            $organizations = Organization::orderBy('name')->paginate(25); // Example pagination for admin
        } else {
            // User view: Simple list for dropdowns
            $organizations = Organization::select('id', 'name')->orderBy('name')->get();
        }

        return response()->json($organizations);
    }

    /**
     * Store a newly created organization in storage.
     * Accessible only by **Admins**.
     * Route: POST /admin/organizations (protected by auth:api_admin)
     */
    public function store(Request $request)
    {
        // Authorization is handled by the 'auth:api_admin' middleware in routes/api.php

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:organizations,name',
            'email' => 'nullable|email|max:255|unique:organizations,email',
            // Add validation for other fields if organizations have more details (address, etc.)
            // 'contact_person' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();
        // Note: Orgs created by Admins don't need password/login initially based on requirements
        // If Orgs needed direct login, you'd add password handling here.

        $organization = Organization::create($validatedData);

        return response()->json($organization, 201); // 201 Created
    }

    /**
     * Display the specified organization.
     * Accessible only by **Admins**.
     * Route: GET /admin/organizations/{organization} (protected by auth:api_admin)
     */
    public function show(Organization $organization)
    {
        // Authorization handled by middleware + route model binding finds the org

        // Admins might want to see associated teams
        $organization->load('teams:id,name,organization_id'); // Load team IDs and names

        return response()->json($organization);
    }

    /**
     * Update the specified organization in storage.
     * Accessible only by **Admins**.
     * Route: PUT /admin/organizations/{organization} (protected by auth:api_admin)
     */
    public function update(Request $request, Organization $organization)
    {
        // Authorization handled by middleware

        $validator = Validator::make($request->all(), [
            // Use 'sometimes' for PUT requests
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('organizations', 'name')->ignore($organization->id), // Ignore self on update
            ],
            'email' => [
                'nullable', // Allow email to be null
                'email',
                'max:255',
                Rule::unique('organizations', 'email')->ignore($organization->id), // Ignore self
            ],
            // 'contact_person' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $organization->update($validator->validated());

        // Optionally reload relationships if needed
        $organization->load('teams:id,name,organization_id');

        return response()->json($organization);
    }

    /**
     * Remove the specified organization from storage.
     * Accessible only by **Admins**.
     * Route: DELETE /admin/organizations/{organization} (protected by auth:api_admin)
     */
    public function destroy(Organization $organization)
    {
        // Authorization handled by middleware

        // Consider implications: What happens to teams linked to this organization?
        // The migration uses ->nullOnDelete() for organization_id in the teams table,
        // so deleting an org will set the team's organization_id to NULL. This is usually safe.
        // If you wanted to prevent deletion if teams are linked, you'd add a check here:
        // if ($organization->teams()->exists()) {
        //     return response()->json(['error' => 'Cannot delete organization with associated teams.'], 409); // 409 Conflict
        // }

        $organization->delete();

        return response()->json(null, 204); // No Content
    }
}
