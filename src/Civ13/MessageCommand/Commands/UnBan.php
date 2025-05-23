<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Byond\Byond;
use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "Unban" command.
 */
class UnBan extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (is_numeric($ckey = Civ13::sanitizeInput($message_filtered['message_content_lower'] = substr($message_filtered['message_content_lower'], strlen(trim($command))))))
            if (! $item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "No data found for Discord ID `$ckey`.");
                else $ckey = $item['ss13'];
        if (isset($this->civ13->verifier) && ! $message->member->roles->has($this->civ13->role_ids['Ambassador']) && ! $this->civ13->verifier->isVerified($ckey)) return $this->civ13->reply($message, "No verified data found for ID `$ckey`. Byond user must verify with `approveme` first.");
        if (! isset($this->civ13->ages[$ckey]) && ! Byond::isValidCkey($ckey)) return $this->civ13->reply($message, "Byond username `$ckey` does not exist.");
        $this->civ13->unban($ckey, $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13']);
        return $this->civ13->reply($message, "`$admin` unbanned `$ckey`");
    }
}