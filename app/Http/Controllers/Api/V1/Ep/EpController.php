<?php

namespace App\Http\Controllers\Api\V1\Ep;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ep\AffectedPersonStoreRequest;
use App\Http\Requests\Api\V1\Ep\AffectedPersonUpdateRequest;
use App\Http\Resources\Api\V1\Ep\AffectedPersonResource;
use App\Models\AdditionalPaymentRecord;
use App\Models\AffectedPerson;
use App\Models\Compensation;
use App\Models\Ec;
use App\Models\Epicad;
use App\Models\Tenant;
use App\Models\Worker;
use App\Services\Audit\CorrectionAuditService;
use App\Services\Audit\StatusHistoryService;
use App\Services\Ep\EcCalculationService;
use App\Services\Ep\EpDeletionService;
use App\Services\EpId\EpIdService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Affected Person / EP controller (architecture §5.4, OP-4–OP-6).
 *
 * CRUD + status transitions (submit/verify/approve/delete).
 */
class EpController extends Controller
{
    private const VALID_TRANSITIONS = [
        'DRAFT' => ['SUBMITTED'],
        'SUBMITTED' => ['VERIFIED'],
        'VERIFIED' => ['APPROVED'],
    ];

    /**
     * EP-level fields treated as financial for correction auditing.
     */
    private const FINANCIAL_EP_FIELDS = [
        'dc_compensation',
        'dc_tree_compensation',
        'yearly_income',
        'monthly_income',
        'monthly_expense',
        'stamp_duty',
    ];

    public function __construct(
        private readonly EpIdService $epIdService,
        private readonly StatusHistoryService $historyService,
        private readonly EcCalculationService $ecCalculation,
        private readonly CorrectionAuditService $correctionService,
        private readonly EpDeletionService $deletionService,
    ) {}

