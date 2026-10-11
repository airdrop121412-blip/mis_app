<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Tree;
use App\Services\Ep\AdditionalPaymentRecordService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TreeController extends Controller
{
    public function index(Request $request, AffectedPerson $ep): JsonResponse
    {
        $query = Tree::where('affected_person_id', $ep->affected_person_id);

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->with([
            'treeType:tree_type_id,name',
            'treeAgeCategory:tree_age_category_id,name',
        ])->orderByDesc('tree_id')->paginate($perPage);

        $paginator->getCollection()->transform(function (Tree $tree): Tree {
            return $tree->setAttribute('tree_type', $tree->treeType?->name)
                ->setAttribute('tree_age_category', $tree->treeAgeCategory?->name)
                ->unsetRelation('treeType')
                ->unsetRelation('treeAgeCategory');
        });
        $paginator->setCollection(app(AdditionalPaymentRecordService::class)
            ->decorate($paginator->getCollection(), 'tree'));

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
            'name' => 'nullable|string|max:255',
            'land_ownership' => 'nullable|string|max:100',
            'tree_type_id' => 'required|integer',
            'tree_type_other' => 'nullable|string|max:255',
            'tree_age_category_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $data['affected_person_id'] = $ep->affected_person_id;

        $entity = Tree::create($data);
        app(AdditionalPaymentRecordService::class)->register($entity, $ep);

        return ApiResponse::created($entity, 'Tree created.');
    }

    public function show(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Tree::where('tree_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();

        return ApiResponse::success($entity, 'OK');
    }

    public function update(Request $request, AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Tree::where('tree_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'tree');

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'land_ownership' => 'nullable|string|max:100',
            'tree_type_id' => 'required|integer',
            'tree_type_other' => 'nullable|string|max:255',
            'tree_age_category_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $entity->update($data);

        return ApiResponse::success($entity->fresh(), 'Tree updated.');
    }

    public function destroy(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Tree::where('tree_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'tree');

        $entity->delete();

        return ApiResponse::success(null, 'Tree deleted.');
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
                'EP must be in DRAFT status to modify tree records.',
                'EP_NOT_EDITABLE',
            );
        }
    }
}
