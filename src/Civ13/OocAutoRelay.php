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

/**
 * Turns a plain Discord message in a server's #ooc channel into one line of
 * in-game OOC chat. Pure helpers only: the Discord listener gathers the data
 * and `GameServer::OOCMessage()` writes the IPC record.
 */
final class OocAutoRelay
{
    /** Longest relayed line, in characters; longer messages are cut with an ellipsis. */
    public const MAX_LENGTH = 400;

    /**
     * Whether a message should be relayed, before formatting.
     *
     * @param bool        $own_guild   The message is in the bot's home guild.
     * @param bool        $is_bot      The author is a bot or the message came from a webhook (the game's own relay).
     * @param bool        $is_command  The message calls the bot (prefix or mention), so the command handler owns it.
     * @param string|null $ckey        The author's verified ckey, or null when unverified.
     * @param bool        $banished    The author has the Banished role or is banned on any server.
     */
    public static function eligible(bool $own_guild, bool $is_bot, bool $is_command, ?string $ckey, bool $banished): bool
    {
        return $own_guild && ! $is_bot && ! $is_command && $ckey !== null && $ckey !== '' && ! $banished;
    }

    /**
     * Converts Discord content into a single game-safe line, or null when nothing is left to relay.
     *
     * @param array<string,string> $users       Mentioned user id => display name.
     * @param array<string,string> $roles       Mentioned role id => role name.
     * @param array<string,string> $channels    Channel id => channel name.
     * @param int                  $attachments Number of attachments/stickers on the message.
     */
    public static function format(string $content, array $users = [], array $roles = [], array $channels = [], int $attachments = 0): ?string
    {
        $text = preg_replace_callback('/<@!?(\d+)>/', static fn (array $m): string => '@'.($users[$m[1]] ?? 'unknown-user'), $content) ?? '';
        $text = preg_replace_callback('/<@&(\d+)>/', static fn (array $m): string => '@'.($roles[$m[1]] ?? 'unknown-role'), $text) ?? '';
        $text = preg_replace_callback('/<#(\d+)>/', static fn (array $m): string => '#'.($channels[$m[1]] ?? 'unknown-channel'), $text) ?? '';
        $text = preg_replace('/<a?:(\w+):\d+>/', ':$1:', $text) ?? ''; // Custom emoji
        $text = preg_replace('/<t:(\d+)(?::[tTdDfFR])?>/', '<t:$1>', $text) ?? ''; // Keep timestamps short
        $text = str_replace(':::', ': : :', $text); // IPC field separator
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? ''; // Newlines and other control characters
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($attachments > 0) {
            $text = trim($text.' '.($attachments === 1 ? '[attachment]' : "[$attachments attachments]"));
        }
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH - 1)).'…';
        }

        return $text;
    }

    /** Whether `$text` trips any OOC badword rule, in which case it is not relayed. */
    public static function blocked(string $text, array $badwords): bool
    {
        if (! enum_exists(ModerationMethod::class, false)) {
            class_exists(Moderator::class); // The enum lives in Moderator.php, outside PSR-4 naming
        }
        $lower = strtolower($text);
        foreach ($badwords as $rule) {
            if (isset($rule['word']) && ModerationMethod::matches($lower, $rule)) {
                return true;
            }
        }

        return false;
    }
}
