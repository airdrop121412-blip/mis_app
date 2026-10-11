<?php

namespace App\Services\Validation;

use App\Models\AffectedPerson;
use App\Models\Ec;
use App\Models\Epicad;
use App\Models\Voucher;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Centralized validation engine for MIS entities.
 *
 * All business rules live here — exposed via API for the React frontend.
 * The frontend MUST NOT duplicate these rules; it calls the API and displays
 * the machine-readable errors returned.
 */
class ValidationService
{
    /**
     * Validate a single EP and return all issues.
     *
     * @return array{ep_id: int, ep_id_code: ?string, name: ?string, issues: list<array{field: string, message: string, severity: string}>}
     */
    public function validateEp(int $affectedPersonId): array
    {
        $ep = AffectedPerson::findOrFail($affectedPersonId);
        $issues = [];

        // ── Required field checks ─────────────────────────────
        if (empty($ep->name)) {
            $issues[] = ['field' => 'name', 'message' => 'Name is required.', 'severity' => 'error'];
        }
        if (empty($ep->district_id)) {
            $issues[] = ['field' => 'district_id', 'message' => 'District is required.', 'severity' => 'error'];
        }
        if (empty($ep->upazila_id)) {
            $issues[] = ['field' => 'upazila_id', 'message' => 'Upazila is required.', 'severity' => 'error'];
        }
        if (empty($ep->gender_id)) {
            $issues[] = ['field' => 'gender_id', 'message' => 'Gender is required.', 'severity' => 'error'];
        }
        if (empty($ep->land_status_id)) {
            $issues[] = ['field' => 'land_status_id', 'message' => 'Land status is required.', 'severity' => 'error'];
        }
        if (empty($ep->rc_type_id)) {
            $issues[] = ['field' => 'rc_type_id', 'message' => 'RC type is required.', 'severity' => 'error'];
        }
        if (empty($ep->project_id)) {
            $issues[] = ['field' => 'project_id', 'message' => 'Project is required.', 'severity' => 'error'];
        }

        // ── Format validation ─────────────────────────────────
                // ── NID validation (blank / format / duplicate) ──────────
        if (empty($ep->nid_number) || trim((string) $ep->nid_number) === '') {
            $issues[] = ['field' => 'nid_number', 'message' => 'NID number is blank.', 'severity' => 'error'];
        } else {
            $nid = trim((string) $ep->nid_number);
            if (strlen($nid) !== 10 && strlen($nid) !== 17) {
                $issues[] = ['field' => 'nid_number', 'message' => 'NID number should be 10 or 17 digits.', 'severity' => 'warning'];
            }
            $dupNid = AffectedPerson::where('project_id', $ep->project_id)
                ->where('affected_person_id', '!=', $ep->affected_person_id)
                ->whereNotNull('nid_number')
                ->whereRaw('TRIM(nid_number) != ""')
                ->whereRaw('TRIM(nid_number) = TRIM(?)', [$ep->nid_number])
                ->exists();
            if ($dupNid) {
                $issues[] = ['field' => 'nid_number', 'message' => 'NID number is duplicated in this project.', 'severity' => 'error'];
            }
        }
                // ── Mobile validation (blank / format / duplicate) ─────────
        if (empty($ep->mobile) || trim((string) $ep->mobile) === '') {
            $issues[] = ['field' => 'mobile', 'message' => 'Mobile number is blank.', 'severity' => 'error'];
        } else {
            if (! preg_match('/^01[3-9]\\d{8}$/', (string) $ep->mobile)) {
                $issues[] = ['field' => 'mobile', 'message' => 'Mobile number format is invalid (expected 01XXXXXXXXX).', 'severity' => 'warning'];
            }
            $dupMobile = AffectedPerson::where('project_id', $ep->project_id)
                ->where('affected_person_id', '!=', $ep->affected_person_id)
                ->whereNotNull('mobile')
                ->whereRaw('TRIM(mobile) != ""')
                ->where('mobile', $ep->mobile)
                ->exists();
            if ($dupMobile) {
                $issues[] = ['field' => 'mobile', 'message' => 'Mobile number is duplicated in this project.', 'severity' => 'error'];
            }
        }

        // ── Date validation ───────────────────────────────────
        if ($ep->date_of_birth !== null && $ep->date_of_birth->isFuture()) {
            $issues[] = ['field' => 'date_of_birth', 'message' => 'Date of birth cannot be in the future.', 'severity' => 'error'];
        }

        // ── GPS validation ────────────────────────────────────
        if ($ep->gps_latitude !== null && ($ep->gps_latitude < 20 || $ep->gps_latitude > 27)) {
            $issues[] = ['field' => 'gps_latitude', 'message' => 'GPS latitude outside Bangladesh range (20-27°).', 'severity' => 'warning'];
        }
        if ($ep->gps_longitude !== null && ($ep->gps_longitude < 88 || $ep->gps_longitude > 93)) {
            $issues[] = ['field' => 'gps_longitude', 'message' => 'GPS longitude outside Bangladesh range (88-93°).', 'severity' => 'warning'];
        }

        // ── Relational integrity ──────────────────────────────
        if ($ep->religion_id !== null) {
            $exists = DB::table('religion')->where('religion_id', $ep->religion_id)->exists();
            if (! $exists) {
                $issues[] = ['field' => 'religion_id', 'message' => 'Referenced religion does not exist.', 'severity' => 'error'];
            }
        }

        return [
            'ep_id' => $ep->affected_person_id,
            'ep_id_code' => $ep->ep_id,
            'name' => $ep->name,
            'issues' => $issues,
        ];
    }

