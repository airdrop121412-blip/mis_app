<?php

namespace App\Http\Controllers\Api\V1\Validation;

use App\Http\Controllers\Controller;
use App\Services\Validation\ValidationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Validation API — exposes centralized Laravel validation rules to React frontend.
 *
 * The frontend MUST NOT duplicate business rules. It calls these endpoints
 * and renders the machine-readable errors returned.
 */
class ValidationController extends Controller
{
    public function __construct(
        private readonly ValidationService $validationService,
    ) {}

    /**
     * GET /api/v1/validation/ep/{ep}
     *
     * Validate a single EP and return all issues.
     */
    public function validateEp(int $ep): JsonResponse
    {
        $result = $this->validationService->validateEp($ep);

        return ApiResponse::success($result);
    }

    /**
     * GET /api/v1/validation/project/{project}/eps
     *
     * Validate all EPs in a project and return those with issues.
     */
    public function validateProjectEps(int $project): JsonResponse
    {
        $results = $this->validationService->validateProjectEps($project);

        return ApiResponse::success([
            'project_id' => $project,
            'invalid_count' => count($results),
            'items' => $results,
        ]);
    }

    /**
     * GET /api/v1/validation/ep/{ep}/epicad-readiness
     *
     * Check if an EP is ready for EPICAD generation.
     */
    public function epicadReadiness(int $ep): JsonResponse
    {
        $result = $this->validationService->validateEpicadReadiness($ep);

        return ApiResponse::success($result);
    }

    /**
     * GET /api/v1/validation/ep/{ep}/ec-readiness
     *
     * Check if an EP is ready for EC generation.
     */
    public function ecReadiness(int $ep): JsonResponse
    {
        $result = $this->validationService->validateEcReadiness($ep);

        return ApiResponse::success($result);
    }

    /**
     * GET /api/v1/validation/ep/{ep}/voucher-readiness
     *
     * Check if an EP is ready for voucher generation.
     */
    public function voucherReadiness(int $ep): JsonResponse
    {
        $result = $this->validationService->validateVoucherReadiness($ep);

        return ApiResponse::success($result);
    }

    /**
     * GET /api/v1/validation/eps/invalid
     *
     * Paginated list of EPs that have NID or Mobile data-quality issues
     * (blank, invalid format, or duplicate within the project).
     * Accepts project_id, per_page, and page query params.
     */
    public function invalidEps(Request $request): JsonResponse
    {
        $projectId = $request->filled('project_id') ? (int) $request->input('project_id') : null;
        $perPage = (int) $request->input('per_page', 25);
        $page = (int) $request->input('page', 1);

        $result = $this->validationService->getInvalidEpsPaginated($projectId, $perPage, $page);

        return ApiResponse::success(
            $result['data'],
            'Invalid EPs retrieved.',
            200,
            [
                'pagination' => $result['pagination'],
            ],
        );
    }

    /**
     * GET /api/v1/validation/project/{project}/summary
     *
     * Aggregate validation summary for a project.
     */
    public function projectSummary(int $project): JsonResponse
    {
        $result = $this->validationService->getProjectSummary($project);

        return ApiResponse::success($result);
    }
}
