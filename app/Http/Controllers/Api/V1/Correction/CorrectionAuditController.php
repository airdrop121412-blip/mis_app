<?php

namespace App\Http\Controllers\Api\V1\Correction;

use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Business;
use App\Models\Crop;
use App\Models\Infrastructure;
use App\Models\Land;
use App\Models\Tenant;
use App\Models\Tree;
use App\Models\Worker;
use App\Services\Audit\CorrectionAuditService;
use App\Services\Audit\StatusHistoryService;
use App\Services\Auth\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data-correction API.
 *
 * Allows authorized users to correct entity data while maintaining
 * a complete audit trail. Financial corrections require authorization.
 */
class CorrectionAuditController extends Controller
{
    /**
     * Entity-to-model mapping for dynamic correction.
     */
    private const ENTITY_MODELS = [
        'EP' => AffectedPerson::class,
        'LAND' => Land::class,
        'TREE' => Tree::class,
        'INFRASTRUCTURE' => Infrastructure::class,
        'CROP' => Crop::class,
        'TENANT' => Tenant::class,
        'BUSINESS' => Business::class,
        'WORKER' => Worker::class,
    ];

    /**
     * Fields that are financial (require authorization to change).
     */
    private const FINANCIAL_FIELDS = [
        'assessed_loss',
        'damage_amount',
        'total_damage',
        'total_compensation',
        'approved_amount',
        'monthly_income',
        'monthly_expense',
    ];

    /**
     * Fields that are NOT editable after DRAFT status.
     */
    private const LOCKED_FIELDS_ON_SUBMIT = [
        'name',
        'father_name',
        'mother_name',
        'spouse_name',
        'nid_number',
        'district_id',
        'upazila_id',
    ];

    public function __construct(
        private readonly CorrectionAuditService $correctionService,
        private readonly StatusHistoryService $historyService,
        private readonly AuthService $authService,
    ) {}

    /**
     * GET /api/v1/corrections
     *
     * List corrections for a project, optionally filtered by entity type or EP search.
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->input('project_id');
        if (! $projectId) {
            return ApiResponse::error('project_id is required.', 'VALIDATION_ERROR', 422);
        }

        $entityType = $request->input('entity_type');
        $epSearch = $request->input('q');
        $perPage = (int) $request->input('per_page', 25);

        $paginator = $this->correctionService->listCorrections($projectId, $entityType, $epSearch, $perPage);

        return ApiResponse::success(
            $paginator->items(),
            'OK',
            200,
            [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        );
    }

    /**
     * POST /api/v1/corrections
     *
     * Apply a data correction with audit trail.
     *
     * Request body:
     * {
     *   "entity_type": "EP",
     *   "entity_id": 1,
     *   "field_name": "nid_number",
     *   "new_value": "1234567890",
     *   "correction_reason": "Corrected from paper record",
     *   "authorized": false
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => 'required|string|in:EP,LAND,TREE,INFRASTRUCTURE,CROP,TENANT,BUSINESS,WORKER',
            'entity_id' => 'required|integer|min:1',
            'field_name' => 'required|string|max:100',
            'new_value' => 'nullable|string|max:2000',
            'correction_reason' => 'required|string|max:500',
            'authorized' => 'boolean',
        ]);

        $entityType = $request->input('entity_type');
        $entityId = (int) $request->input('entity_id');
        $fieldName = $request->input('field_name');
        $newValue = $request->input('new_value');
        $correctionReason = $request->input('correction_reason');
        $authorized = $request->boolean('authorized', false);
        $userId = auth()->id();

        $modelClass = self::ENTITY_MODELS[$entityType] ?? null;
        if (! $modelClass) {
            return ApiResponse::error("Invalid entity type: {$entityType}", 'INVALID_ENTITY_TYPE', 422);
        }

        // Load the entity
        $entity = $modelClass::find($entityId);
        if (! $entity) {
            return ApiResponse::error("{$entityType} #{$entityId} not found.", 'NOT_FOUND', 404);
        }

        // Check editability (EP must be DRAFT)
        if ($entityType === 'EP' && ! in_array($entity->status ?? null, ['DRAFT', null], true)) {
            return ApiResponse::error(
                "EP must be in DRAFT status to edit (current: {$entity->status}).",
                'EP_NOT_EDITABLE',
                422,
            );
        }

        // Financial impact check
        $financialImpact = in_array($fieldName, self::FINANCIAL_FIELDS, true);
        if ($financialImpact && ! $authorized) {
            $user = auth()->user();
            $isApprover = $this->authService->hasPermission($user, 'ep.approve');
            if (! $isApprover) {
                return ApiResponse::error(
                    'Financial corrections require authorization from an approved approver.',
                    'FINANCIAL_CORRECTION_UNAUTHORIZED',
                    403,
                );
            }
        }

        // Get old value
        $oldValue = $entity->{$fieldName} !== null ? (string) $entity->{$fieldName} : null;

        // No actual change check
        if ($oldValue === $newValue || ($oldValue === null && $newValue === null)) {
            return ApiResponse::error('No change detected.', 'NO_CHANGE', 422);
        }

        // Get EP ID for denormalization
        $epId = null;
        $projectId = null;
        $affectedPersonId = null;
        if ($entityType === 'EP') {
            $epId = $entity->ep_id;
            $projectId = $entity->project_id;
            $affectedPersonId = $entity->affected_person_id;
        } elseif (method_exists($entity, 'affected_person_id')) {
            $epModel = AffectedPerson::find($entity->affected_person_id);
            if ($epModel) {
                $epId = $epModel->ep_id;
                $projectId = $epModel->project_id;
                $affectedPersonId = $epModel->affected_person_id;
            }
        }

        // Apply correction in transaction
        DB::transaction(function () use ($modelClass, $entityId, $fieldName, $newValue, $entityType, $oldValue, $correctionReason, $userId, $financialImpact, $authorized, $epId, $projectId) {
            // Update the entity
            $modelClass::where((new $modelClass)->getKeyName(), $entityId)
                ->update([$fieldName => $newValue]);

            // Record audit
            $this->correctionService->record(
                $entityType,
                $entityId,
                $fieldName,
                $oldValue,
                $newValue,
                $correctionReason,
                $userId,
                $financialImpact,
                $authorized,
                $authorized ? $userId : null,
                $epId,
                $projectId,
            );
        });

        return ApiResponse::created([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'affected_person_id' => $affectedPersonId,
            'field_name' => $fieldName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'financial_impact' => $financialImpact,
        ], 'Correction applied.');
    }

    /**
     * GET /api/v1/corrections/{entityType}/{entityId}/history
     *
     * Get correction history for a specific entity.
     */
    public function history(string $entityType, int $entityId): JsonResponse
    {
        $upperType = strtoupper($entityType);
        $history = $this->correctionService->getHistory($upperType, $entityId);

        return ApiResponse::success($history);
    }
}
