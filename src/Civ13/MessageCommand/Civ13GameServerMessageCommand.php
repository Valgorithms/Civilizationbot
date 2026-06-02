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

namespace Civ13\MessageCommand;

use Civ13\Civ13;
use Civ13\GameServer;

class Civ13GameServerMessageCommand extends Civ13MessageCommand
{
    public function __construct(protected Civ13 &$civ13, protected GameServer &$gameserver)
    {
    }
    
    public function new(\Closure|callable|null $callback = null): static
    {
        $new = new static($this->civ13, $this->gameserver);
        $new->setCallback($callback);

        return $new;
    }
}
