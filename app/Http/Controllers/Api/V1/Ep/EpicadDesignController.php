<?php

namespace App\Http\Controllers\Api\V1\Ep;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ep\EpicadDesignRequest;
use App\Http\Resources\Api\V1\Ep\EpicadDesignResource;
use App\Models\EpicadDesign;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EPICAD design / template management (§ EPICAD tab).
 *
 * CRUD for reusable card designs plus file upload of an HTML template or a
 * Word .doc file (extracted to HTML by the client).
 */
class EpicadDesignController extends Controller
{
    /**
     * GET /api/v1/epicad-designs  (optionally ?project_id=)
     */
    public function index(Request $request): JsonResponse
    {
        $query = EpicadDesign::query();

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->input('project_id'));
        }

        $items = $query->orderByDesc('is_active')->orderByDesc('epicad_design_id')->get();

        return ApiResponse::success(EpicadDesignResource::collection($items), 'OK');
    }

    /**
     * POST /api/v1/epicad-designs
     */
    public function store(EpicadDesignRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $validated['created_by'] = $request->user()?->getAuthIdentifier();

        /** @var bool $active */
        $active = (bool) ($validated['is_active'] ?? false);

        $design = DB::transaction(function () use ($validated, $active) {
            $design = EpicadDesign::create($validated);
            $this->applyActive($design, $active);

            return $design;
        });

        return ApiResponse::created(
            new EpicadDesignResource($design),
            'EPICAD design created.',
        );
    }

    /**
     * GET /api/v1/epicad-designs/{epicadDesign}
     */
    public function show(EpicadDesign $epicadDesign): JsonResponse
    {
        return ApiResponse::success(new EpicadDesignResource($epicadDesign), 'OK');
    }

    /**
     * PUT /api/v1/epicad-designs/{epicadDesign}
     */
    public function update(EpicadDesignRequest $request, EpicadDesign $epicadDesign): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $validated['updated_by'] = $request->user()?->getAuthIdentifier();
        /** @var bool $active */
        $active = (bool) ($validated['is_active'] ?? (bool) $epicadDesign->is_active);

        DB::transaction(function () use ($epicadDesign, $validated, $active) {
            $epicadDesign->update($validated);
            $this->applyActive($epicadDesign, $active);
        });

        return ApiResponse::success(
            new EpicadDesignResource($epicadDesign->fresh()),
            'EPICAD design updated.',
        );
    }

    /**
     * DELETE /api/v1/epicad-designs/{epicadDesign}
     */
    public function destroy(EpicadDesign $epicadDesign): JsonResponse
    {
        $epicadDesign->delete();

        return ApiResponse::success(null, 'EPICAD design deleted.');
    }

    /**
     * POST /api/v1/epicad-designs/{epicadDesign}/upload
     *
     * Accepts an HTML (.html) template or a Word (.doc/.docx) document.
     * .doc/.docx are extracted to HTML client-side before storage, so this
     * endpoint accepts the extracted HTML for .doc files. A `.doc` binary
     * upload is stored raw under the `docx_orig_filename` (kept for download).
     */
    public function upload(Request $request, EpicadDesign $epicadDesign): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->file('design');

        if (! $file) {
            return ApiResponse::error('No design file provided.', 'NO_FILE', 422);
        }

        $ext = strtolower($file->getClientOriginalExtension());

        if (in_array($ext, ['html', 'htm'], true)) {
            $html = (string) $file->getContent();
            $epicadDesign->update([
                'html_template' => $html,
                'docx_orig_filename' => null,
            ]);
        } elseif (in_array($ext, ['doc', 'docx'], true)) {
            // The client uploads the converted HTML, but we keep the original
            // filename reference. If the client sent raw .doc bytes (not
            // converted), store it as the original for manual download.
            $title = 'epicad-'.Str::slug($epicadDesign->name ?: 'design');
            $path = $file->storeAs('epicad-designs', $title.'.'.$ext, 'public');
            $epicadDesign->update([
                'docx_orig_filename' => $path,
            ]);

            // Preserve any already-converted HTML if present.
        } else {
            return ApiResponse::error('Unsupported file type. Use .html, .doc, or .docx.', 'UNSUPPORTED_TYPE', 422);
        }

        return ApiResponse::success(
            new EpicadDesignResource($epicadDesign->fresh()),
            'Design file uploaded.',
        );
    }

    /**
     * Enforce a single active design per project.
     */
    private function applyActive(EpicadDesign $design, bool $active): void
    {
        if (! $active) {
            return;
        }

        EpicadDesign::query()
            ->where('project_id', $design->project_id)
            ->where('epicad_design_id', '!=', $design->epicad_design_id)
            ->update(['is_active' => false]);

        $design->update(['is_active' => true]);
    }
}
