<?php

namespace App\Services\EpId;

use App\Exceptions\BusinessRuleException;
use App\Models\AffectedPerson;
use App\Models\District;
use App\Models\EpidCode;
use App\Models\LandStatus;
use App\Models\Project;
use App\Models\Upazila;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EP ID generation service (architecture §8).
 *
 * Builds a project-continuous EP ID from CONFIGURABLE codes supplied in the
 * epid_code table (Admin → EP ID Codes):
 *   district.code(1) + LPAD(upazila.code,2,'0') + LPAD(project_phase.code,2,'0')
 *   + title/non_tile.code(1)   (from land_status.name; 1=Title, 2=Non-Title)
 *   + loss_category.code(1)    (from category_of_loss, or '0' when empty)
 *   + '#' + LPAD(serial,4,'0')
 *
 * Example: district 1 + upazila 02 + phase 01 + Non-Title 2 + loss 2
 *          → "1020122#0001"
 *
 * Transaction-safe: atomic UPDATE ... LAST_INSERT_ID(last_value + 1)
 * row-locks the sequence row to serialize concurrent surveyors.
 * UNIQUE(project_id, ep_id) is the final DB backstop.
 */
class EpIdService
{
    private const MAX_RETRIES = 1;

    /**
     * Per-request cache of used main-EP serials, keyed by project_id.
     * Populated once on first call and updated as new serials are handed out,
     * so bulk imports (15 000+ rows) don't trigger a full table scan per row.
     */
    private array $projectSerialCache = [];

    /**
     * Per-request cache of used indirect-EP serials, keyed by "$projectId:$type".
     */
    private array $typeSerialCache = [];

    /**
     * Per-request set of existing EP IDs, keyed by "$projectId".
     * Used for in-memory collision checks in generate().
     */
    private array $epIdLookupCache = [];

    /**
     * Per-request cache of master models keyed "type:id". buildPrefix()
     * resolves the same district/upazila/land-status/project rows on EVERY
     * imported row — caching them removes four WAN round-trips per row
     * during bulk imports (they are static configuration data and cannot
     * change within a single request).
     *
     * @var array<string, District|Upazila|LandStatus|Project>
     */
    private array $modelCache = [];

    /**
     * Per-request cache of resolved epid codes keyed "category|name|projectId".
     * codeFor() ran one SELECT per lookup per row; codes are admin-configured
     * and stable within a request, so results are memoised (misses — the
     * EP_ID_CODE_MISSING exception — are never cached).
     */
    private array $codeCache = [];

    /**
     * Generate a unique EP ID for the given project and geo-location.
     *
     * @param  string|null  $lossCategory  affected_person.category_of_loss
     * @param  string|null  $ownership  affected_person.ownership — 'Title' /
     *                                  'Non-title' drives the title/non-tile
     *                                  digit (falls back to land_status)
     * @param  string|null  $phase  affected_person.phase (falls back to
     *                              project.phase, then to "00")
     *
     * @throws BusinessRuleException when a required code is not configured or a
     *                               collision persists after retries
     */
    public function generate(
        int $projectId,
        int $districtId,
        int $upazilaId,
        int $landStatusId,
        ?string $lossCategory,
        ?string $ownership = null,
        ?string $phase = null,
    ): string {
        $prefix = $this->buildPrefix($projectId, $districtId, $upazilaId, $landStatusId, $lossCategory, $ownership, $phase);

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $serial = $this->nextFreeProjectSerial($projectId);
            $epId = $prefix.'#'.str_pad($serial, 4, '0', STR_PAD_LEFT);

            // Check collision against existing EP IDs using the in-memory
            // cache (populated once on first call) instead of a per-row
            // database query — 15 000+ rows would otherwise issue 15 000+
            // individual SELECT … EXISTS queries.
            if (! isset($this->epIdLookupCache[$projectId])) {
                $this->epIdLookupCache[$projectId] = DB::table('affected_person')
                    ->where('project_id', $projectId)
                    ->whereNotNull('ep_id')
                    ->pluck('ep_id')
                    ->flip();
            }
            $collides = isset($this->epIdLookupCache[$projectId][$epId]);

            if (! $collides) {
                return $epId;
            }
        }

