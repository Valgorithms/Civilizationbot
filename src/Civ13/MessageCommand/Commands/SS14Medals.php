<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Civ14\GameServer;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "14medals" command.
 */
class SS14Medals extends Civ13MessageCommand
{
    public function __construct(protected Civ13 &$civ13, protected GameServer &$gameserver){}

    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $id = self::messageWithoutCommand($command, $message_filtered)) {
            if (! $item = $this->civ13->ss14verifier->get('discord', $message->author->id)) return $this->civ13->reply($message, 'Please register your SS14 account using the `/verifyme` command.');
            $id = $item['ss14'] ?? null;
        }
        if (is_numeric($id)) {
            if (! $item = $this->civ13->ss14verifier->get('discord', $id)) return $this->civ13->reply($message, "Unable to locate verified Discord account with id `$id`.");
            $id = $item['ss14'] ?? null;
        }
        if (! $item = $this->gameserver->medals->get('user', $id)) return $this->civ13->reply( $message,
            "No SS14 medals found for `$id`."
        );
        return $this->civ13->reply($message,
            "Medals for {$item['user']}:" . PHP_EOL
            . (implode(PHP_EOL, $item['medals']) ?? 'No medals found.')
        );
    }
}