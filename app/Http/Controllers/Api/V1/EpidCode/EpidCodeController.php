<?php

namespace App\Http\Controllers\Api\V1\EpidCode;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EpidCode\EpidCodeStoreRequest;
use App\Http\Requests\Api\V1\EpidCode\EpidCodeUpdateRequest;
use App\Http\Resources\Api\V1\EpidCode\EpidCodeResource;
use App\Models\EpidCode;
use App\Services\EpidCodeSyncService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EP ID code CRUD (Admin → EP ID Codes).
 *
 * Routes: /api/v1/ep-id-codes
 *
 * These are configurable short codes the EpIdService consumes when building
 * an EP ID. Read = master.view, write = master.manage (same as masters).
 *
 * Creating/updating a code also mirrors it into the matching master lookup
 * table (see EpidCodeSyncService) so it shows up in the app's dropdowns.
 */
class EpidCodeController extends Controller
{
    public function __construct(private readonly EpidCodeSyncService $sync) {}

    /**
     * GET /api/v1/ep-id-codes
     *
     * Optional filters: ?category=district and ?q= (search name/code).
     * Returns rows ordered by category then sort_order then name.
     *
     * ?project_id=X restricts to that project's codes (admin view).
     * ?project_id=X&include_global=1 additionally returns the shared global
     * (project_id NULL) codes, but project-specific rows override global rows
     * with the same category + district + name so consumers (entry-screen
     * dropdowns) never see duplicates.
     */
    public function index(Request $request): JsonResponse
    {
        $query = EpidCode::query();

        $includeGlobal = $request->boolean('include_global');

        if ($request->filled('project_id')) {
            $projectId = $request->integer('project_id');
            if ($includeGlobal) {
                $query->where(fn ($q) => $q->where('project_id', $projectId)->orWhereNull('project_id'));
            } else {
                $query->where('project_id', $projectId);
            }
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Project-specific rows first so they win the override dedupe below.
        $query->orderByRaw('project_id IS NULL')
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('name');

        $codes = $query->get();

        if ($includeGlobal) {
            $codes = $codes
                ->unique(fn (EpidCode $code) => $code->category.'|'.($code->district_id ?? '-').'|'.mb_strtolower($code->name))
                ->values();
        }

        return ApiResponse::success(EpidCodeResource::collection($codes), 'OK');
    }

    /**
     * GET /api/v1/ep-id-codes/categories
     *
     * Returns the supported categories (key → label) for the admin UI.
     */
    public function categories(): JsonResponse
    {
        return ApiResponse::success(EpidCode::CATEGORIES, 'OK');
    }

    /**
     * POST /api/v1/ep-id-codes
     */
    public function store(EpidCodeStoreRequest $request): JsonResponse
    {
        $code = EpidCode::create($request->validated());

        $this->sync->sync($code);

        return ApiResponse::created(new EpidCodeResource($code), 'EP ID code created.');
    }

    /**
     * PUT /api/v1/ep-id-codes/{epid_code}
     */
    public function update(EpidCodeUpdateRequest $request, EpidCode $epid_code): JsonResponse
    {
        $epid_code->update($request->validated());

        $this->sync->sync($epid_code->fresh());

        return ApiResponse::success(
            new EpidCodeResource($epid_code->fresh()),
            'EP ID code updated.',
        );
    }

    /**
     * DELETE /api/v1/ep-id-codes/{epid_code}
     *
     * Hard delete. Existing EP IDs keep their stored strings, so removing a
     * code never breaks past records — it only disables the generator for
     * future EPs. Prefer setting is_active=false for auditability.
     */
    public function destroy(EpidCode $epid_code): JsonResponse
    {
        $epid_code->delete();

        return ApiResponse::success(null, 'EP ID code deleted.');
    }
}
