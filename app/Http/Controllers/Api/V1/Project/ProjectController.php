<?php

namespace App\Http\Controllers\Api\V1\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\ProjectStoreRequest;
use App\Http\Requests\Api\V1\Project\ProjectUpdateRequest;
use App\Http\Resources\Api\V1\Project\ProjectResource;
use App\Models\Project;
use App\Services\EpId\EpIdService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Project CRUD (architecture §5.3).
 *
 * Routes: /api/v1/projects
 */
class ProjectController extends Controller
{
    public function __construct(
        private readonly EpIdService $epIdService,
    ) {}

    /**
     * GET /api/v1/projects
     *
     * Paginated list, filterable by ?is_active=1 and ?q= (search name/code).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Project::query();

        if ($request->filled('is_active')) {
            $query->where('is_active', (int) $request->boolean('is_active'));
        }

        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('project_code', 'like', "%{$search}%");
            });
        }

        $query->orderBy('name');

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->paginate($perPage);

        return ApiResponse::success(
            ProjectResource::collection($paginator),
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
     * GET /api/v1/projects/{project}
     */
    public function show(Project $project): JsonResponse
    {
        return ApiResponse::success(new ProjectResource($project), 'OK');
    }

    /**
     * POST /api/v1/projects
     */
    public function store(ProjectStoreRequest $request): JsonResponse
    {
        $project = Project::create($request->validated());

        return ApiResponse::created(
            new ProjectResource($project),
            'Project created.',
        )->header('Location', route('projects.show', $project->project_id));
    }

    /**
     * PUT /api/v1/projects/{project}
     *
     * An EP ID's phase segment comes from project.phase, so when that value
     * changes the DRAFT EPs of the project are re-stamped automatically to
     * reflect the new phase code.
     */
    public function update(ProjectUpdateRequest $request, Project $project): JsonResponse
    {
        $previousPhase = $project->phase;

        $project->update($request->validated());

        if ($project->fresh()->phase !== $previousPhase) {
            DB::transaction(fn () => $this->epIdService->refreshDraftEpIds($project->project_id));
        }

        return ApiResponse::success(
            new ProjectResource($project->fresh()),
            'Project updated.',
        );
    }

    /**
     * DELETE /api/v1/projects/{project}
     *
     * Projects containing EP data are protected from accidental destructive
     * deletion. Remove the project only after its EP records are handled.
     */
    public function destroy(Project $project): JsonResponse
    {
        if ($project->affectedPersons()->exists()) {
            return ApiResponse::error(
                'This project cannot be deleted because it contains EP records.',
                'PROJECT_NOT_EMPTY',
                409,
            );
        }

        DB::transaction(function () use ($project): void {
            $project->epSequences()->delete();
            $project->delete();
        });

        return ApiResponse::success(null, 'Project deleted.');
    }
}
