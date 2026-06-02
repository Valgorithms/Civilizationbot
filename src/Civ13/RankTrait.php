<?php

declare(strict_types=1);

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13;

use Discord\Helpers\Collection;

trait RankTrait
{
    public function checkRank(?Collection $roles = null, array $allowed_ranks = []): bool
    {
        if (empty($allowed_ranks)) {
            return true;
        }
        $filtered_ranks = array_filter($allowed_ranks, fn ($rank) => isset($this->civ13->role_ids[$rank]));
        $resolved_ranks = array_map(fn ($rank) => $this->civ13->role_ids[$rank], $filtered_ranks);
        foreach ($roles as $role) {
            if (in_array($role->id, $resolved_ranks)) {
                return true;
            }
        }

        return false;
    }
}
