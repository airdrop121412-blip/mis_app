<?php

namespace App\Http\Requests\Api\V1\Master;

/**
 * Shared entity resolution for MasterStoreRequest / MasterUpdateRequest.
 *
 * Reads the {entity} route parameter, validates it against the whitelist,
 * and caches the resolved config so rules() can build dynamic validation.
 */
trait MasterEntityConfig
{
    /** @var array|null cached config */
    private ?array $resolvedConfig = null;

    public function prepareForValidation(): void
    {
        // Resolve once; prepareForValidation runs before rules().
        $this->entityConfig();
    }

    /**
     * @return array{table: string, pk: string, has_code: bool, parent_fk?: array{table: string, key: string, column: string}}
     */
    public function entityConfig(): array
    {
        if ($this->resolvedConfig !== null) {
            return $this->resolvedConfig;
        }

        $entity = $this->route('entity');

        if (! isset(MasterEntityMap::ENTITIES[$entity])) {
            abort(404, "Unknown master entity: {$entity}");
        }

        $this->resolvedConfig = MasterEntityMap::ENTITIES[$entity];

        return $this->resolvedConfig;
    }
}