    /**
     * Validate all EPs in a project.
     *
     * @return list<array{ep_id: int, ep_id_code: ?string, name: ?string, issues: list<array>}>
     */
    public function validateProjectEps(int $projectId): array
    {
        $eps = AffectedPerson::where('project_id', $projectId)
            ->pluck('affected_person_id')
            ->toArray();

        $results = [];
        foreach ($eps as $epId) {
            $result = $this->validateEp($epId);
            if (count($result['issues']) > 0) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Validate an EP for EPICAD generation readiness.
     *
     * EPICAD requires: EP must have at least one land or tree or infrastructure record.
     */
    public function validateEpicadReadiness(int $affectedPersonId): array
    {
        $ep = AffectedPerson::findOrFail($affectedPersonId);
        $issues = [];

        $hasLand = DB::table('land')->where('affected_person_id', $affectedPersonId)->exists();
        $hasTree = DB::table('tree')->where('affected_person_id', $affectedPersonId)->exists();
        $hasInfra = DB::table('infrastructure')->where('affected_person_id', $affectedPersonId)->exists();

        if (! $hasLand && ! $hasTree && ! $hasInfra) {
            $issues[] = [
                'field' => 'entity',
                'message' => 'EP must have at least one land, tree, or infrastructure record to generate EPICAD.',
                'severity' => 'error',
            ];
        }

        return [
            'ep_id' => $ep->affected_person_id,
            'ep_id_code' => $ep->ep_id,
            'readable' => $hasLand || $hasTree || $hasInfra,
            'issues' => $issues,
        ];
    }

    /**
     * Validate an EP for EC generation readiness.
     *
     * EC creation handles the internal EPICAD reference automatically.
     */
    public function validateEcReadiness(int $affectedPersonId): array
    {
        $ep = AffectedPerson::findOrFail($affectedPersonId);
        $issues = [];

        return [
            'ep_id' => $ep->affected_person_id,
            'ep_id_code' => $ep->ep_id,
            'readable' => true,
            'issues' => $issues,
        ];
    }

    /**
     * Validate an EP for compensation/voucher generation readiness.
     *
     * Voucher requires: the EP must be APPROVED and have a stored
     * compensation calculation. EC approval is not a separate gate.
     */
    public function validateVoucherReadiness(int $affectedPersonId): array
    {
        $ep = AffectedPerson::findOrFail($affectedPersonId);
        $issues = [];

        $ec = Ec::where('affected_person_id', $affectedPersonId)->first();

        if ($ep->status !== 'APPROVED') {
            $issues[] = [
                'field' => 'ep.status',
                'message' => "EP must be APPROVED before generating voucher (current: {$ep->status}).",
                'severity' => 'error',
            ];
        } elseif (! $ec) {
            $issues[] = [
                'field' => 'ec',
                'message' => 'EC calculation has not been stored for this approved EP.',
                'severity' => 'error',
            ];
        }

        return [
            'ep_id' => $ep->affected_person_id,
            'ep_id_code' => $ep->ep_id,
            'readable' => $ep->status === 'APPROVED' && $ec !== null,
            'issues' => $issues,
        ];
    }

    /**
     * Get aggregate validation summary for a project.
     */
    public function getProjectSummary(?int $projectId = null): array
    {
        $totalEps = AffectedPerson::when($projectId, fn($q) => $q->where('project_id', $projectId))->count();
        $invalidEps = $this->countInvalidEps($projectId);

        $withEpicad = DB::table('epicad')
            ->join('affected_person', 'epicad.affected_person_id', '=', 'affected_person.affected_person_id')
            ->where('affected_person.project_id', $projectId)
            ->count();

        $withEc = DB::table('ec')
            ->join('affected_person', 'ec.affected_person_id', '=', 'affected_person.affected_person_id')
            ->where('affected_person.project_id', $projectId)
            ->count();

        $withApprovedEc = DB::table('ec')
            ->join('affected_person', 'ec.affected_person_id', '=', 'affected_person.affected_person_id')
            ->where('affected_person.project_id', $projectId)
            ->where('ec.status', 'APPROVED')
            ->count();

        $withVoucher = DB::table('voucher')
            ->join('ec', 'voucher.ec_id', '=', 'ec.ec_id')
            ->join('affected_person', 'ec.affected_person_id', '=', 'affected_person.affected_person_id')
            ->where('affected_person.project_id', $projectId)
            ->count();

        return [
            'total_eps' => $totalEps,
            'invalid_eps' => $invalidEps,
            'valid_eps' => $totalEps,
            'with_epicad' => $withEpicad,
            'with_ec' => $withEc,
            'with_approved_ec' => $withApprovedEc,
            'with_voucher' => $withVoucher,
        ];
    }

              /**
      * Efficiently count EPs with blank, invalid-format, or duplicate
      * NID values (EP identity mismatch), optionally scoped to a project.
      * Mobile issues are reported in validateEp but do not affect this count
      * since mobile is not used for EP ID creation.
      */
    public function countInvalidEps(?int $projectId = null): int
    {
        $sql = "
            SELECT COUNT(DISTINCT ap.affected_person_id) AS cnt
            FROM affected_person ap
            LEFT JOIN affected_person nd ON nd.nid_number = ap.nid_number
                AND nd.project_id = ap.project_id
                AND nd.affected_person_id != ap.affected_person_id
                AND ap.nid_number IS NOT NULL AND TRIM(ap.nid_number) != ''
        ";

        $params = [];
        if ($projectId) {
            $sql .= " WHERE ap.project_id = :project_id";
            $params['project_id'] = $projectId;
        } else {
            $sql .= " WHERE 1=1";
        }

        $sql .= "
            AND (
                ap.nid_number IS NULL
                OR TRIM(ap.nid_number) = ''
                OR LENGTH(TRIM(ap.nid_number)) NOT IN (10, 17)
                OR nd.affected_person_id IS NOT NULL

            )
        ";

        $row = DB::selectOne($sql, $params);
        return (int) ($row->cnt ?? 0);
    }

    /**
     * Return a paginated list of invalid EPs with their specific issues.
     *
     * @return array{data: list, pagination: array}
     */
    public function getInvalidEpsPaginated(?int $projectId, int $perPage = 25, int $page = 1): array
    {
        $joinClause = "
            LEFT JOIN affected_person nd ON nd.nid_number = ap.nid_number
                AND nd.project_id = ap.project_id
                AND nd.affected_person_id != ap.affected_person_id
                AND ap.nid_number IS NOT NULL AND TRIM(ap.nid_number) != ''
        
        ";

        $issueCondition = "(
            ap.nid_number IS NULL
            OR TRIM(ap.nid_number) = ''
            OR LENGTH(TRIM(ap.nid_number)) NOT IN (10, 17)
            OR nd.affected_person_id IS NOT NULL
        )";

        $params = [];
        $conditions = [];
        if ($projectId) {
            $conditions[] = "ap.project_id = :project_id";
            $params['project_id'] = $projectId;
        }
        $conditions[] = $issueCondition;
        $whereClause = " WHERE " . implode(" AND ", $conditions);

        $sql = "
            SELECT sub.affected_person_id
            FROM (
                SELECT DISTINCT ap.affected_person_id, ap.hh_id
                FROM affected_person ap
                " . $joinClause . "
                " . $whereClause . "
            ) sub
            ORDER BY CAST(sub.hh_id AS UNSIGNED) ASC, sub.affected_person_id ASC
            LIMIT " . (int) $perPage . " OFFSET " . (int) (($page - 1) * $perPage);

        $rows = DB::select($sql, $params);
        $epIds = array_column($rows, 'affected_person_id');

        $items = [];
        foreach ($epIds as $epId) {
            $result = $this->validateEp((int) $epId);
            if (count($result['issues']) > 0) {
                $items[] = $result;
            }
        }

        $totalSql = "
            SELECT COUNT(DISTINCT ap.affected_person_id) AS cnt
            FROM affected_person ap
            " . $joinClause . "
            " . $whereClause . "
        ";

        $totalRow = DB::selectOne($totalSql, $params);
        $total = (int) ($totalRow->cnt ?? 0);

        $lastPage = (int) ceil($total / $perPage);

        return [
            'data' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

}
