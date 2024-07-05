<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13\Interfaces;

use Discord\Helpers\Collection;
use Handler\HandlerInterface;

interface CivHandlerInterface extends HandlerInterface
{
    public function checkRank(?Collection $roles = null, array $allowed_ranks = []): bool;
}