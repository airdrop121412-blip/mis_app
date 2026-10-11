<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Business;
use App\Models\BusinessOwnershipType;
use App\Models\Tenant;
use App\Services\Ep\AdditionalPaymentRecordService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function index(Request $request, AffectedPerson $ep): JsonResponse
    {
        $query = Business::where('affected_person_id', $ep->affected_person_id);

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->with([
            'businessOwnershipType:business_ownership_type_id,name',
            'businessType:business_type_id,name',
            'businessCategory:business_category_id,name',
        ])->orderByDesc('business_id')->paginate($perPage);

        $paginator->getCollection()->transform(function (Business $business): Business {
            return $business->setAttribute('business_ownership_type', $business->businessOwnershipType?->name)
                ->setAttribute('business_type', $business->businessType?->name)
                ->setAttribute('business_category', $business->businessCategory?->name)
                ->unsetRelation('businessOwnershipType')
                ->unsetRelation('businessType')
                ->unsetRelation('businessCategory');
        });
        $paginator->setCollection(app(AdditionalPaymentRecordService::class)
            ->decorate($paginator->getCollection(), 'business'));

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
            'business_ownership_type_id' => 'required|integer',
            'tenant_id' => 'nullable|integer',
            'business_type_id' => 'required|integer',
            'business_type_other' => 'nullable|string|max:255',
            'business_category_id' => 'required|integer',
            'business_name' => 'required|string|max:255',
            'capital_amount' => 'required|numeric|min:0',
            'present_address' => 'nullable|string|max:500',
            'annual_income' => 'nullable|numeric|min:0',
            'has_wage_labour' => 'sometimes|boolean',
            'number_of_wage_labour' => 'nullable|integer|min:0',
            'has_income_tax' => 'sometimes|boolean',
            'income_tax_id' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $this->enforceOwnershipConstraint($data, $ep);

        if (! empty($data['has_wage_labour']) && empty($data['number_of_wage_labour'])) {
            throw new BusinessRuleException(
                'Businesses with wage labour must specify the number of wage labourers.',
                'WAGE_LABOUR_COUNT_REQUIRED',
            );
        }

        $data['affected_person_id'] = $ep->affected_person_id;

        $entity = Business::create($data);
        app(AdditionalPaymentRecordService::class)->register($entity, $ep);

        return ApiResponse::created($entity, 'Business created.');
    }

    public function show(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Business::where('business_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();

        return ApiResponse::success($entity, 'OK');
    }

    public function update(Request $request, AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Business::where('business_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'business');

        $data = $request->validate([
            'business_ownership_type_id' => 'required|integer',
            'tenant_id' => 'nullable|integer',
            'business_type_id' => 'required|integer',
            'business_type_other' => 'nullable|string|max:255',
            'business_category_id' => 'required|integer',
            'business_name' => 'required|string|max:255',
            'capital_amount' => 'required|numeric|min:0',
            'present_address' => 'nullable|string|max:500',
            'annual_income' => 'nullable|numeric|min:0',
            'has_wage_labour' => 'sometimes|boolean',
            'number_of_wage_labour' => 'nullable|integer|min:0',
            'has_income_tax' => 'sometimes|boolean',
            'income_tax_id' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $this->enforceOwnershipConstraint($data, $ep);

        if (! empty($data['has_wage_labour']) && empty($data['number_of_wage_labour'])) {
            throw new BusinessRuleException(
                'Businesses with wage labour must specify the number of wage labourers.',
                'WAGE_LABOUR_COUNT_REQUIRED',
            );
        }

        $entity->update($data);

        return ApiResponse::success($entity->fresh(), 'Business updated.');
    }

    public function destroy(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Business::where('business_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'business');

        $entity->delete();

        return ApiResponse::success(null, 'Business deleted.');
    }

    /**
     * Derive the ownership code from the selected Ownership Type and enforce
     * the CHECK constraint:
     *   (ownership_code='OWNER' AND tenant_id IS NULL)
     *   OR (ownership_code='TENANT' AND tenant_id IS NOT NULL).
     *
     * The "Tenant" ownership type ties the business to one of the EP's
     * tenants; anything else is treated as owner-operated.
     */
    private function enforceOwnershipConstraint(array &$data, AffectedPerson $ep): void
    {
        $tenantTypeId = BusinessOwnershipType::where('name', 'Tenant')
            ->value('business_ownership_type_id');

        if ((int) ($data['business_ownership_type_id'] ?? 0) === (int) $tenantTypeId) {
            if (empty($data['tenant_id'])) {
                throw new BusinessRuleException(
                    'Tenant businesses must reference a tenant.',
                    'BUSINESS_TENANT_REQUIRED',
                );
            }

            $tenantExists = Tenant::where('tenant_id', $data['tenant_id'])
                ->where('affected_person_id', $ep->affected_person_id)
                ->exists();

            if (! $tenantExists) {
                throw new BusinessRuleException(
                    'Tenant not found for this affected person.',
                    'TENANT_NOT_FOUND',
                    404,
                );
            }

            $data['ownership_code'] = 'TENANT';
        } else {
            // Owner-operated — force tenant_id to null regardless of input.
            $data['tenant_id'] = null;
            $data['ownership_code'] = 'OWNER';
        }
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
                'EP must be in DRAFT status to modify business records.',
                'EP_NOT_EDITABLE',
            );
        }
    }
}
