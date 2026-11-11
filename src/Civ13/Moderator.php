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

use Civ13\Exceptions\VerifierException;
use Discord\Discord;
use Monolog\Logger;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;
use function React\Promise\reject;

/**
 * This enum defines various methods for moderating text content.
 *
 * @method static self EXACT()           Matches the exact word.
 * @method static self RUSSIAN()         Matches any Russian Cyrillic characters.
 * @method static self CHINESE()         Matches any Chinese Han characters.
 * @method static self KOREAN()          Matches any Korean Hangul characters.
 * @method static self STR_STARTS_WITH() Matches if the text starts with the specified word.
 * @method static self STR_ENDS_WITH()   Matches if the text ends with the specified word.
 * @method static self STR_CONTAINS()    Matches if the text contains the specified word.
 *
 * @see https://www.regular-expressions.info/unicode.html for more information on Unicode regular expressions.
 *
 * @method static bool matches(string $lower, array $badwords) Checks if the given text matches any of the bad words based on the specified moderation method.
 */
enum ModerationMethod: string
{
    case EXACT = 'exact';
    case RUSSIAN = 'russian';
    case CHINESE = 'chinese';
    case KOREAN = 'korean';
    //case SPANISH = 'spanish'; // This conflicts with existing player names in the game
    //const SPANISH_CHARS = ['ñ', 'Ñ', 'á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ü', 'Ü'];
    //case UNICODE = 'unicode';
    case STR_STARTS_WITH = 'str_starts_with';
    case STR_ENDS_WITH = 'str_ends_with';
    case STR_CONTAINS = 'str_contains';

    /**
     * Tests `$lower` against a badword rule. `$badwords` carries `word` and an
     * optional `method` (one of this enum's cases); an unknown method falls
     * back to `str_contains`. Returns a truthy match result.
     */
    public static function matches(string $lower, array $badwords): int|bool
    {
        $method = $badwords['method'] ?? null;
        $moderationMethod = (is_string($method) ? self::tryFrom($method) : null) ?? self::STR_CONTAINS;

        return match ($moderationMethod) {
            self::EXACT => preg_match('/\b'.preg_quote($badwords['word'], '/').'\b/i', $lower),
            self::RUSSIAN => preg_match('/\p{Cyrillic}/u', $lower),
            self::CHINESE => preg_match('/\p{Han}/u', $lower),
            self::KOREAN => preg_match('/\p{Hangul}/u', $lower),
            //self::SPANISH => self::str_contains_any($lower, self::SPANISH_CHARS),
            self::STR_STARTS_WITH => str_starts_with($lower, $badwords['word']),
            self::STR_ENDS_WITH => str_ends_with($lower, $badwords['word']),
            self::STR_CONTAINS => str_contains($lower, $badwords['word']),
            // default => str_contains($lower, $badwords['word']), // Redundant
        };
    }

    /** Whether `$haystack` contains any one of `$needles`. */
    private static function str_contains_any(string $haystack, array $needles): bool
    {
        return array_reduce($needles, static fn ($carry, $needle) => $carry || str_contains($haystack, $needle), false);
    }
}

class Moderator
{
    public Civ13 $civ13;
    public Discord $discord;
    public Logger $logger;
    public array $timers = [];
    public string $status = 'status.txt';
    public bool $ready = false;

    /** @param Civ13 $civ13 The bot instance; its `discord` and `logger` are bound by reference. */
    public function __construct(Civ13 $civ13)
    {
        $this->civ13 = &$civ13;
        $this->discord = &$civ13->discord;
        $this->logger = &$civ13->logger;
        $this->afterConstruct();
    }

    /** Runs {@see setup()} immediately if the bot is ready, otherwise on the next `init` event. */
    protected function afterConstruct(): void
    {
        $this->civ13->ready
            ? $this->setup()
            : $this->discord->once('init', fn () => $this->setup());
    }

