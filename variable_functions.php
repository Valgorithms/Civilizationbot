<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */ 

use Civ13\Civ13;
use Discord\Parts\User\Activity;

$status_changer_random = function (Civ13 $civ13): bool
{ // on ready
    if (! $civ13::status) {
        unset($civ13->timers['status_changer_timer']);
        $civ13->logger->warning('status is not defined');
        return false;
    }
    if (! $status_array = file($civ13::status, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) {
        unset($civ13->timers['status_changer_timer']);
        $civ13->logger->warning('unable to open file `' . $civ13::status . '`');
        return false;
    }
    list($status, $type, $state) = explode('; ', $status_array[array_rand($status_array)]);
    if (! $status) return false;
    $activity = new Activity($civ13->discord, [ // Discord status            
        'name' => $status,
        'type' => (int) $type, // 0, 1, 2, 3, 4 | Game/Playing, Streaming, Listening, Watching, Custom Status
    ]);
    $civ13->statusChanger($activity, $state);
    return true;
};
$status_changer_timer = function (Civ13 $civ13) use ($status_changer_random): void
{ // on ready
    if (! isset($civ13->timers['status_changer_timer'])) $civ13->timers['status_changer_timer'] = $civ13->discord->getLoop()->addPeriodicTimer(120, function () use ($civ13, $status_changer_random) { $status_changer_random($civ13); });
};
/*$on_ready = function (Civ13 $civ13): void
{    
    // 
};*/