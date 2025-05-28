<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13GameServerMessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "Civ13BRMedals" command.
 */
class Civ13GameServerBRMedals extends Civ13GameServerMessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $ckey = self::messageWithoutCommand($command, $message_filtered, true, true)) return $this->civ13->reply($message, 'Wrong format. Please try `brmedals [ckey]`.');
        if (! $msg = self::brmedals($this->civ13->enabled_gameservers['tdm']->basedir . Civ13::awards_br, $ckey)) return $this->civ13->reply($message, 'There was an error trying to get your medals!');
        return $this->civ13->reply($message, $msg, 'brmedals.txt');
    }

    public static function brmedals(string $fp, string $ckey): string
    {
        if (! $search = @fopen($fp, 'r')) return "Error opening `$fp`.";
        $result = '';
        while (! feof($search))
            if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey))
                if (! $duser = explode(';', $line))
                    if (isset($duser[5]) && $duser[0] === $ckey)
                        $result .= "**{$duser[1]}:** placed *{$duser[2]} of {$duser[5]},* on {$duser[4]} ({$duser[3]})" . PHP_EOL;
        return $result ?: "No medals found for `$ckey`.";
    }
}