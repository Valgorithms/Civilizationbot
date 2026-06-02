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

use Civ13\Interfaces\CivHandlerInterface;
use Discord\Discord;
use Monolog\Logger;
use Handler\Handler;

abstract class CivHandler extends Handler implements CivHandlerInterface
{
    public Discord $discord;
    public Logger $logger;

    use RankTrait;
    
    public function __construct(public Civ13 &$civ13, array $handlers = [])
    {
        parent::__construct();
        $this->attributes['handlers'] = $handlers;
        $this->discord = &$civ13->discord;
        $this->logger = &$civ13->logger;
    }
}
