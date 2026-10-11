<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Ec;
use App\Models\EcLineItem;
use App\Support\ApiResponse;
use App\Support\CompensationCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EC Line Item controller — rollup of assessment categories into an EC.
 * Category: the 18 official reference categories (see CompensationCategories).
 * One line item per category per EC (UNIQUE constraint).
 */
class EcLineItemController extends Controller
{
    private const CATEGORIES = CompensationCategories::ACCEPTED;

    /**
     * GET /api/v1/ep/{ep}/ec/line-item
     */
    public function index(Request $request, AffectedPerson $ep): JsonResponse
    {
        $ec = $this->getEc($ep);

        $items = EcLineItem::where('ec_id', $ec->ec_id)
            ->orderBy('category')
            ->get();

        return ApiResponse::success($items, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/ec/line-item
     */
    public function store(Request $request, AffectedPerson $ep): JsonResponse
    {
        $ec = $this->getEc($ep);
        $this->assertEditable($ec);

        $data = $request->validate([
            'category' => 'required|string|in:'.implode(',', self::CATEGORIES),
            'description' => 'nullable|string|max:255',
            'assessed_amount' => 'required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        // One line item per category per EC
        $existing = EcLineItem::where('ec_id', $ec->ec_id)
            ->where('category', $data['category'])
            ->first();

        if ($existing) {
            throw new BusinessRuleException(
                "Line item for category {$data['category']} already exists. Use update.",
                'LINE_ITEM_EXISTS',
            );
        }

        $item = EcLineItem::create($data + ['ec_id' => $ec->ec_id]);

        return ApiResponse::created($item, 'Line item created.');
    }

    /**
     * PUT /api/v1/ep/{ep}/ec/line-item/{line_item}
     */
    public function update(Request $request, AffectedPerson $ep, EcLineItem $lineItem): JsonResponse
    {
        $ec = $this->getEc($ep);
        $this->assertEditable($ec);
        $this->assertLineItemBelongsToEc($ec, $lineItem);

        $data = $request->validate([
            'description' => 'nullable|string|max:255',
            'assessed_amount' => 'required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $lineItem->update($data);

        return ApiResponse::success($lineItem->fresh(), 'Line item updated.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/ec/line-item/{line_item}
     */
    public function destroy(AffectedPerson $ep, EcLineItem $lineItem): JsonResponse
    {
        $ec = $this->getEc($ep);
        $this->assertEditable($ec);
        $this->assertLineItemBelongsToEc($ec, $lineItem);

        $lineItem->delete();

        return ApiResponse::success(null, 'Line item deleted.');
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function getEc(AffectedPerson $ep): Ec
    {
        return Ec::where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
    }

    private function assertEditable(Ec $ec): void
    {
        if ($ec->status !== 'DRAFT') {
            throw new BusinessRuleException(
                'Line items can only be modified when EC is in DRAFT status.',
                'EC_NOT_EDITABLE',
            );
        }
    }

    private function assertLineItemBelongsToEc(Ec $ec, EcLineItem $lineItem): void
    {
        if ($lineItem->ec_id !== $ec->ec_id) {
            throw new BusinessRuleException(
                'Line item does not belong to this EC.',
                'LINE_ITEM_NOT_FOUND',
                404,
            );
        }
    }
}
