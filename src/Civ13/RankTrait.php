<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Helpers\Collection;

trait RankTrait
{
    public function checkRank(?Collection $roles = null, array $allowed_ranks = []): bool
    {
        if (empty($allowed_ranks)) return true;
        $resolved_ranks = [];
        foreach ($allowed_ranks as $rank) if (isset($this->civ13->role_ids[$rank])) $resolved_ranks[] = $this->civ13->role_ids[$rank];
        foreach ($roles as $role) if (in_array($role->id, $resolved_ranks)) return true;
        return false;
    }
}