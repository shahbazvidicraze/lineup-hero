<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait; // <-- USE TRAIT
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Response;

class OrganizationController extends Controller
{
    use ApiResponseTrait; // <-- INCLUDE TRAIT

    /**
     * Display a listing of organizations.
     * Route: GET /organizations (User)
     * Route: GET /admin/organizations (Admin)
     */
    public function index(Request $request)
    {
        if ($request->user('api_admin')) {
            $organizations = Organization::orderBy('name')->paginate($request->input('per_page', 25));
            return $this->successResponse($organizations, 'Organizations retrieved successfully (Admin View).');
        } else {
            $organizations = Organization::select('id', 'name')->orderBy('name')->get();
            return $this->successResponse($organizations, 'Organizations retrieved successfully.');
        }
    }

    /**
     * Store a newly created organization (Admin only).
     * Route: POST /admin/organizations
     */
    public function store(Request $request)
    {
        // Auth via 'auth:api_admin' middleware
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:organizations,name',
            'email' => 'nullable|email|max:255|unique:organizations,email',
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $organization = Organization::create($validator->validated());
        return $this->successResponse($organization, 'Organization created successfully.', Response::HTTP_CREATED);
    }

    /**
     * Display the specified organization (Admin only).
     * Route: GET /admin/organizations/{organization}
     */
    public function show(Organization $organization)
    {
        // Auth via 'auth:api_admin' middleware
        $organization->load('teams:id,name,organization_id');
        return $this->successResponse($organization);
    }

    /**
     * Update the specified organization (Admin only).
     * Route: PUT /admin/organizations/{organization}
     */
    public function update(Request $request, Organization $organization)
    {
        // Auth via 'auth:api_admin' middleware
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes','required','string','max:255', Rule::unique('organizations', 'name')->ignore($organization->id)],
            'email' => ['nullable','email','max:255', Rule::unique('organizations', 'email')->ignore($organization->id)],
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $organization->update($validator->validated());
        $organization->load('teams:id,name,organization_id');
        return $this->successResponse($organization, 'Organization updated successfully.');
    }

    /**
     * Remove the specified organization (Admin only).
     * Route: DELETE /admin/organizations/{organization}
     */
    public function destroy(Organization $organization)
    {
        // Auth via 'auth:api_admin' middleware
        // $organization->teams()->update(['organization_id' => null]); // Handled by DB constraint ON DELETE SET NULL
        $organization->delete();
        return $this->successResponse(null, 'Organization deleted successfully.', Response::HTTP_OK, false);
    }
}
