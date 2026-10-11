<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Worker;
use App\Services\Ep\AdditionalPaymentRecordService;
use App\Services\EpId\EpIdService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerController extends Controller
{
    public function __construct(
        private readonly EpIdService $epIdService,
    ) {}

    public function index(Request $request, AffectedPerson $ep): JsonResponse
    {
        $query = Worker::where('affected_person_id', $ep->affected_person_id);

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->with([
            'business:business_id,business_name',
            'gender:gender_id,name',
            'occupation:occupation_id,name',
            'employmentType:employment_type_id,name',
            'district:district_id,name',
            'upazila:upazila_id,name',
        ])->orderByDesc('worker_id')->paginate($perPage);

        $paginator->getCollection()->transform(function (Worker $worker): Worker {
            return $worker->setAttribute('business_name', $worker->business?->business_name)
                ->setAttribute('gender', $worker->gender?->name)
                ->setAttribute('occupation', $worker->occupation?->name)
                ->setAttribute('employment_type', $worker->employmentType?->name)
                ->setAttribute('district_name', $worker->district?->name)
                ->setAttribute('upazila_name', $worker->upazila?->name)
                ->unsetRelation('business')
                ->unsetRelation('gender')
                ->unsetRelation('occupation')
                ->unsetRelation('employmentType')
                ->unsetRelation('district')
                ->unsetRelation('upazila');
        });
        $paginator->setCollection(app(AdditionalPaymentRecordService::class)
            ->decorate($paginator->getCollection(), 'worker'));

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

    public function store(Request $request, AffectedPerson $ep): JsonResponse
    {
        $this->assertCreate($ep);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'father_name' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:20',
            'nid_number' => 'nullable|string|max:30',
            'mouza' => 'nullable|string|max:255',
            'union_name' => 'nullable|string|max:255',
            'district_id' => 'nullable|integer',
            'upazila_id' => 'nullable|integer',
            'total_family_member' => 'nullable|integer|min:0|max:200',
            'duration_of_employment_month' => 'nullable|integer|min:0|max:1200',
            'gender_id' => 'required|integer',
            'gender_other' => 'nullable|string|max:255',
            'age' => 'nullable|integer|min:0',
            'occupation_id' => 'nullable|integer',
            'occupation_other' => 'nullable|string|max:255',
            'employment_type_id' => 'nullable|integer',
            'employment_type_other' => 'nullable|string|max:255',
            'business_id' => 'nullable|integer',
            'monthly_income' => 'required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $data['affected_person_id'] = $ep->affected_person_id;

        // A wage labour's own district/upazila drive its EP ID prefix; fall
        // back to the parent EP's when the worker does not specify one.
        $districtId = $data['district_id'] ?? $ep->district_id;
        $upazilaId = $data['upazila_id'] ?? $ep->upazila_id;

        $entity = Worker::create([
            ...$data,
            'ep_id' => $this->epIdService->generateIndirect(
                $ep->project_id,
                $districtId,
                $upazilaId,
                $ep->land_status_id,
                'Wage Loss',
                'EP_SERIAL_WORKER',
                $ep->ownership,
                $ep->phase,
            ),
        ]);
        app(AdditionalPaymentRecordService::class)->register($entity, $ep);

        return ApiResponse::created($entity, 'Worker created.');
    }

    public function show(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Worker::where('worker_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->with([
                'business:business_id,business_name',
                'district:district_id,name',
                'upazila:upazila_id,name',
            ])
            ->firstOrFail();

        $entity->setAttribute('business_name', $entity->business?->business_name)
            ->setAttribute('district_name', $entity->district?->name)
            ->setAttribute('upazila_name', $entity->upazila?->name)
            ->unsetRelation('business')
            ->unsetRelation('district')
            ->unsetRelation('upazila');

        return ApiResponse::success($entity, 'OK');
    }

    public function update(Request $request, AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Worker::where('worker_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'worker');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'father_name' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:20',
            'nid_number' => 'nullable|string|max:30',
            'mouza' => 'nullable|string|max:255',
            'union_name' => 'nullable|string|max:255',
            'district_id' => 'nullable|integer',
            'upazila_id' => 'nullable|integer',
            'total_family_member' => 'nullable|integer|min:0|max:200',
            'duration_of_employment_month' => 'nullable|integer|min:0|max:1200',
            'gender_id' => 'required|integer',
            'gender_other' => 'nullable|string|max:255',
            'age' => 'nullable|integer|min:0',
            'occupation_id' => 'nullable|integer',
            'occupation_other' => 'nullable|string|max:255',
            'employment_type_id' => 'nullable|integer',
            'employment_type_other' => 'nullable|string|max:255',
            'business_id' => 'nullable|integer',
            'monthly_income' => 'required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $entity->update($data);

        return ApiResponse::success($entity->fresh(), 'Worker updated.');
    }

    public function destroy(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Worker::where('worker_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'worker');

        $entity->delete();

        return ApiResponse::success(null, 'Worker deleted.');
    }

    private function assertCreate(AffectedPerson $ep): void
    {
        if ($ep->status === 'PAID') {
            return;
        }

        $this->assertDraft($ep);
    }

    private function assertDraft(AffectedPerson $ep): void
    {
        if (! $ep->canBeEditedBy(request()->user())) {
            throw new BusinessRuleException(
                'EP must be in DRAFT status to modify worker records.',
                'EP_NOT_EDITABLE',
            );
        }
    }
}
