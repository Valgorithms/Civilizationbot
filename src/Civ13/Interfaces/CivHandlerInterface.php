<?php

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13\Interfaces;

use Discord\Helpers\Collection;
use Handler\HandlerInterface;

interface CivHandlerInterface extends HandlerInterface
{
    public function checkRank(?Collection $roles = null, array $allowed_ranks = []): bool;
}