    /**
     * Registers this instance as `civ13->moderator` and marks it ready.
     *
     * @return PromiseInterface Rejected with {@see \LogicException} if already set up.
     */
    public function setup(): PromiseInterface
    {
        if ($this->ready) {
            return reject(new \LogicException('Moderator already setup'));
        }
        $this->civ13->moderator = &$this;
        $this->logger->info('Added Moderator');
        $this->ready = true;

        return resolve(null);
    }

    /**
     * Scrutinizes the given ckey and applies ban rules if necessary.
     *
     * @param string $ckey The ckey to be scrutinized.
     */
    public function scrutinizeCkey(string $ckey): void
    { // Suspicious user ban rules
        if (! isset($this->civ13->permitted[$ckey]) && ! in_array($ckey, $this->civ13->seen_players)) {
            $this->civ13->seen_players[] = $ckey;
            $ckeyinfo = $this->civ13->ckeyinfo($ckey);
            $ban = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->civ13->discord_formatted}"];
            if ($ckeyinfo['altbanned']) { // Banned with a different ckey
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                    $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true).' (Alt Banned)');
                }
            } else {
                foreach ($ckeyinfo['ips'] as $ip) {
                    /* We should only check new connections
                    $ip_data = $this->civ13->getIpData($ip);
                    if (isset($ip_data['proxy']) && $ip_data['proxy']) { // Proxy
                        if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Proxy)');
                        break;
                    }
                    if (isset($ip_data['hosting']) && $ip_data['hosting']) {
                        if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Hosting)');
                        break;
                    }*/
                    foreach ($this->civ13->blacklisted_regions as $region) {
                        if (str_starts_with($ip, $region)) { // IP Segments
                            if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                                $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true).' (Blacklisted Region)');
                            }
                            break 2;
                        }
                    }
                }
            }
        }
        try {
            if ($this->civ13->verifier->get('ss13', $ckey)) {
                return;
            } // Verified users are exempt from further checks
        } catch (VerifierException $e) { // Verification status is unknown, so never treat the user as unverified
            $this->logger->warning("Skipped new-account checks for `$ckey`: {$e->getMessage()}");

            return;
        }
        if (! isset($this->civ13->permitted[$ckey]) && ! isset($this->civ13->ages[$ckey]) && ($age = $this->civ13->getByondAge($ckey)) && ! $this->civ13->checkByondAge($age)) { // Force new accounts to register in Discord
            $ban = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Byond account `$ckey` must register and be approved to play. ($age) Verify at {$this->civ13->discord_formatted}"];
            if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true));
            }
        }
    }

    /**
     * Moderates game chat by checking for blacklisted words/phrases and taking appropriate actions.
     *
     * @param  GameServer $gameserver        The game server instance where the chat message is being sent.
     * @param  string     $ckey              The ckey of the player sending the chat message.
     * @param  string     $string            The chat message string to be moderated.
     * @param  array      $badwords_array    An array of blacklisted words/phrases and their moderation methods.
     * @param  array       &$badword_warnings An array to store any warnings generated by the moderation process.
     * @param  string|null $warnings_file     The file the updated warning counters are saved to, or null to keep them in memory only.
     * @return bool        True if any infractions were found, false otherwise.
     */
    public function moderate(GameServer $gameserver, string $ckey, string $string, array $badwords_array, array &$badword_warnings, ?string $warnings_file = null): bool
    {
        $lower = strtolower($string);
        //$this->logger->debug("[MODERATE] ckey = `$ckey`, string = `$string`, lower = `$lower`");
        $seenCategories = [];
        $infractions = array_filter($badwords_array, function ($badwords) use ($lower, &$seenCategories) {
            if (($badwords['category'] ?? null) && ! isset($seenCategories[$badwords['category']]) && ModerationMethod::matches($lower, $badwords)) {
                $seenCategories[$badwords['category']] = true;

                return true;
            }

            return false;
        });
        //$this->logger->debug(empty($seenCategories) ? 'No infractions' : 'Infractions found');
        foreach ($infractions as $badwords_arr) {
            $this->__relayViolation($gameserver, $ckey, $badwords_arr, $badword_warnings, $warnings_file);
        }

        return ! empty($seenCategories);
    }
    /**
     * This function is called from the game's chat hook if a player says something that contains a blacklisted word.
     *
     * @param  GameServer   $gameserver        The game server instance where the chat message is being sent.
     * @param  string       $ckey              The player's unique identifier.
     * @param  array        $badwords_array    An array containing information about the blacklisted word.
     * @param  array        &$badword_warnings A reference to an array that stores the number of warnings for each player.
     * @return string|false The warning message or false if the player should not be warned or banned.
     */
    private function __relayViolation(GameServer $gameserver, string $ckey, array $badwords_array, array &$badword_warnings, ?string $warnings_file = null): string|false
    {
        if (Civ13::sanitizeInput($ckey) === Civ13::sanitizeInput($this->discord->username)) {
            return false;
        } // Don't ban or alert staff for the bot

        // Notify staff in Discord about the violation
        $filtered = self::filterWord((string) ($badwords_array['word'] ?? ''));
        $warning = "You are currently violating a server rule. Further violations will result in an automatic ban that will need to be appealed on our Discord. Review the rules at {$this->civ13->rules}. Reason: {$badwords_array['reason']} ({$badwords_array['category']} => $filtered)";
        if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
            $this->civ13->sendMessage($channel, "`$ckey` is".substr($warning, 7));
        }

        if (isset($this->civ13->verifier)) {
            if ($guild = $this->discord->guilds->get('id', $this->civ13->civ13_guild_id)) {
                try {
                    $item = $this->civ13->verifier->get('ss13', $ckey);
                } catch (VerifierException $e) { // Admin status is unknown; warn only rather than risk banning staff
                    $this->logger->warning("Skipped chat-moderation ban for `$ckey`: {$e->getMessage()}");

                    return false;
                }
                if ($item) {
                    if (isset($item['discord']) && $member = $guild->members->get('id', $item['discord'])) {
                        if (isset($this->civ13->role_ids['Admin']) && $member->roles->has($this->civ13->role_ids['Admin'])) {
                            return false;
                        }
                    }
                }
            }
        } // Don't ban an admin

        if (! $this->__relayWarningCounter($ckey, $badwords_array, $badword_warnings, $warnings_file)) {
            return $this->civ13->ban(['ckey' => $ckey, 'duration' => $badwords_array['duration'], 'reason' => "Blacklisted phrase ($filtered). Review the rules at {$this->civ13->rules}. Appeal at {$this->civ13->discord_formatted}"]);
        }
        $gameserver->DirectMessage($warning, $this->discord->username, $ckey);

        return $warning;
    }
    /*
     * This function determines if a player has been warned too many times for a specific category of bad words
     * If they have, it will return false to indicate they should be banned
     * If they have not, it will return true to indicate they should be warned
     */
    private function __relayWarningCounter(string $ckey, array $badwords_array, array &$badword_warnings, ?string $warnings_file = null): bool
    {
        $badword_warnings[$ckey][$badwords_array['category']] = ($badword_warnings[$ckey][$badwords_array['category']] ?? 0) + 1;

        if ($warnings_file !== null) {
            $this->civ13->VarSave($warnings_file, $badword_warnings);
        }

        return $badword_warnings[$ckey][$badwords_array['category']] <= ($badwords_array['warnings'] ?? 0);
    }

    /**
     * Masks a blacklisted word for staff and player notices, keeping only its first and last characters.
     * Words of one or two characters are shown as-is because there is nothing left to mask.
     */
    public static function filterWord(string $word): string
    {
        $length = mb_strlen($word);
        if ($length <= 2) {
            return $word;
        }

        return mb_substr($word, 0, 1).str_repeat('%', $length - 2).mb_substr($word, -1);
    }
}