        throw new BusinessRuleException(
            'EP ID collision after retries.',
            'EP_ID_COLLISION',
        );
    }

    /**
     * Build the prefix of an EP ID (no serial part) by concatenating the
     * configured category codes:
     *
     * District + Upazila(padded to 2) + Phase(padded to 2) + Title/Non-Tile + Loss.
     *
     * Code lengths are configurable per category (see EpidCode::CODE_MAX_LENGTH);
     * the prefix is therefore variable-length and is only ever parsed via the
     * '#' serial separator. The phase is resolved from the EP/override first,
     * falling back to the project's phase, then to "00" when neither is set.
     *
     * @throws BusinessRuleException when a required code is not configured
     */
    public function buildPrefix(
        int $projectId,
        int $districtId,
        int $upazilaId,
        int $landStatusId,
        ?string $lossCategory,
        ?string $ownership = null,
        ?string $phase = null,
    ): string {
        $district = $this->cachedModel('district', $districtId);
        $upazila = $this->cachedModel('upazila', $upazilaId);
        $landStatus = $this->cachedModel('land_status', $landStatusId);
        $project = $this->cachedModel('project', $projectId);

        $districtCode = $this->codeFor(EpidCode::CATEGORY_DISTRICT, $district->name, $projectId);
        $upazilaCode = str_pad($this->codeFor(EpidCode::CATEGORY_UPAZILA, $upazila->name, $projectId), 2, '0', STR_PAD_LEFT);
        $phaseName = $phase ?? $project->phase;
        $phaseCode = str_pad(
            $phaseName !== null && trim($phaseName) !== ''
                ? $this->codeFor(EpidCode::CATEGORY_PROJECT_PHASE, $phaseName, $projectId)
                : '0',
            2,
            '0',
            STR_PAD_LEFT,
        );
        $titleName = $this->canonicalTitleName($ownership, $landStatus->name);
        $titleCode = $this->codeFor($this->titleCategoryFor($titleName), $titleName, $projectId);
        $lossCode = $lossCategory !== null && trim($lossCategory) !== ''
            ? $this->codeFor(EpidCode::CATEGORY_LOSS_CATEGORY, $lossCategory, $projectId)
            : '0';

        return $districtCode.$upazilaCode.$phaseCode.$titleCode.$lossCode;
    }

    /**
     * Generate the EP ID of an INDIRECT affected person (tenant / wage
     * labour). Same prefix format as direct EPs, but the serial comes
     * from its own per-project sequence key so each type starts at #0001.
     *
     * The indirect person's own district/upazila are used when present
     * (optional — they fall back to the parent EP's), the title/non-title
     * digit follows the parent EP's land status, and the loss digit is taken
     * from 'Tenant' / 'Wage Loss'.
     *
     * @param  int|null  $districtId  tenant/worker district (null → '0')
     * @param  int|null  $upazilaId  tenant/worker upazila (null → '00')
     * @param  int|null  $landStatusId  parent EP land status (null → '0')
     * @param  string  $sequenceKey  e.g. 'EP_SERIAL_TENANT' / 'EP_SERIAL_WORKER'
     *
     * @throws BusinessRuleException when a required code is not configured or a
     *                               collision persists after retries
     */
    public function generateIndirect(
        int $projectId,
        ?int $districtId,
        ?int $upazilaId,
        ?int $landStatusId,
        string $lossCategory,
        string $sequenceKey,
        ?string $ownership = null,
        ?string $phase = null,
    ): string {
        $prefix = $this->buildIndirectPrefix($projectId, $districtId, $upazilaId, $landStatusId, $lossCategory, $ownership, $phase);

        $table = match ($sequenceKey) {
            'EP_SERIAL_WORKER' => 'worker',
            default => 'tenant',
        };

        $epId = $prefix.'#'.str_pad($this->nextFreeTypeSerial($projectId, $table, $prefix), 4, '0', STR_PAD_LEFT);

        if ($this->epIdCollides($projectId, $epId)) {
            throw new BusinessRuleException(
                'Indirect EP ID collision after retries.',
                'EP_ID_COLLISION',
            );
        }

        return $epId;
    }

    /**
     * Build the prefix for an indirect person. Geo-location is
     * optional (falls back to '0'/'00'), title code follows land status.
     */
    private function buildIndirectPrefix(
        int $projectId,
        ?int $districtId,
        ?int $upazilaId,
        ?int $landStatusId,
        string $lossCategory,
        ?string $ownership = null,
        ?string $phase = null,
    ): string {
        $project = $this->cachedModel('project', $projectId);

        $districtCode = $districtId !== null
            ? $this->codeFor(EpidCode::CATEGORY_DISTRICT, $this->cachedModel('district', $districtId)->name, $projectId)
            : '0';

        $upazilaCode = $upazilaId !== null
            ? str_pad($this->codeFor(EpidCode::CATEGORY_UPAZILA, $this->cachedModel('upazila', $upazilaId)->name, $projectId), 2, '0', STR_PAD_LEFT)
            : '00';

        $phaseName = $phase ?? $project->phase;
        $phaseCode = str_pad(
            $phaseName !== null && trim($phaseName) !== ''
                ? $this->codeFor(EpidCode::CATEGORY_PROJECT_PHASE, $phaseName, $projectId)
                : '0',
            2,
            '0',
            STR_PAD_LEFT,
        );

        if ($landStatusId !== null) {
            $titleName = $this->canonicalTitleName($ownership, $this->cachedModel('land_status', $landStatusId)->name);
        } else {
            $titleName = $this->canonicalTitleName($ownership, '');
        }

        $titleCode = $titleName !== ''
            ? $this->codeFor($this->titleCategoryFor($titleName), $titleName, $projectId)
            : '0';

        $lossCode = $this->codeFor(EpidCode::CATEGORY_LOSS_CATEGORY, $lossCategory, $projectId);

        return $districtCode.$upazilaCode.$phaseCode.$titleCode.$lossCode;
    }

    /**
     * Check an EP ID for collisions across the EP, tenant and worker tables
     * within the same project (the uniqueness backstop for indirect IDs).
     */
    private function epIdCollides(int $projectId, string $epId): bool
    {
        if (DB::table('affected_person')
            ->where('project_id', $projectId)
            ->where('ep_id', $epId)
            ->exists()) {
            return true;
        }

        foreach (['tenant', 'worker'] as $table) {
            if (DB::table($table)
                ->join('affected_person as ap', 'ap.affected_person_id', '=', $table.'.affected_person_id')
                ->where('ap.project_id', $projectId)
                ->where($table.'.ep_id', $epId)
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refresh the EP ID of every DRAFT EP in a project after the project's
     * phase changes. The prefix is recomputed (keeping the EP's own serial) so
     * IDs automatically pick up the new phase code. EPs with an explicit phase
     * are left alone (their prefix does not depend on the project phase).
     */
    public function refreshDraftEpIds(int $projectId): void
    {
        $drafts = AffectedPerson::query()
            ->where('project_id', $projectId)
            ->where('status', 'DRAFT')
            ->whereNotNull('ep_id')
            ->get();

        foreach ($drafts as $ep) {
            $this->refreshEpId($ep);
        }
    }

    /**
     * Recompute the EP ID of a single EP, keeping its serial. Used when an
     * EP's prefix-affecting field changes (e.g. phase) so the ID reflects the
     * latest values automatically. Missing codes or collisions leave the
     * current ID untouched.
     */
    public function refreshEpId(AffectedPerson $ep): void
    {
        $serial = Str::afterLast($ep->ep_id, '#');
        if (! ctype_digit($serial)) {
            return;
        }

        try {
            $prefix = $this->buildPrefix(
                $ep->project_id,
                $ep->district_id,
                $ep->upazila_id,
                $ep->land_status_id,
                $ep->category_of_loss,
                $ep->ownership,
                $ep->phase,
            );
        } catch (BusinessRuleException) {
            // A code for this EP is not configured — keep its current ID.
            return;
        }

        $newEpId = $prefix.'#'.str_pad($serial, 4, '0', STR_PAD_LEFT);
        if ($newEpId === $ep->ep_id) {
            return;
        }

        $collides = DB::table('affected_person')
            ->where('project_id', $ep->project_id)
            ->where('ep_id', $newEpId)
            ->where('affected_person_id', '!=', $ep->affected_person_id)
            ->exists();

        if (! $collides) {
            DB::table('affected_person')
                ->where('affected_person_id', $ep->affected_person_id)
                ->update(['ep_id' => $newEpId]);
        }
    }

    /**
     * Resolve the active epid_code for a (category, name). Throws a clear
     * business-rule error when the admin has not configured the code yet.
     *
     * When a project id is given, the project's own code wins over the shared
     * global (project_id NULL) code for the same name, so duplicate names
     * across scopes can never resolve to the wrong project's code.
     */
    private function codeFor(string $category, string $name, ?int $projectId = null): string
    {
        $cacheKey = $category.'|'.$name.'|'.($projectId ?? '');
        if (isset($this->codeCache[$cacheKey])) {
            return $this->codeCache[$cacheKey];
        }

        $query = EpidCode::query()
            ->active()
            ->where('category', $category)
            ->where('name', $name);

        if ($projectId !== null) {
            $query->where(fn ($q) => $q->where('project_id', $projectId)->orWhereNull('project_id'))
                ->orderByRaw('project_id IS NULL');
        }

        $code = $query->value('code');

        if ($code === null) {
            throw new BusinessRuleException(
                "EP ID code is not configured for {$category}: {$name}. ".
                'Add it under Admin → EP ID Codes.',
                'EP_ID_CODE_MISSING',
            );
        }

        return $this->codeCache[$cacheKey] = $code;
    }

    /**
     * Master-model lookup with a per-request cache (see $modelCache).
     * findOrFail() semantics are preserved exactly — a missing id still
     * throws ModelNotFoundException on first access.
     *
     * @return District|Upazila|LandStatus|Project
     */
    private function cachedModel(string $type, int $id): District|Upazila|LandStatus|Project
    {
        $key = $type.':'.$id;

        if (! array_key_exists($key, $this->modelCache)) {
            $this->modelCache[$key] = match ($type) {
                'district' => District::findOrFail($id),
                'upazila' => Upazila::findOrFail($id),
                'land_status' => LandStatus::findOrFail($id),
                'project' => Project::findOrFail($id),
                default => throw new \InvalidArgumentException("Unknown cached model type '{$type}'."),
            };
        }

        return $this->modelCache[$key];
    }

    /**
     * Resolve the name that determines the title/non-tile digit.
     *
     * The EP's Ownership field ('Title' / 'Non-title') is authoritative; it is
     * mapped to the canonical epid_code names. When ownership is empty the
     * land_status name is used as a legacy fallback.
     */
    private function canonicalTitleName(?string $ownership, string $fallbackName): string
    {
        if ($ownership !== null && trim($ownership) !== '') {
            return str_contains(strtolower(trim($ownership)), 'non')
                ? 'Non-Title'
                : 'Title';
        }

        return $fallbackName;
    }

    /**
     * Map a title/non-title name to its epid_code category.
     * 'Non-Title' → non_tile, everything else → title.
     */
    private function titleCategoryFor(string $titleName): string
    {
        return str_contains(strtolower(trim($titleName)), 'non')
            ? EpidCode::CATEGORY_NON_TILE
            : EpidCode::CATEGORY_TITLE;
    }

    /**
     * Find the lowest positive serial (1..9999) not yet used by any affected
     * person EP ID in the project.
     *
     * Main EP serials are maintained as one contiguous per-project series
     * (independent of tenant / wage-labour series). Freed serials from deleted
     * EPs are reused by the next entry automatically.
     */
    private function nextFreeProjectSerial(int $projectId): int
    {
        // Populate the cache once per request so we don't do a full table
        // scan on every row during bulk imports (15 000+ rows = disaster).
        if (! isset($this->projectSerialCache[$projectId])) {
            $used = DB::table('affected_person')
                ->where('project_id', $projectId)
                ->whereNotNull('ep_id')
                ->pluck('ep_id');

            $taken = [];
            foreach ($used as $epId) {
                $serial = Str::afterLast((string) $epId, '#');
                if (ctype_digit($serial)) {
                    $taken[(int) $serial] = true;
                }
            }
            $this->projectSerialCache[$projectId] = $taken;
        }

        $taken = $this->projectSerialCache[$projectId];

        for ($serial = 1; $serial <= 9999; $serial++) {
            if (! isset($taken[$serial])) {
                $taken[$serial] = true;  // reserve for this import run
                $this->projectSerialCache[$projectId] = $taken;
                return $serial;
            }
        }

        throw new BusinessRuleException(
            'EP ID serial space exhausted for project '.$projectId.'.',
            'EP_ID_SERIAL_EXHAUSTED',
        );
    }

    /**
     * Find the lowest positive serial (1..9999) not currently used by the
     * given indirect type (tenant / worker) anywhere in the project.
     *
     * Indirect serials form one contiguous per-project series per type,
     * independent of which main EP they belong to. Freed numbers (from
     * deleted records) are reused by the next entry automatically. Where a
     * candidate string would collide with another table, it is skipped so the
     * full EP ID stays unique within the project.
     */
    private function nextFreeTypeSerial(int $projectId, string $type, string $prefix): int
    {
        $cacheKey = $projectId.':'.$type;

        // Populate the cache once per request so we don't do a full JOIN
        // query on every row during bulk imports (15 000+ rows = disaster).
        if (! isset($this->typeSerialCache[$cacheKey])) {
            $taken = [];
            $ids = DB::table($type)
                ->join('affected_person as ap', 'ap.affected_person_id', '=', $type.'.affected_person_id')
                ->where('ap.project_id', $projectId)
                ->whereNotNull($type.'.ep_id')
                ->pluck($type.'.ep_id');

            foreach ($ids as $epId) {
                $serial = Str::afterLast((string) $epId, '#');
                if (ctype_digit($serial)) {
                    $taken[(int) $serial] = true;
                }
            }
            $this->typeSerialCache[$cacheKey] = $taken;
        }

        $taken = $this->typeSerialCache[$cacheKey];

        for ($serial = 1; $serial <= 9999; $serial++) {
            if (isset($taken[$serial])) {
                continue;
            }

            if (! $this->epIdCollides($projectId, $prefix.'#'.str_pad($serial, 4, '0', STR_PAD_LEFT))) {
                $taken[$serial] = true;  // reserve for this import run
                $this->typeSerialCache[$cacheKey] = $taken;
                return $serial;
            }
        }

        throw new BusinessRuleException(
            'EP ID serial space exhausted for type '.$type.' in project '.$projectId.'.',
            'EP_ID_SERIAL_EXHAUSTED',
        );
    }

    /**
     * Generate an EC number (same pattern, different sequence key).
     */
    public function generateEcNumber(int $projectId): string
    {
        $serial = $this->nextSequenceValue($projectId, 'EC_NUMBER');

        return 'EC-'.$projectId.'-'.str_pad($serial, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a voucher number (same pattern, different sequence key).
     */
    public function generateVoucherNumber(int $projectId): string
    {
        $serial = $this->nextSequenceValue($projectId, 'VOUCHER_NUMBER');

        return 'VC-'.$projectId.'-'.str_pad($serial, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Generic next-sequence-value for any key (EC_NUMBER, VOUCHER_NUMBER).
     */
    private function nextSequenceValue(int $projectId, string $key): int
    {
        DB::table('ep_sequence')->insertOrIgnore([
            'project_id' => $projectId,
            'sequence_key' => $key,
            'last_value' => 0,
        ]);

        DB::selectOne(
            'UPDATE ep_sequence
                SET `last_value` = LAST_INSERT_ID(`last_value` + 1)
              WHERE project_id = ? AND sequence_key = ?
            ',
            [$projectId, $key]
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() as id')->id;
    }
}
