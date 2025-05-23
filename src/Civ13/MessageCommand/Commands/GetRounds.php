<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "getrounds" command.
 */
class GetRounds extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $ckey = self::messageWithoutCommand($command, $message_filtered, true, true)) return $this->civ13->reply($message, 'Invalid format! Please use the format: getrounds `ckey`');
        if (! $item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "No verified data found for ID `$ckey`.");
        $rounds = [];
        foreach ($this->civ13->enabled_gameservers as $gameserver) if ($r = $gameserver->getRounds([$item['ss13']])) $rounds[$gameserver->name] = $r;
        if (! $rounds) return $this->civ13->reply($message, 'No data found for that ckey.');
        $builder = Civ13::createBuilder();
        foreach ($rounds as $server_name => $rounds) {
            $embed = $this->civ13->createEmbed()->setTitle($server_name)->addFieldValues('Rounds', strval(count($rounds)));
            if ($user = $this->civ13->verifier->getVerifiedUser($item)) $embed->setAuthor("{$user->username} ({$user->id})", $user->avatar);
            $builder->addEmbed($embed);
        }
        return $message->reply($builder);
    }
}