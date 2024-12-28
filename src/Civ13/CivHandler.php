<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Civ13\Civ13;
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
        $this->discord =& $civ13->discord;
        $this->logger =& $civ13->logger;
    }
}