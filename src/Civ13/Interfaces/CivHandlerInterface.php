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

/**
 * A {@see HandlerInterface} whose entries are gated by Discord role rank:
 * `checkRank()` decides whether a member holding `$roles` may run a handler
 * that requires one of `$allowed_ranks`. Implemented via {@see \Civ13\RankTrait}.
 *
 * @see \Civ13\RankTrait The shared `checkRank()` implementation
 * @see \Handler\HandlerInterface The base handler-collection contract
 */
interface CivHandlerInterface extends HandlerInterface
{
    /**
     * Whether a member holding `$roles` is allowed to run a handler that
     * requires one of `$allowed_ranks` (an empty list means "anyone").
     */
    public function checkRank(?Collection $roles = null, array $allowed_ranks = []): bool;
}