    /**
     * GET /api/v1/ep
     *
     * Filterable: project_id, district_id, upazila_id, status, q (search ep_id/name).
     */
    public function index(Request $request): JsonResponse
    {
        $query = AffectedPerson::query()
            ->with(['gender:gender_id,name', 'religion:religion_id,name', 'occupation:occupation_id,name',
                'district:district_id,name', 'upazila:upazila_id,name', 'landStatus:land_status_id,name', 'rcType:rc_type_id,name',
                'ec:affected_person_id,status,ec_number']);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }
        if ($request->filled('district_id')) {
            $query->where('district_id', $request->input('district_id'));
        }
        if ($request->filled('upazila_id')) {
            $query->where('upazila_id', $request->input('upazila_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where(function ($q) use ($search) {
                $q->where('ep_id', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('nid_number', 'like', "%{$search}%");
            });
        }

        // Default sort: HH ID ascending (natural numeric order), then by record id.
        $query->orderByRaw('CAST(hh_id AS UNSIGNED) ASC, affected_person_id ASC');

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->paginate($perPage);

        return ApiResponse::success(
            AffectedPersonResource::collection($paginator),
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
     * GET /api/v1/ep/lookup?q=...
     *
     * Type-ahead lookup for the EPICAD tab: finds an EP by its EP ID
     * code (or numeric id / name) so a card can be generated.
     */
    public function lookup(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        // Scope results to the caller's active project so EPs from other
        // projects never leak into card/file-generator searches.
        $projectId = $request->filled('project_id') ? $request->integer('project_id') : null;

        $with = ['gender:gender_id,name', 'religion:religion_id,name', 'occupation:occupation_id,name',
            'district:district_id,name', 'upazila:upazila_id,name', 'landStatus:land_status_id,name', 'rcType:rc_type_id,name',
            'ec:affected_person_id,status,ec_number'];

        // When the search matches a Tenant / Wage Labour worker EP ID (e.g.
        // 3030318#0004) we still return the parent EP, tagged with the card
        // kind + subject so EPICAD can generate that person's card directly.
        $tags = []; // affected_person_id => ['kind', 'subject', 'subject_ep_id', 'subject_name']

        if ($search !== '') {
            foreach (AffectedPerson::where(function ($q) use ($search) {
                $q->where('ep_id', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('affected_person_id', 'like', "%{$search}%");
            })->when($projectId, fn ($q) => $q->where('project_id', $projectId))
                ->pluck('affected_person_id') as $id) {
                $tags[$id] ??= ['kind' => 'ep', 'subject' => null, 'subject_ep_id' => null, 'subject_name' => null];
            }

            foreach (Tenant::where('ep_id', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")
                ->when($projectId, fn ($q) => $q->whereHas('affectedPerson', fn ($ap) => $ap->where('project_id', $projectId)))
                ->get(['affected_person_id', 'tenant_id', 'ep_id', 'name']) as $tenant) {
                if (! isset($tags[$tenant->affected_person_id]) || $tags[$tenant->affected_person_id]['kind'] === 'ep') {
                    $tags[$tenant->affected_person_id] = [
                        'kind' => 'tenant',
                        'subject' => $tenant->tenant_id,
                        'subject_ep_id' => $tenant->ep_id,
                        'subject_name' => $tenant->name,
                    ];
                }
            }

            foreach (Worker::where('ep_id', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")
                ->when($projectId, fn ($q) => $q->whereHas('affectedPerson', fn ($ap) => $ap->where('project_id', $projectId)))
                ->get(['affected_person_id', 'worker_id', 'ep_id', 'name']) as $worker) {
                if (! isset($tags[$worker->affected_person_id]) || $tags[$worker->affected_person_id]['kind'] === 'ep') {
                    $tags[$worker->affected_person_id] = [
                        'kind' => 'worker',
                        'subject' => $worker->worker_id,
                        'subject_ep_id' => $worker->ep_id,
                        'subject_name' => $worker->name,
                    ];
                }
            }
        }

        $query = AffectedPerson::query()->with($with)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId));

        if ($search !== '') {
            $ids = array_keys($tags);
            $query->whereIn('affected_person_id', $ids);
            if ($ids === []) {
                $query->whereRaw('1 = 0');
            }
        }

        $limit = min((int) $request->input('limit', 20), 50);
        $items = $query->orderByDesc('affected_person_id')->limit($limit)->get();

        foreach ($items as $ep) {
            $tag = $tags[$ep->affected_person_id] ?? ['kind' => 'ep', 'subject' => null, 'subject_ep_id' => null, 'subject_name' => null];
            $ep->card_kind = $tag['kind'];
            $ep->card_subject_id = $tag['subject'];
            $ep->card_subject_ep_id = $tag['subject_ep_id'];
            $ep->card_subject_name = $tag['subject_name'] ?? null;
        }

        return ApiResponse::success(AffectedPersonResource::collection($items), 'OK');
    }

    /**
     * POST /api/v1/ep  (OP-4)
     *
     * Creates the EP inside a transaction with the generated EP ID.
     */
    public function store(AffectedPersonStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $ep = DB::transaction(function () use ($data) {
            $epId = $this->epIdService->generate(
                $data['project_id'],
                $data['district_id'],
                $data['upazila_id'],
                $data['land_status_id'],
                $data['category_of_loss'] ?? null,
                $data['ownership'] ?? null,
                $data['phase'] ?? null,
            );

            $ep = AffectedPerson::create([
                ...$data,
                'ep_id' => $epId,
                'status' => 'DRAFT',
            ]);

            $this->historyService->record(
                'EP',
                $ep->affected_person_id,
                null,
                'DRAFT',
                auth()->id(),
                'EP created.',
            );

            return $ep;
        });

        $ep->load(['gender:gender_id,name', 'district:district_id,name', 'upazila:upazila_id,name',
            'landStatus:land_status_id,name', 'rcType:rc_type_id,name']);

        return ApiResponse::created(
            new AffectedPersonResource($ep),
            'EP created.',
        )->header('Location', route('ep.show', $ep->affected_person_id));
    }

    /**
     * GET /api/v1/ep/{ep}
     */
    public function show(AffectedPerson $ep): JsonResponse
    {
        $ep->load(['gender:gender_id,name', 'religion:religion_id,name', 'occupation:occupation_id,name',
            'district:district_id,name', 'upazila:upazila_id,name', 'landStatus:land_status_id,name', 'rcType:rc_type_id,name']);

        return ApiResponse::success(new AffectedPersonResource($ep), 'OK');
    }

    /**
     * PUT /api/v1/ep/{ep}  (OP-5)
     *
     * Entry operators may edit DRAFT records. Administrators may correct
     * submitted or verified records, but APPROVED records are locked.
     */
    public function update(AffectedPersonUpdateRequest $request, AffectedPerson $ep): JsonResponse
    {
        if (! $ep->canBeEditedBy($request->user())) {
            throw new BusinessRuleException(
                'EP cannot be edited in '.$ep->status.' status.',
                'EP_NOT_EDITABLE',
            );
        }

        $data = $request->validated();
        $isApprovedCorrection = $ep->status === 'APPROVED';

        // An APPROVED record is locked by design; the only way through is the
        // admin correction workflow, which must state WHY before it writes.
        $reason = trim((string) $request->input('correction_reason', ''));
        if ($isApprovedCorrection && $reason === '') {
            throw new BusinessRuleException(
                'A correction reason is required to change an APPROVED EP.',
                'CORRECTION_REASON_REQUIRED',
            );
        }

        // Capture the field-level before/after diff before the write so the
        // correction history carries every changed column.
        $changes = $isApprovedCorrection
            ? $this->captureEpChanges($ep, $data)
            : [];

        DB::transaction(function () use ($ep, $data, $changes, $reason, $isApprovedCorrection): void {
            $ep->update($data);

            if ($isApprovedCorrection && $changes !== []) {
                $this->correctionService->recordBatch(
                    $changes,
                    $reason,
                    auth()->id(),
                    $ep->ep_id,
                    $ep->project_id,
                    false,
                    auth()->id(),
                    $ep->hh_id,
                    'EDIT',
                );
            }
        });

        // A DRAFT EP's ID carries its district/upazila/phase/category codes —
        // recompute the prefix so the ID follows any of these changes. An
        // APPROVED EP's ID is a locked code and must never be regenerated.
        $refreshed = $ep->fresh();
        if ($refreshed->status === 'DRAFT') {
            $this->epIdService->refreshEpId($refreshed);
            $refreshed = $ep->fresh();
        }

        $refreshed->load(['gender:gender_id,name', 'district:district_id,name', 'upazila:upazila_id,name',
            'landStatus:land_status_id,name', 'rcType:rc_type_id,name']);

        return ApiResponse::success(
            new AffectedPersonResource($refreshed),
            'EP updated.',
        );
    }

    /**
     * Field-level diff between the stored EP and an incoming validated payload.
     *
     * Values are normalized before comparison so a decimal stored as "100.00"
     * is not mis-reported as a change when the client sends 100.
     *
     * @return array<int, array{entity_type: string, entity_id: int, field_name: string, old_value: ?string, new_value: ?string, financial_impact: bool}>
     */
    private function captureEpChanges(AffectedPerson $ep, array $data): array
    {
        $changes = [];

        foreach ($data as $field => $newValue) {
            if ($field === 'correction_reason') {
                continue;
            }

            $oldValue = $ep->getAttribute($field);
            $oldNormalized = $this->normalizeAuditValue($oldValue);
            $newNormalized = $this->normalizeAuditValue($newValue);

            if ($oldNormalized === $newNormalized) {
                continue;
            }

            $changes[] = [
                'entity_type' => 'EP',
                'entity_id' => $ep->affected_person_id,
                'field_name' => $field,
                'old_value' => $oldNormalized,
                'new_value' => $newNormalized,
                'financial_impact' => in_array($field, self::FINANCIAL_EP_FIELDS, true),
            ];
        }

        return $changes;
    }

    /**
     * Render an arbitrary attribute value as a stable, comparable string.
     */
    private function normalizeAuditValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            // Trim trailing zeros so 100 and 100.00 compare equal.
            $trimmed = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

            return $trimmed === '' ? '0' : $trimmed;
        }

        return (string) $value;
    }

    /**
     * DELETE /api/v1/ep/{ep}  (architecture §5.4)
     *
     * Only when status is DRAFT. Removing an EP destroys all of its nested
     * records (dependencies have RESTRICT FKs, so descendants are walked in
     * order, deepest first) inside one transaction.
     *
     * When the deletion leaves the project with no remaining EPs, the EP
     * serial sequence is reset to 0 so the next EP ID's serial restarts
     * from #0001. The reset only happens when the database becomes empty.
     */
    public function destroy(AffectedPerson $ep): JsonResponse
    {
        if ($ep->status !== 'DRAFT') {
            throw new BusinessRuleException(
                'Only DRAFT EPs can be deleted.',
                'EP_NOT_EDITABLE',
            );
        }

        $epId = $ep->affected_person_id;
        $projectId = $ep->project_id;

        DB::transaction(function () use ($epId, $projectId): void {
            $this->deletionService->deleteGraph($epId);

            // If this was the last remaining EP, reset the serial to 0 so the
            // next EP ID serial restarts from #0001.
            $remaining = DB::table('affected_person')
                ->where('project_id', $projectId)
                ->count();

            if ($remaining === 0) {
                DB::table('ep_sequence')
                    ->where('project_id', $projectId)
                    ->where('sequence_key', 'EP_SERIAL')
                    ->update(['last_value' => 0]);
            }
        });

        return ApiResponse::success(null, 'EP deleted.');
    }

    // ── Status transitions ─────────────────────────────────────────

    /**
     * POST /api/v1/ep/{ep}/submit  (OP-6)
     */
    public function submit(AffectedPerson $ep): JsonResponse
    {
        $this->transition($ep, 'SUBMITTED', $ep->status);

        return ApiResponse::success(
            new AffectedPersonResource($ep->fresh()),
            'EP submitted.',
        );
    }

    /**
     * POST /api/v1/ep/{ep}/verify  (OP-6)
     */
    public function verify(AffectedPerson $ep): JsonResponse
    {
        $this->transition($ep, 'VERIFIED', $ep->status);

        return ApiResponse::success(
            new AffectedPersonResource($ep->fresh()),
            'EP verified.',
        );
    }

    /**
     * POST /api/v1/ep/{ep}/approve  (OP-6)
     */
    public function approve(AffectedPerson $ep): JsonResponse
    {
        DB::transaction(function () use ($ep): void {
            $this->transition($ep, 'APPROVED', $ep->status);
            $this->storeApprovedEcCalculation($ep->fresh());
        });

        return ApiResponse::success(
            new AffectedPersonResource($ep->fresh()->load('ec')),
            'EP approved.',
        );
    }

    /**
     * Persist the EC calculation at the EP approval boundary. Voucher
     * generation later reads these approved compensation rows; it does not
     * recalculate financial values from the document UI.
     */
    private function storeApprovedEcCalculation(AffectedPerson $ep): void
    {
        $epicad = Epicad::firstOrCreate(
            ['affected_person_id' => $ep->affected_person_id],
            [
                'issue_date' => now()->toDateString(),
                'print_status' => 'NOT_PRINTED',
            ],
        );

        $amounts = $this->ecCalculation->calculate($ep);

        $ec = Ec::firstOrCreate(
            ['affected_person_id' => $ep->affected_person_id],
            [
                'epicad_id' => $epicad->epicad_id,
                'ec_number' => $this->generateEcNumber(),
                'assessment_date' => now()->toDateString(),
                'total_assessed_loss' => 0,
                'total_compensation' => 0,
                'status' => 'APPROVED',
            ],
        );

        if ($ec->status !== 'APPROVED') {
            $ec->update(['status' => 'APPROVED']);
        }

        foreach ($amounts as $category => $amount) {
            $line = $ec->lineItems()->updateOrCreate(
                ['category' => $category],
                ['description' => 'Calculated at EP approval', 'assessed_amount' => $amount],
            );
            Compensation::updateOrCreate(
                ['ec_id' => $ec->ec_id, 'category' => $category],
                [
                    'assessed_amount' => $line->assessed_amount,
                    'approved_amount' => $amount,
                    'status' => 'APPROVED',
                    'remarks' => 'Approved with EP.',
                ],
            );
        }

        $total = array_sum($amounts);
        $ec->update([
            'total_assessed_loss' => $total,
            'total_compensation' => $total,
        ]);
    }

    private function generateEcNumber(): string
    {
        $prefix = 'EC-'.now()->format('Ymd').'-';
        $last = Ec::where('ec_number', 'like', $prefix.'%')
            ->orderByDesc('ec_number')
            ->value('ec_number');

        return $prefix.str_pad($last ? ((int) substr($last, -4) + 1) : 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * GET /api/v1/ep/{ep}/file-data
     *
     * Returns the EP with ALL relationships loaded for document generation
     * (EP File, EP Card, EC, Voucher). Read-only, no business rules.
     */
    public function fileData(AffectedPerson $ep): JsonResponse
    {
        if (! in_array($ep->status, ['VERIFIED', 'APPROVED', 'PAID'], true)) {
            throw new BusinessRuleException(
                'EP files can only be generated after the EP is verified.',
                'EP_NOT_VERIFIED',
            );
        }

        $ep->load([
            // FK lookups (names)
            'project:project_id,name,project_code',
            'gender:gender_id,name',
            'religion:religion_id,name',
            'occupation:occupation_id,name',
            'district:district_id,name',
            'upazila:upazila_id,name',
            'landStatus:land_status_id,name',
            'rcType:rc_type_id,name',
            'locationSource:location_source_id,name',

            // Child entities with their FK names
            'familyMembers.relationship:relationship_id,name',
            'familyMembers.gender:gender_id,name',
            'familyMembers.occupation:occupation_id,name',

            'lands.landType:land_type_id,name',
            'lands.landStatus:land_status_id,name',
            'lands.ownershipType:ownership_type_id,name',
            'lands.district:district_id,name',
            'lands.upazila:upazila_id,name',
            'lands.village:village_id,name',
            'lands.landUseType:land_use_type_id,name',
            'lands.landAreaUnit:unit_id,name',
            'lands.assessments.damageType:land_damage_type_id,name',
            'lands.assessments.unit:unit_id,name',
            'lands.crops',
            'lands.crops.assessment',

            'trees.treeType:tree_type_id,name',
            'trees.treeAgeCategory:tree_age_category_id,name',
            'trees.assessment',

            'infrastructures.infrastructureType:infrastructure_type_id,name',
            'infrastructures.usageType:usage_type_id,name',
            'infrastructures.assessment',

            'tenants.tenantType:tenant_type_id,name',
            'tenants.gender:gender_id,name',
            'tenants.occupation:occupation_id,name',
            'tenants.district:district_id,name',
            'tenants.upazila:upazila_id,name',
            'tenants.infrastructures:infrastructure_id',

            'businesses.loss',

            'workers.employmentType:employment_type_id,name',
            'workers.gender:gender_id,name',
            'workers.occupation:occupation_id,name',
            'workers.business:business_id,business_name,has_wage_labour',
            'workers.district:district_id,name',
            'workers.upazila:upazila_id,name',
            'workers.assessment',

            // Workflow entities
            'epicad',
            'ec',
            'ec.lineItems',
            'ec.compensations',

            // Previous payments (via EC → Voucher → Payment)
            'ec.vouchers.voucherCompensations.compensation',
            'ec.vouchers.payments.paymentMethod:payment_method_id,name',
        ]);

        if ($ep->status === 'PAID') {
            $additionalIds = static function (string $type) use ($ep): array {
                return AdditionalPaymentRecord::where('affected_person_id', $ep->affected_person_id)
                    ->where('record_type', $type)
                    ->pluck('record_id')
                    ->all();
            };

            $ep->setRelation('familyMembers', $ep->familyMembers->whereIn('family_member_id', $additionalIds('family_member'))->values());
            $landIds = $additionalIds('land');
            $ep->setRelation('lands', $ep->lands->whereIn('land_id', $landIds)->values());
            $ep->lands->each(function ($land) use ($additionalIds): void {
                $land->setRelation('crops', $land->crops->whereIn('crop_id', $additionalIds('crop'))->values());
            });
            $ep->setRelation('trees', $ep->trees->whereIn('tree_id', $additionalIds('tree'))->values());
            $ep->setRelation('infrastructures', $ep->infrastructures->whereIn('infrastructure_id', $additionalIds('infrastructure'))->values());
            $ep->setRelation('tenants', $ep->tenants->whereIn('tenant_id', $additionalIds('tenant'))->values());
            $ep->setRelation('businesses', $ep->businesses->whereIn('business_id', $additionalIds('business'))->values());
            $ep->setRelation('workers', $ep->workers->whereIn('worker_id', $additionalIds('worker'))->values());

            $additionalCompensationIds = $additionalIds('compensation');
            if ($ep->ec) {
                $ep->ec->setRelation(
                    'compensations',
                    $ep->ec->compensations->whereIn('compensation_id', $additionalCompensationIds)->values(),
                );
                $currentCategories = $ep->ec->compensations->pluck('category')->all();
                $ep->ec->setRelation(
                    'lineItems',
                    $ep->ec->lineItems->whereIn('category', $currentCategories)->values(),
                );
            }
        }

        // Merge nested relationship arrays into the resource output
        $data = (new AffectedPersonResource($ep))->resolve();
        $data['project'] = $ep->project?->name;
        $data['project_code'] = $ep->project?->project_code;
        $data['family_members'] = $ep->familyMembers;
        $data['lands'] = $ep->lands;
        $data['trees'] = $ep->trees;
        $data['infrastructures'] = $ep->infrastructures;
        $data['tenants'] = $ep->tenants;
        $data['businesses'] = $ep->businesses;
        $data['workers'] = $ep->workers;
        $data['epicad'] = $ep->epicad;
        $data['ec'] = $ep->ec;

        return ApiResponse::success($data, 'OK');
    }

    /**
     * GET /api/v1/ep/{ep}/history  (architecture §5.4 #36)
     *
     * Returns the authoritative status_history for this EP.
     */
    public function history(AffectedPerson $ep): JsonResponse
    {
        $history = $this->historyService->getHistory('EP', $ep->affected_person_id);

        return ApiResponse::success($history, 'OK');
    }

        /**
     * DELETE /api/v1/ep/draft  (bulk delete all DRAFT EPs)
     *
     * Deletes every EP currently in DRAFT status, optionally scoped to a
     * project.  Each EP graph is removed via EpDeletionService inside a
     * single transaction.  Non-draft EPs are skipped.
     */
    public function destroyDrafts(Request $request): JsonResponse
    {
        $query = AffectedPerson::where('status', 'DRAFT');
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }

        $draftEps = $query->pluck('affected_person_id')->all();
        $deletedCount = 0;

        DB::transaction(function () use ($draftEps, &$deletedCount): void {
            foreach ($draftEps as $epId) {
                $this->deletionService->deleteGraph($epId);
                $deletedCount++;
            }
        });

        return ApiResponse::success(
            ['deleted' => $deletedCount, 'remaining_drafts' => 0],
            "{$deletedCount} draft EPs deleted."
        );
    }

    // ── Status transitions ─────────────────────────────────────────

    /**
     * Perform a status transition with validation.
     */
    private function transition(AffectedPerson $ep, string $newStatus, string $currentStatus): void
    {
        $allowed = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($newStatus, $allowed)) {
            throw new BusinessRuleException(
                "Cannot transition from {$currentStatus} to {$newStatus}.",
                'INVALID_TRANSITION',
            );
        }

        $ep->update(['status' => $newStatus]);

        $this->historyService->record(
            'EP',
            $ep->affected_person_id,
            $currentStatus,
            $newStatus,
            auth()->id(),
        );
    }
}
