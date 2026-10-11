<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\FamilyMember;
use App\Services\Ep\AdditionalPaymentRecordService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FamilyMemberController extends Controller
{
    public function index(Request $request, AffectedPerson $ep): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 25), 100);

        $paginator = FamilyMember::with([
            'relationship:relationship_id,name',
            'gender:gender_id,name',
            'occupation:occupation_id,name',
        ])
            ->where('affected_person_id', $ep->affected_person_id)
            ->orderByDesc('family_member_id')
            ->paginate($perPage);

        $paginator->getCollection()->transform(function (FamilyMember $member): FamilyMember {
            return $member->setAttribute('relationship', $member->relationship?->name)
                ->setAttribute('gender', $member->gender?->name)
                ->setAttribute('occupation', $member->occupation?->name)
                ->unsetRelation('relationship')
                ->unsetRelation('gender')
                ->unsetRelation('occupation');
        });
        $paginator->setCollection(app(AdditionalPaymentRecordService::class)
            ->decorate($paginator->getCollection(), 'family_member'));

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
            'name' => 'nullable|string|max:150',
            'relationship_id' => 'required|integer',
            'relationship_other' => 'nullable|string|max:255',
            'gender_id' => 'required|integer',
            'gender_other' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:50',
            'age' => 'nullable|integer|min:0',
            'education' => 'nullable|string|max:100',
            'education_other' => 'nullable|string|max:255',
            'occupation_id' => 'nullable|integer',
            'occupation_other' => 'nullable|string|max:255',
            'does_work' => 'nullable|boolean',
            'annual_income' => 'nullable|numeric|min:0',
            'health_status' => 'nullable|string|max:150',
            'disability_type' => 'nullable|string|max:100',
            'is_dependent' => 'sometimes|boolean',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $data['affected_person_id'] = $ep->affected_person_id;

        $entity = FamilyMember::create($data);
        app(AdditionalPaymentRecordService::class)->register($entity, $ep);

        return ApiResponse::created($entity, 'Family member created.');
    }

    public function show(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = FamilyMember::where('family_member_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();

        return ApiResponse::success($entity, 'OK');
    }

    public function update(Request $request, AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = FamilyMember::where('family_member_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'family member');

        $data = $request->validate([
            'name' => 'nullable|string|max:150',
            'relationship_id' => 'required|integer',
            'relationship_other' => 'nullable|string|max:255',
            'gender_id' => 'required|integer',
            'gender_other' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:50',
            'age' => 'nullable|integer|min:0',
            'education' => 'nullable|string|max:100',
            'education_other' => 'nullable|string|max:255',
            'occupation_id' => 'nullable|integer',
            'occupation_other' => 'nullable|string|max:255',
            'does_work' => 'nullable|boolean',
            'annual_income' => 'nullable|numeric|min:0',
            'health_status' => 'nullable|string|max:150',
            'disability_type' => 'nullable|string|max:100',
            'is_dependent' => 'sometimes|boolean',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $entity->update($data);

        return ApiResponse::success($entity->fresh(), 'Family member updated.');
    }

    public function destroy(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = FamilyMember::where('family_member_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'family member');

        $entity->delete();

        return ApiResponse::success(null, 'Family member deleted.');
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
                'EP must be in DRAFT status to modify family members.',
                'EP_NOT_EDITABLE',
            );
        }
    }
}
