<?php

namespace App\Http\Controllers\Api\V1\Map;

use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GPS / Map query (architecture §17, §5.11 #74–75).
 *
 * Endpoints:
 *   GET /api/v1/map/surveys  — bounding-box point query (map.view)
 *   GET /api/v1/map/clusters — grid-cluster aggregation (map.view)
 *
 * Phase 7 will add a generated POINT column + SPATIAL INDEX for true
 * geographic queries; the API contract stays the same.
 */
class MapController extends Controller
{
    private const GRID_SIZE = 0.01; // ~1 km grid cells at equator

    /**
     * GET /api/v1/map/surveys?bbox=minLng,minLat,maxLng,maxLat&project_id=&status=&vulnerable=
     *
     * Returns affected persons with GPS coordinates within the bounding box.
     */
    public function surveys(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bbox' => 'required|string',
            'project_id' => 'nullable|integer|exists:project,project_id',
            'status' => 'nullable|string|in:DRAFT,SUBMITTED,VERIFIED,APPROVED',
            'vulnerable' => 'nullable|boolean',
        ]);

        // Parse bbox: minLng,minLat,maxLng,maxLat
        $coords = array_map('floatval', explode(',', $validated['bbox']));
        if (count($coords) !== 4) {
            return ApiResponse::error(
                'bbox must have exactly 4 comma-separated values: minLng,minLat,maxLng,maxLat',
                'INVALID_BBOX',
                422,
            );
        }

        [$minLng, $minLat, $maxLng, $maxLat] = $coords;

        $query = AffectedPerson::query()
            ->select([
                'affected_person.affected_person_id',
                'affected_person.ep_id',
                'affected_person.hh_id',
                'affected_person.name',
                'affected_person.father_name',
                'affected_person.nid_number',
                'affected_person.mobile',
                'affected_person.status',
                'affected_person.district_id',
                'affected_person.upazila_id',
                'affected_person.gps_latitude',
                'affected_person.gps_longitude',
                'affected_person.project_id',
                'district.name as district',
                'upazila.name as upazila',
                'location_source.name as location_source',
            ])
            ->leftJoin('district', 'district.district_id', '=', 'affected_person.district_id')
            ->leftJoin('upazila', 'upazila.upazila_id', '=', 'affected_person.upazila_id')
            ->leftJoin('location_source', 'location_source.location_source_id', '=', 'affected_person.location_source_id')
            ->whereNotNull('gps_latitude')
            ->whereNotNull('gps_longitude')
            ->whereBetween('gps_latitude', [$minLat, $maxLat])
            ->whereBetween('gps_longitude', [$minLng, $maxLng]);

        if ($request->has('project_id')) {
            $query->where('project_id', $validated['project_id']);
        }

        if ($request->has('status')) {
            $query->where('status', $validated['status']);
        }

        // Vulnerable filter: join with allowance where exists
        if ($request->boolean('vulnerable')) {
            $query->whereIn('affected_person_id', function ($sub) {
                $sub->select('affected_person_id')
                    ->from('allowance')
                    ->where('status', 'APPROVED');
            });
        }

        $points = $query->orderBy('affected_person_id')->get();

        return ApiResponse::success($points, 'OK');
    }

    /**
     * GET /api/v1/map/clusters?bbox=...&zoom=&project_id=&status=
     *
     * Returns grid-cell cluster summaries for the visible area.
     * Each cluster: { lat, lng, count }.
     */
    public function clusters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bbox' => 'required|string',
            'zoom' => 'nullable|integer|min:1|max:20',
            'project_id' => 'nullable|integer|exists:project,project_id',
            'status' => 'nullable|string|in:DRAFT,SUBMITTED,VERIFIED,APPROVED',
        ]);

        // Parse bbox
        $coords = array_map('floatval', explode(',', $validated['bbox']));
        if (count($coords) !== 4) {
            return ApiResponse::error(
                'bbox must have exactly 4 comma-separated values: minLng,minLat,maxLng,maxLat',
                'INVALID_BBOX',
                422,
            );
        }

        [$minLng, $minLat, $maxLng, $maxLat] = $coords;

        // Adjust grid size based on zoom level
        $gridSize = self::GRID_SIZE / max(1, (int) ($validated['zoom'] ?? 10) - 8);

        // Build query for clustering
        $query = AffectedPerson::query()
            ->selectRaw('
                ROUND(gps_latitude / ?, 0) * ? AS cluster_lat,
                ROUND(gps_longitude / ?, 0) * ? AS cluster_lng,
                COUNT(*) AS count
            ', [$gridSize, $gridSize, $gridSize, $gridSize])
            ->whereNotNull('gps_latitude')
            ->whereNotNull('gps_longitude')
            ->whereBetween('gps_latitude', [$minLat, $maxLat])
            ->whereBetween('gps_longitude', [$minLng, $maxLng])
            ->groupBy('cluster_lat', 'cluster_lng');

        if ($request->has('project_id')) {
            $query->where('project_id', $validated['project_id']);
        }

        if ($request->has('status')) {
            $query->where('status', $validated['status']);
        }

        $clusters = $query->get();

        return ApiResponse::success($clusters, 'OK');
    }
}
