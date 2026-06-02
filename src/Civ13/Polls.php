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

use Discord\Discord;
use Discord\Parts\Channel\Poll\Poll;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

class Polls
{
    public const VERSION_QUESTION = 'Version?';
    public const VERSION_ANSWERS = [
        '13',
        '14',
    ];
    public const VERSION_ALLOW_MULTISELECT = false;
    public const VERSION_DURATION = 1;
    
    public const GAMEMODE_QUESTION = 'Gamemode?';
    public const GAMEMODE_ANSWERS = [
        'Nomads',
        'TDM',
        'RP',
    ];
    public const GAMEMODE_ALLOW_MULTISELECT = false;
    public const GAMEMODE_DURATION = 1;

    public const EPOCH_QUESTION = 'Epoch?';
    public const EPOCH_ANSWERS = [
        'Stone',
        'Bronze',
        'Medieval',
        'Imperial',
        'Industrial',
        'Modern',
    ];
    public const EPOCH_ALLOW_MULTISELECT = false;
    public const EPOCH_DURATION = 1;

    public const RESEARCH_QUESTION = 'Research method?';
    public const RESEARCH_ANSWERS = [
        'Auto',
        'Classic',
        'Locked',
    ];
    public const RESEARCH_ALLOW_MULTISELECT = false;
    public const RESEARCH_DURATION = 1;

    public const MAPSWAP_QUESTION = 'Change map?';
    public const MAPSWAP_ANSWERS = [
        'Yes',
        'No',
    ];
    public const MAPSWAP_ALLOW_MULTISELECT = false;
    public const MAPSWAP_DURATION = 1;

    //CONST MAP_QUESTION = 'Map?';

    /**
     * Retrieves a poll based on the specified type.
     *
     * @param  Discord                $discord The Discord instance.
     * @param  string                 $type    The type of the poll.
     * @return PromiseInterface<Poll> A promise that resolves to the poll instance.
     * @throws \Exception             If the poll type is invalid.
     */
    public static function getPoll(Discord $discord, string $type): PromiseInterface
    {
        if (! $type = strtoupper($type)) {
            return reject(new \Exception('Available polls: '.implode(', ', array_keys(self::listPolls()))));
        }
        if (! isset(self::listPolls()[$type])) {
            return reject(new \Exception("Invalid poll type `$type`. Available polls: ".implode(', ', array_keys(self::listPolls()))));
        }

        return resolve(
            (new Poll($discord))
                ->setQuestion(constant('self::'.$type.'_QUESTION')) // The question of the poll. Only text is supported
                ->setAnswers(constant('self::'.$type.'_ANSWERS')) // Each of the answers available in the poll, up to 10
                ->setAllowMultiselect(constant('self::'.$type.'_ALLOW_MULTISELECT')) // Whether a user can select multiple answers
                ->setDuration(constant('self::'.$type.'_DURATION')) // Number of hours the poll should be open for, up to 32 days. Defaults to 24
        );
    }

    /**
     * Lists all poll questions by extracting the constant names that end with '_QUESTION'.
     *
     * This method uses reflection to get all constants defined in the class,
     * filters them to include only those whose names end with '_QUESTION',
     * and then maps these names to remove the '_QUESTION' suffix.
     * The resulting array is then flipped, making the original names the values.
     *
     * @return array An associative array where the keys are the poll names without the '_QUESTION' suffix.
     */
    public static function listPolls(): array
    {
        return array_flip(array_map(fn ($name) => substr($name, 0, -9), array_filter(array_keys((new \ReflectionClass(__CLASS__))->getConstants()), fn ($name) => str_ends_with($name, '_QUESTION'))));
    }
}
