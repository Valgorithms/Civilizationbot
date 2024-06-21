<?php
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Byond\Byond;
use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\User\Member;
use Monolog\Logger;
use React\Promise\PromiseInterface;

class MessageServiceManager
{
    public Civ13 $civ13;
    public Discord $discord;
    public Logger $logger;
    public MessageHandler $messageHandler;

    public function __construct(Civ13 &$civ13) {
        $this->civ13 =& $civ13;
        $this->discord =& $civ13->discord;
        $this->logger =& $civ13->logger;
        $this->messageHandler = new MessageHandler($this->civ13);
        $this->__afterConstruct();
    }

    public function __afterConstruct()
    {
        $this->__generateGlobalMessageCommands();
        $this->logger->debug('[CHAT COMMAND LIST] ' . PHP_EOL . $this->messageHandler->generateHelp());
    }

    public function generateHelp(?Collection $roles): string
    {
        return $this->messageHandler->generateHelp($roles);
    }

    public function handle(Message $message): ?PromiseInterface
    {
        if ($return = $this->messageHandler->handle($message)) return $return;
        $message_array = $this->civ13->filterMessage($message);
        if (! $message_array['called']) return null; // Not a command
        if (! $message_array['message_content_lower']) { // No command given
            $random_responses = ['You can see a full list of commands by using the `help` command.'];
            $random_responses = [
                'You can see a full list of commands by using the `help` command.',
                'I\'m sorry, I can\'t do that, Dave.',
                '404 Error: Humor not found.',
                'Hmm, looks like someone called me to just enjoy my company.',
                'Seems like I\'ve been summoned!',
                'I see you\'ve summoned the almighty ' . ($this->discord->username ?? $this->discord->username) . ', ready to dazzle you with... absolutely nothing!',
                'Ah, the sweet sound of my name being called!',
                'I\'m here, reporting for duty!',
                'Greetings, human! It appears you\'ve summoned me to bask in my digital presence.',
                'You rang? Or was that just a pocket dial in the digital realm?',
                'Ah, the classic call and no command combo!',
                'I\'m here, at your service!',
                'You\'ve beckoned, and here I am!'
            ];
            if (count($random_responses) > 0) return $this->civ13->reply($message, $random_responses[rand(0, count($random_responses)-1)]);
        }
        if ($message_array['message_content_lower'] === 'dev')
            if (isset($this->civ13->technician_id) && isset($this->civ13->role_ids['Chief Technical Officer']))
                if ($message->user_id === $this->civ13->technician_id)
                    return $message->member->addRole($this->civ13->role_ids['Chief Technical Officer']);
        return null;
    }

    public function offsetGet(int|string $offset): array
    {
        return $this->messageHandler->offsetGet($offset);
    }
    
    public function offsetSet(int|string $offset, callable $callback, ?array $required_permissions = [], ?string $method = 'str_starts_with', ?string $description = ''): MessageHandler
    {
        return $this->messageHandler->offsetSet($offset, $callback, $required_permissions, $method, $description);
    }

    public function offsetExists(int|string $offset): bool
    {
        return $this->messageHandler->offsetExists($offset);
    }

    /*
     * The generated functions include `ping`, `help`, `cpu`, `approveme`, and `insult`.
     * The `ping` function replies with "Pong!" when called.
     * The `help` function generates a list of available commands based on the user's roles.
     * The `cpu` function returns the CPU usage of the system.
     * The `approveme` function verifies a user's identity and assigns them the `Verified` role.
     * And more! (see the code for more details)
     */
    private function __generateGlobalMessageCommands(): void
    {
        // MessageHandler
        $this->offsetSet('ping', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            return $this->civ13->reply($message, 'Pong!');
        }));

        $help = new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            return $this->civ13->reply($message, $this->generateHelp($message->member->roles), 'help.txt', true);
        });
        $this->offsetSet('help', $help);
        $this->offsetSet('commands', $help);

        $httphelp = new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            return $this->civ13->reply($message, $this->civ13->httpServiceManager->httpHandler->generateHelp(), 'httphelp.txt', true);
        });
        $this->offsetSet('httphelp', $httphelp, ['Owner', 'High Staff']);

        $this->offsetSet('cpu', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            if (PHP_OS_FAMILY == "Windows") {
                $load_array = explode(' ', trim(shell_exec('powershell -command "gwmi Win32_PerfFormattedData_PerfOS_Processor | select -ExpandProperty PercentProcessorTime"')));
                return $this->civ13->reply($message, "CPU Usage: {$load_array[0]}%");
            } else { // Linux
                $cpu_load = sys_getloadavg();
                $cpu_usage = $cpu_load ? array_sum($cpu_load) / count($cpu_load) : -1;
                return $this->civ13->reply($message, "CPU Usage: $cpu_usage%");
            }
            return $this->civ13->reply($message, 'Unrecognized operating system!');
        }), ['Verified']);
        $this->offsetSet('checkip', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $context = stream_context_create(['http' => ['connect_timeout' => 5]]);
            return $this->civ13->reply($message, @file_get_contents('http://ipecho.net/plain', false, $context));
        }), ['Verified']);
        /**
         * This method retrieves information about a ckey, including primary identifiers, IPs, CIDs, and dates.
         * It also iterates through playerlogs ban logs to find all known ckeys, IPs, and CIDs.
         * If the user has high staff privileges, it also displays primary IPs and CIDs.
         * @param Message $message The message object.
         * @param array $message_filtered The filtered message content.
         * @param string $command The command used to trigger this method.
         * @return PromiseInterface
         */
        $this->offsetSet('ckeyinfo', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $high_rank_check = function (Message $message, array $allowed_ranks = []): bool
            {
                $resolved_ranks = array_map(function ($rank) {
                    return isset($this->civ13->role_ids[$rank]) ? $this->civ13->role_ids[$rank] : null;
                }, $allowed_ranks);

                return count(array_filter($resolved_ranks, function ($rank) use ($message) {
                    return $message->member->roles->has($rank);
                })) > 0;
            };
            $high_staff = $high_rank_check($message, ['Owner', 'High Staff']);
            if (! $id = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Invalid format! Please use the format: ckeyinfo `ckey`');
            if (is_numeric($id)) {
                if (! $item = $this->civ13->verifier->getVerifiedItem($id)) return $this->civ13->reply($message, "No data found for Discord ID `$id`.");
                $ckey = $item['ss13'];
            } else $ckey = $id;
            if (! $collectionsArray = $this->civ13->getCkeyLogCollections($ckey)) return $this->civ13->reply($message, 'No data found for that ckey.');

            $embed = new Embed($this->discord);
            $embed->setTitle($ckey);
            if ($item = $this->civ13->verifier->getVerifiedItem($ckey)) {
                $ckey = $item['ss13'];
                if ($member = $this->civ13->verifier->getVerifiedMember($item))
                    $embed->setAuthor("{$member->user->username} ({$member->id})", $member->avatar);
            }
            $ckeys = [$ckey];
            $ips = [];
            $cids = [];
            $dates = [];
            // Get the ckey's primary identifiers
            foreach ($collectionsArray['playerlogs'] as $log) {
                if (isset($log['ip']) && ! in_array($log['ip'], $ips)) $ips[] = $log['ip'];
                if (isset($log['cid']) && ! in_array($log['cid'], $cids)) $cids[] = $log['cid'];
                if (isset($log['date']) && ! in_array($log['date'], $dates)) $dates[] = $log['date'];
            }
            foreach ($collectionsArray['bans'] as $log) {
                if (isset($log['ip']) && ! in_array($log['ip'], $ips)) $ips[] = $log['ip'];
                if (isset($log['cid']) && ! in_array($log['cid'], $cids)) $cids[] = $log['cid'];
                if (isset($log['date']) && ! in_array($log['date'], $dates)) $dates[] = $log['date'];
            }
            $ckey_age = [];
            if (! empty($ckeys)) {
                foreach ($ckeys as $c) ($age = $this->civ13->getByondAge($c)) ? $ckey_age[$c] = $age : $ckey_age[$c] = "N/A";
                $ckey_age_string = '';
                foreach ($ckey_age as $key => $value) $ckey_age_string .= " $key ($value) ";
                if ($ckey_age_string) $embed->addFieldValues('Primary Ckeys', trim($ckey_age_string));
            }
            if ($high_staff) {
                if (! empty($ips) && $ips) $embed->addFieldValues('Primary IPs', implode(', ', $ips), true);
                if (! empty($cids) && $cids) $embed->addFieldValues('Primary CIDs', implode(', ', $cids), true);
            }
            if (! empty($dates) && $dates) $embed->addFieldValues('Primary Dates', implode(', ', $dates));

            // Iterate through the playerlogs ban logs to find all known ckeys, ips, and cids
            $playerlogs = $this->civ13->playerlogsToCollection(); // This is ALL players
            $i = 0;
            $break = false;
            do { // Iterate through playerlogs to find all known ckeys, ips, and cids
                $found = false;
                $found_ckeys = [];
                $found_ips = [];
                $found_cids = [];
                $found_dates = [];
                foreach ($playerlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                    if (! in_array($log['ckey'], $ckeys)) { $found_ckeys[] = $log['ckey']; $found = true; }
                    if (! in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                    if (! in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
                    if (! in_array($log['date'], $dates)) { $found_dates[] = $log['date']; }
                }
                $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
                $ips = array_unique(array_merge($ips, $found_ips));
                $cids = array_unique(array_merge($cids, $found_cids));
                $dates = array_unique(array_merge($dates, $found_dates));
                if ($i > 10) $break = true;
                $i++;
            } while ($found && ! $break); // Keep iterating until no new ckeys, ips, or cids are found

            $banlogs = $this->civ13->bansToCollection();
            $this->civ13->bancheck($ckey)
                ? $banned = 'Yes'
                : $banned = 'No';
            $found = true;
            $i = 0;
            $break = false;
            do { // Iterate through playerlogs to find all known ckeys, ips, and cids
                $found = false;
                $found_ckeys = [];
                $found_ips = [];
                $found_cids = [];
                $found_dates = [];
                foreach ($banlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                    if (! in_array($log['ckey'], $ips)) { $found_ckeys[] = $log['ckey']; $found = true; }
                    if (! in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                    if (! in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
                    if (! in_array($log['date'], $dates)) { $found_dates[] = $log['date']; }
                }
                $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
                $ips = array_unique(array_merge($ips, $found_ips));
                $cids = array_unique(array_merge($cids, $found_cids));
                $dates = array_unique(array_merge($dates, $found_dates));
                if ($i > 10) $break = true;
                $i++;
            } while ($found && ! $break); // Keep iterating until no new ckeys, ips, or cids are found
            $altbanned = 'No';
            foreach ($ckeys as $key) if ($key != $ckey) if ($this->civ13->bancheck($key)) { $altbanned = 'Yes'; break; }

            $verified = 'No';
            if ($this->civ13->verifier->get('ss13', $ckey)) $verified = 'Yes';
            if (! empty($ckeys) && $ckeys) {
                foreach ($ckeys as $c) if (! isset($ckey_age[$c])) ($age = $this->civ13->getByondAge($c)) ? $ckey_age[$c] = $age : $ckey_age[$c] = "N/A";
                $ckey_age_string = '';
                foreach ($ckey_age as $key => $value) $ckey_age_string .= "$key ($value) ";
                if ($ckey_age_string) $embed->addFieldValues('Matched Ckeys', trim($ckey_age_string));
            }
            if ($high_staff) {
                if (! empty($ips) && $ips) $embed->addFieldValues('Matched IPs', implode(', ', $ips), true);
                if (! empty($cids) && $cids) $embed->addFieldValues('Matched CIDs', implode(', ', $cids), true);
            }
            if (! empty($ips) && $ips) {
                $regions = [];
                foreach ($ips as $ip) if (! in_array($region = $this->civ13->IP2Country($ip), $regions)) $regions[] = $region;
                if ($regions) $embed->addFieldValues('Regions', implode(', ', $regions));
            }
            if (! empty($dates) && $dates && strlen($dates_string = implode(', ', $dates)) <= 1024) $embed->addFieldValues('Dates', $dates_string);
            if ($verified) $embed->addfieldValues('Verified', $verified, true);
            $discords = [];
            if ($ckeys) foreach ($ckeys as $c) if ($item = $this->civ13->verifier->get('ss13', $c)) $discords[] = $item['discord'];
            if ($discords) {
                foreach ($discords as &$id) $id = "<@{$id}>";
                $embed->addfieldValues('Discord', implode(', ', $discords));
            }
            if ($banned) $embed->addfieldValues('Currently Banned', $banned, true);
            if ($altbanned) $embed->addfieldValues('Alt Banned', $altbanned, true);
            $embed->addfieldValues('Ignoring banned alts or new account age', isset($this->civ13->permitted[$ckey]) ? 'Yes' : 'No', true);
            $builder = MessageBuilder::new();
            if (! $high_staff) $builder->setContent('IPs and CIDs have been hidden for privacy reasons.');
            $builder->addEmbed($embed);
            return $message->reply($builder);
        }), ['Owner', 'High Staff', 'Admin']);
        
        if (isset($this->civ13->role_ids['Verified']))
        $approveme = new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            if (isset($this->civ13->role_ids['Verified']) && $message->member->roles->has($this->civ13->role_ids['Verified'])) return $this->civ13->reply($message, 'You already have the verification role!');
            if ($item = $this->civ13->verifier->getVerifiedItem($message->author)) {
                $message->member->setRoles([$this->civ13->role_ids['Verified']], "approveme {$item['ss13']}");
                return $message->react("👍");
            }
            if (! $ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Invalid format! Please use the format `approveme ckey`');
            return $this->civ13->reply($message, $this->civ13->verifier->process($ckey, $message->user_id, $message->member));
        });
        $this->offsetSet('approveme', $approveme);
        $this->offsetSet('aproveme', $approveme);
        $this->offsetSet('approvme', $approveme);

        if (file_exists(Civ13::insults_path))
        $this->offsetSet('insult', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $split_message = explode(' ', $message_filtered['message_content']); // $split_target[1] is the target
            if (count($split_message) <= 1 || strlen($split_message[1]) === 0) $split_message[1] = "<@{$message->user_id}>";
            if (! empty($insults_array = file(Civ13::insults_path, FILE_IGNORE_NEW_LINES))) {
                $random_insult = $insults_array[array_rand($insults_array)];
                return $message->channel->sendMessage(MessageBuilder::new()->setContent($split_message[1] . ', ' . $random_insult)->setAllowedMentions(['parse' => []]));
            }
            return $this->civ13->reply($message, 'No insults found!');
        }), ['Verified']);

        $this->offsetSet('discord2ckey', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) {
            if (! $item = $this->civ13->verifier->get('discord', $id = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->civ13->reply($message, "`$id` is not registered to any byond username");
            return $this->civ13->reply($message, "`$id` is registered to `{$item['ss13']}`");
        }), ['Verified']);

        $this->offsetSet('ckey2discord', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) {
            if (! $item = $this->civ13->verifier->get('ss13', $ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->civ13->reply($message, "`$ckey` is not registered to any discord id");
            return $this->civ13->reply($message, "`$ckey` is registered to <@{$item['discord']}>");
        }), ['Verified']);

        $this->offsetSet('ckey', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            //if (str_starts_with($message_filtered['message_content_lower'], 'ckeyinfo')) return null; // This shouldn't happen, but just in case...
            if (! $ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) {
                if (! $item = $this->civ13->verifier->getVerifiedItem($ckey = $message->user_id)) return $this->civ13->reply($message, "You are not registered to any byond username");
                return $this->civ13->reply($message, "You are registered to `{$item['ss13']}`");
            }
            if (is_numeric($ckey)) {
                if (! $item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "`$ckey` is not registered to any ckey");
                if (! $age = $this->civ13->getByondAge($item['ss13'])) return $this->civ13->reply($message, "`{$item['ss13']}` does not exist");
                return $this->civ13->reply($message, "`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
            }
            if (! $age = $this->civ13->getByondAge($ckey)) return $this->civ13->reply($message, "`$ckey` does not exist");
            if ($item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
            return $this->civ13->reply($message, "`$ckey` is not registered to any discord id ($age)");
        }), ['Verified']);

        $this->offsetSet('fullbancheck', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            foreach ($message->guild->members as $member)
                if ($item = $this->civ13->verifier->getVerifiedItem($member))
                    $this->civ13->bancheck($item['ss13']);
            return $message->react("👍");
        }), ['Owner', 'High Staff']);
        
        
        $this->offsetSet('playerlist', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): ?PromiseInterface
        { // This function is only authorized to be used by the database administrator
            if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
            $playerlist = [];
            foreach ($this->civ13->enabled_gameservers as &$gameserver) $playerlist = array_unique(array_merge($playerlist, $gameserver->players));
            if ($playerlist) return $this->civ13->reply($message, implode(', ', $playerlist));
            return $this->civ13->reply($message, 'No players found.');
        }), ['Chief Technical Officer']);

        $this->offsetSet('unvet', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): ?PromiseInterface
        { // Adds the infantry role to all veterans
            if (! isset($this->civ13->role_ids['veteran']) || ! isset($this->civ13->role_ids['Verified'])) return $message->react("❌");
            if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
            $members = [];
            foreach ($message->guild->members as $member) if ($member->roles->has($this->civ13->role_ids['veteran']) && ! $member->roles->has($this->civ13->role_ids['Verified'])) $members[] = $member;
            if (! $members) $message->react("👎");
            $message->react("⏱️");

            $func = function (Member $member): PromiseInterface
            {
                return $member->addRole($this->civ13->role_ids['Verified']);
            };
            $promise = array_shift($members)->addRole($this->civ13->role_ids['Verified']);
            foreach ($members as $member) $promise = $promise->then(function () use ($member, $func) {
                return $func($member);
            });
            $promise->then(function () use ($message) {
                return $message->react("👍");
            });
        }), ['Chief Technical Officer']);

        $this->offsetSet('retryregister', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): ?PromiseInterface
        { // This function is only authorized to be used by the database administrator
            if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
            foreach ($this->civ13->verifier->provisional as $ckey => $discord_id) $this->civ13->verifier->provisionalRegistration($ckey, $discord_id); // Attempt to register all provisional users
            return $this->civ13->reply($message, 'Attempting to register all provisional users.');
        }), ['Chief Technical Officer']);
        
        
        $this->offsetSet('register', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): ?PromiseInterface
        { // This function is only authorized to be used by the database administrator
            if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
            $split_message = explode(';', trim(substr($message_filtered['message_content_lower'], strlen($command))));
            if (! $ckey = $this->civ13->sanitizeInput($split_message[0])) return $this->civ13->reply($message, 'Byond username was not passed. Please use the format `register <byond username>; <discord id>`.');
            if (! is_numeric($discord_id = $this->civ13->sanitizeInput($split_message[1]))) return $this->civ13->reply($message, "Discord id `$discord_id` must be numeric.");
            return $this->civ13->reply($message, $this->civ13->verifier->register($ckey, $discord_id)['error']);
        }), ['Chief Technical Officer']);

        $this->offsetSet('unverify', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): ?PromiseInterface
        { // This function is only authorized to be used by the database administrator
            if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
            $split_message = explode(';', trim(substr($message_filtered['message_content_lower'], strlen($command))));
            if (! $id = $this->civ13->sanitizeInput($split_message[0])) return $this->civ13->reply($message, 'Byond username or Discord ID was not passed. Please use the format `register <byond username>; <discord id>`.');
            return $this->civ13->reply($message, $this->civ13->verifier->unverify($id)['message']);
        }), ['Chief Technical Officer']);

        $this->offsetSet('discard', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): ?PromiseInterface
        {
            if (! $ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Byond username was not passed. Please use the format `discard <byond username>`.');
            $string = "`$ckey` will no longer attempt to be automatically registered.";
            if (isset($this->civ13->verifier->provisional[$ckey])) {
                if ($member = $message->guild->members->get($this->civ13->verifier->provisional[$ckey])) {
                    $member->removeRole($this->civ13->role_ids['Verified']);
                    $string .= " The <@&{$this->civ13->role_ids['Verified']}> role has been removed from $member.";
                }
                unset($this->civ13->verifier->provisional[$ckey]);
                $this->civ13->VarSave('provisional.json', $this->civ13->verifier->provisional);
            }
            return $this->civ13->reply($message, $string);
        }), ['Owner', 'High Staff', 'Admin']);
        
        if (isset($this->civ13->role_ids['paroled'], $this->civ13->channel_ids['parole_logs'])) {
            $release = new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): ?PromiseInterface
            {
                if (! $item = $this->civ13->verifier->getVerifiedItem($id = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->civ13->reply($message, "<@{$id}> is not currently verified with a byond username or it does not exist in the cache yet");
                $this->civ13->paroleCkey($ckey = $item['ss13'], $message->user_id, false);
                $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'];
                if ($member = $this->civ13->verifier->getVerifiedMember($item))
                    if ($member->roles->has($this->civ13->role_ids['paroled']))
                        $member->removeRole($this->civ13->role_ids['paroled'], "`$admin` ({$message->member->displayname}) released `$ckey`");
                if ($channel = $this->discord->getChannel($this->civ13->channel_ids['parole_logs'])) $this->civ13->sendMessage($channel, "`$ckey` (<@{$item['discord']}>) has been released from parole by `$admin` (<@{$message->user_id}>).");
                return $message->react("👍");
            });
            $this->offsetSet('release', ($release), ['Owner', 'High Staff', 'Admin']);
        }

        $this->offsetSet('tests', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $tokens = explode(' ', trim(substr($message_filtered['message_content'], strlen($command))));
            if (empty($tokens[0])) {
                if (empty($this->civ13->tests)) return $this->civ13->reply($message, "No tests have been created yet! Try creating one with `tests add {test_key} {question}`");
                $reply = 'Available tests: `' . implode('`, `', array_keys($this->civ13->tests)) . '`';
                $reply .= PHP_EOL . 'Available commands: `list {test_key}`, `add {test_key} {question}`, `post {test_key} {question #}`, `remove {test_key} {question #}` `delete {test_key}`';
                return $this->civ13->reply($message, $reply);
            }
            if (! isset($tokens[1])) return $this->civ13->reply($message, 'Invalid format! You must include the name of the test, e.g. `tests list {test_key}.');
            if (! isset($this->civ13->tests[$test_key = strtolower($tokens[1])]) && $tokens[0] !== 'add') return $this->civ13->reply($message, "Test `$test_key` hasn't been created yet! Please add a question first.");
            switch ($tokens[0]) {
                case 'list':
                    return $message->reply(MessageBuilder::new()->addFileFromContent("$test_key.txt", var_export($this->civ13->tests[$test_key], true))->setContent('Number of questions: ' . count(array_keys($this->civ13->tests[$test_key]))));
                case 'delete':
                    if (isset($tokens[2])) return $this->civ13->reply($message, "Invalid format! Please use the format `tests delete {test_key}`"); // Prevents accidental deletion of tests
                    unset($this->civ13->tests[$test_key]);
                    $this->civ13->VarSave('tests.json', $this->civ13->tests);
                    return $this->civ13->reply($message, "Deleted test `$test_key`");
                case 'add':
                    if (! $question = implode(' ', array_slice($tokens, 2))) return $this->civ13->reply($message, 'Invalid format! Please use the format `tests add {test_key} {question}`');
                    $this->civ13->tests[$test_key][] = $question;
                    $this->civ13->VarSave('tests.json', $this->civ13->tests);
                    return $this->civ13->reply($message, "Added question to test `$test_key`: `$question`");
                case 'remove':
                    if (!isset($tokens[2]) || !is_numeric($tokens[2])) return $this->civ13->reply($message, "Invalid format! Please use the format `tests remove {test_key} {question #}`");
                    if (!isset($this->civ13->tests[$test_key][$tokens[2]])) return $this->civ13->reply($message, "Question not found in test `$test_key`! Please use the format `tests {test_key} remove {question #}`");
                    $question = $this->civ13->tests[$test_key][$tokens[2]];
                    unset($this->civ13->tests[$test_key][$tokens[2]]);
                    $this->civ13->VarSave('tests.json', $this->civ13->tests);
                    return $this->civ13->reply($message, "Removed question `{$tokens[2]}`: `$question`");
                case 'post':
                    if (!isset($tokens[2]) || !is_numeric($tokens[2])) return $this->civ13->reply($message, "Invalid format! Please use the format `tests post {test_key} {# of questions}`");
                    if (count($this->civ13->tests[$test_key]) < $tokens[2]) return $this->civ13->reply($message, "Can't return more questions than exist in a test!");
                    $test = $this->civ13->tests[$test_key]; // Copy the array, don't reference it
                    shuffle($test);
                    return $this->civ13->reply($message, implode(PHP_EOL, array_slice($test, 0, $tokens[2])));
                default:
                    return $this->civ13->reply($message, 'Invalid format! Available commands: `list {test_key}`, `add {test_key} {question}`, `post {test_key} {question #}`, `remove {test_key} {question #}` `delete {test_key}`');
            }
        }), ['Owner', 'High Staff']);

        if (isset($this->civ13->functions['misc']['promotable_check']) && $promotable_check = $this->civ13->functions['misc']['promotable_check']) {
            $promotable = new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use ($promotable_check): PromiseInterface
            {
                if (! $promotable_check($this->civ13, $this->civ13->sanitizeInput(substr($message_filtered['message_content'], strlen($command))))) return $message->react("👎");
                return $message->react("👍");
            });
            $this->offsetSet('promotable', $promotable, ['Owner', 'High Staff']);
        }

        if (isset($this->civ13->functions['misc']['mass_promotion_loop']) && $mass_promotion_loop = $this->civ13->functions['misc']['mass_promotion_loop'])
        $this->offsetSet('mass_promotion_loop', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use ($mass_promotion_loop): PromiseInterface
        {
            if (! $mass_promotion_loop($this->civ13)) return $message->react("👎");
            return $message->react("👍");
        }), ['Owner', 'High Staff']);

        if (isset($this->civ13->functions['misc']['mass_promotion_check']) && $mass_promotion_check = $this->civ13->functions['misc']['mass_promotion_check'])
        $this->offsetSet('mass_promotion_check', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use ($mass_promotion_check): PromiseInterface
        {
            if ($promotables = $mass_promotion_check($this->civ13)) return $message->reply(MessageBuilder::new()->addFileFromContent('promotables.txt', json_encode($promotables)));
            return $message->react("👎");
        }), ['Owner', 'High Staff']);
        //


        $this->offsetSet('ooc', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): ?PromiseInterface
        {
            $message_filtered['message_content'] = trim(substr($message_filtered['message_content'], trim(strlen($command))));
            foreach ($this->civ13->enabled_gameservers as &$gameserver) switch (strtolower($message->channel->name)) {
                case "ooc-{$gameserver->key}":                    
                    if ($gameserver->OOCMessage($message_filtered['message_content'], $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username)) return $message->react("📧");
                    return $message->react("🔥");
            }
            return $this->civ13->reply($message, 'You need to be in any of the #ooc channels to use this command.');
        }), ['Verified']);

        $this->offsetSet('asay', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $message_filtered['message_content'] = trim(substr($message_filtered['message_content'], trim(strlen($command))));
            foreach ($this->civ13->enabled_gameservers as $server) {
                switch (strtolower($message->channel->name)) {
                    case "asay-{$server->key}":
                        if ($this->civ13->AdminMessage($message_filtered['message_content'], $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username, $server->key)) return $message->react("📧");
                        return $message->react("🔥");
                }
            }
            return $this->civ13->reply($message, 'You need to be in any of the #asay channels to use this command.');
        }), ['Verified']);

        $this->offsetSet('globalooc', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): ?PromiseInterface
        {
            $message_filtered['message_content'] = trim(substr($message_filtered['message_content'], trim(strlen($command))));
            if ($this->civ13->OOCMessage($message_filtered['message_content'], $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username)) return $message->react("📧");
            return $message->react("🔥");
        }), ['Owner', 'High Staff', 'Admin']);

        $this->offsetSet('globalasay', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): ?PromiseInterface
        {
            $message_filtered['message_content'] = trim(substr($message_filtered['message_content'], trim(strlen($command))));
            if ($this->civ13->AdminMessage($message_filtered['message_content'], $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username)) return $message->react("📧");
            return $message->react("🔥");
        }), ['Owner', 'High Staff', 'Admin']);

        $directmessage = new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            if (! str_contains($message_filtered['message_content'], ';')) return $this->civ13->reply($message, 'Invalid format! Please use the format `dm [ckey]; [message]`.');
            $explode = explode(';', $message_filtered['message_content']);
            $recipient = $this->civ13->sanitizeInput(substr(array_shift($explode), strlen($command)));
            $msg = implode(' ', $explode);
            foreach ($this->civ13->enabled_gameservers as $server) {
                switch (strtolower($message->channel->name)) {
                    case "asay-{$server->key}":
                    case "ic-{$server->key}":
                    case "ooc-{$server->key}":
                        if ($this->civ13->DirectMessage($msg, $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username, $recipient, $server->key)) return $message->react("📧");
                        return $message->react("🔥");
                }
            }
            return $this->civ13->reply($message, 'You need to be in any of the #ic, #asay, or #ooc channels to use this command.');
        });
        $this->offsetSet('dm', $directmessage, ['Owner', 'High Staff', 'Admin', 'Moderator']);
        $this->offsetSet('pm', $directmessage, ['Owner', 'High Staff', 'Admin', 'Moderator']);

        $this->offsetSet('bancheck_centcom', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            if (! $ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `bancheck [ckey]`.');
            if (is_numeric($ckey)) {
                if (! $item = $this->civ13->verifier->get('discord', $ckey)) return $this->civ13->reply($message, "No ckey found for Discord ID `$ckey`.");
                $ckey = $item['ss13'];
            }
            if (! $json = Byond::bansearch_centcom($ckey)) return $this->civ13->reply($message, "Unable to locate bans for **$ckey** on centcom.melonmesa.com.");
            if ($json === '[]') return $this->civ13->reply($message, "No bans were found for **$ckey** on centcom.melonmesa.com.");
            return $this->civ13->reply($message, $json, $ckey.'_bans.json', true);
        }), ['Verified']);
        $this->offsetSet('bancheck', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) {
            if (! $ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `bancheck [ckey]`.');
            if (is_numeric($ckey)) {
                if (! $item = $this->civ13->verifier->get('discord', $ckey)) return $this->civ13->reply($message, "No ckey found for Discord ID `$ckey`.");
                $ckey = $item['ss13'];
            }
            $reason = 'unknown';
            $found = false;
            $content = '';
            foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                if (! touch ($gameserver->basedir . Civ13::bans) || ! $file = @fopen($gameserver->basedir . Civ13::bans, 'r')) {
                    $this->logger->warning('Could not open `' . $gameserver->basedir . Civ13::bans . "` for reading.");
                    return $message->react("🔥");
                }
                while (($fp = fgets($file, 4096)) !== false) {
                    $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
                    if ((count($linesplit)>=8) && ($linesplit[8] === strtolower($ckey))) {
                        $found = true;
                        $type = $linesplit[0];
                        $reason = $linesplit[3];
                        $admin = $linesplit[4];
                        $date = $linesplit[5];
                        $content .= "**$ckey** has been **$type** banned from **{$gameserver->name}** on **$date** for **$reason** by $admin." . PHP_EOL;
                    }
                }
                fclose($file);
            }
            if (! $found) $content .= "No bans were found for **$ckey**." . PHP_EOL;
            elseif (isset($this->civ13->role_ids['banished']) && $member = $this->civ13->verifier->getVerifiedMember($ckey))
                if (! $member->roles->has($this->civ13->role_ids['banished']))
                    $member->addRole($this->civ13->role_ids['banished']);
            return $this->civ13->reply($message, $content, 'bancheck.txt');
        }));
        
        /**
         * Changes the relay method between 'file' and 'webhook' and sends a message to confirm the change.
         *
         * @param Message $message The message object received from the user.
         * @param array $message_filtered An array of filtered message content.
         * @param string $command The command string.
         *
         * @return PromiseInterface
         */
        $this->offsetSet('ckeyrelayinfo', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $this->civ13->relay_method === 'file'
                ? $method = 'webhook'
                : $method = 'file';
            $this->civ13->relay_method = $method;
            return $this->civ13->reply($message, "Relay method changed to `$method`.");
        }), ['Owner', 'High Staff']);    

        $this->offsetSet('fullaltcheck', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $ckeys = [];
            $members = $message->guild->members->filter(function (Member $member) { return ! $member->roles->has($this->civ13->role_ids['banished']); });
            foreach ($members as $member)
                if ($item = $this->civ13->verifier->getVerifiedItem($member->id)) {
                    $ckeyinfo = $this->civ13->ckeyinfo($item['ss13']);
                    if (count($ckeyinfo['ckeys']) > 1)
                        $ckeys = array_unique(array_merge($ckeys, $ckeyinfo['ckeys']));
                }
            if ($ckeys) {
                $builder = MessageBuilder::new();
                $builder->addFileFromContent('alts.txt', '`'.implode('`' . PHP_EOL . '`', $ckeys));
                $builder->setContent('The following ckeys are alt accounts of unbanned verified players.');
                return $message->reply($builder);
            }
            return $this->civ13->reply($message, 'No alts found.');
        }), ['Owner', 'High Staff']);

        $this->offsetSet('permitted', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            if (empty($this->civ13->permitted)) return $this->civ13->reply($message, 'No users have been permitted to bypass the Byond account restrictions.');
            return $this->civ13->reply($message, 'The following ckeys are now permitted to bypass the Byond account limit and restrictions: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', array_keys($this->civ13->permitted)) . '`');
        }), ['Owner', 'High Staff', 'Admin'], 'exact');

        $this->offsetSet('permit', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $this->civ13->permitCkey($ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))));
            return $this->civ13->reply($message, "$ckey is now permitted to bypass the Byond account restrictions.");
        }), ['Owner', 'High Staff', 'Admin']);

        $revoke = new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $this->civ13->permitCkey($ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))), false);
            return $this->civ13->reply($message, "$ckey is no longer permitted to bypass the Byond account restrictions.");
        });
        $this->offsetSet('revoke', $revoke, ['Owner', 'High Staff', 'Admin']);
        $this->offsetSet('unpermit', $revoke, ['Owner', 'High Staff', 'Admin']); // Alias for revoke
        
        if (isset($this->civ13->role_ids['paroled'], $this->civ13->channel_ids['parole_logs'])) {
            $this->offsetSet('parole', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
            {
                if (! $item = $this->civ13->verifier->getVerifiedItem($id = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->civ13->reply($message, "<@{$id}> is not currently verified with a byond username or it does not exist in the cache yet");
                $this->civ13->paroleCkey($ckey = $item['ss13'], $message->user_id, true);
                $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'];
                if ($member = $this->civ13->verifier->getVerifiedMember($item))
                    if (! $member->roles->has($this->civ13->role_ids['paroled']))
                        $member->addRole($this->civ13->role_ids['paroled'], "`$admin` ({$message->member->displayname}) paroled `$ckey`");
                if ($channel = $this->discord->getChannel($this->civ13->channel_ids['parole_logs'])) $this->civ13->sendMessage($channel, "`$ckey` (<@{$item['discord']}>) has been placed on parole by `$admin` (<@{$message->user_id}>).");
                return $message->react("👍");
            }), ['Owner', 'High Staff', 'Admin']);
        }

        $this->offsetSet('refresh', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            if ($this->civ13->verifier->getVerified(false)) return $message->react("👍");
            return $message->react("👎");
        }), ['Owner', 'High Staff', 'Admin']);
        
        $this->offsetSet('listbans', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            return $this->civ13->listbans($message, trim(substr($message_filtered['message_content_lower'], strlen($command))));
        }), ['Owner', 'High Staff', 'Admin']);

        $this->offsetSet('softban', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $this->civ13->softban($id = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))));
            return $this->civ13->reply($message, "`$id` is no longer allowed to get verified.");
        }), ['Owner', 'High Staff', 'Admin']);

        $this->offsetSet('unsoftban', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $this->civ13->softban($id = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))), false);
            return $this->civ13->reply($message, "`$id` is allowed to get verified again.");
        }), ['Owner', 'High Staff', 'Admin']);
        
        $this->offsetSet('ban', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $message_filtered['message_content'] = substr($message_filtered['message_content'], trim(strlen($command)));
            $split_message = explode('; ', $message_filtered['message_content']);
            if (! $split_message[0] = $this->civ13->sanitizeInput($split_message[0])) return $this->civ13->reply($message, 'Missing ban ckey! Please use the format `ban ckey; duration; reason`');
            if (! isset($split_message[1]) || ! $split_message[1]) return $this->civ13->reply($message, 'Missing ban duration! Please use the format `ban ckey; duration; reason`');
            if (! isset($split_message[2]) || ! $split_message[2]) return $this->civ13->reply($message, 'Missing ban reason! Please use the format `ban ckey; duration; reason`');
            $arr = ['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->civ13->discord_formatted}"];
            return $this->civ13->reply($message, $this->civ13->ban($arr, $this->civ13->verifier->getVerifiedItem($message->author)['ss13']));
        }), ['Owner', 'High Staff', 'Admin']);
        
        $this->offsetSet('unban', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            if (is_numeric($ckey = $this->civ13->sanitizeInput($message_filtered['message_content_lower'] = substr($message_filtered['message_content_lower'], trim(strlen($command))))))
                if (! $item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "No data found for Discord ID `$ckey`.");
                else $ckey = $item['ss13'];
            $this->civ13->unban($ckey, $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13']);
            return $this->civ13->reply($message, "**$admin** unbanned **$ckey**");
        }), ['Owner', 'High Staff', 'Admin']);

        if (isset($this->civ13->files['map_defines_path']) && file_exists($this->civ13->files['map_defines_path']))
        $this->offsetSet('maplist', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            if (! $file_contents = @file_get_contents($this->civ13->files['map_defines_path'])) return $message->react("🔥");
            return $message->reply(MessageBuilder::new()->addFileFromContent('maps.txt', $file_contents));
        }), ['Owner', 'High Staff', 'Admin']);

        $this->offsetSet('adminlist', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {            
            $builder = MessageBuilder::new();
            $found = false;
            foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                if (! file_exists($path = $gameserver->basedir . Civ13::admins) || ! $file_contents = @file_get_contents($path)) {
                    $this->logger->debug("`$path` is not a valid file path!");
                    continue;
                }
                $builder->addFileFromContent($path, $file_contents);
                $found = true;
            }
            if (! $found) return $message->react("🔥");
            return $message->reply($builder);
        }), ['Owner', 'High Staff', 'Admin']);

        $this->offsetSet('factionlist', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {            
            $builder = MessageBuilder::new()->setContent('Faction Lists');
            foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                if (file_exists($path = $gameserver->basedir . Civ13::factionlist)) $builder->addfile($path, $gameserver->key . '_factionlist.txt');
                else $this->logger->warning("`$path is not a valid file path!");
            }
            return $message->reply($builder);
        }), ['Owner', 'High Staff', 'Admin']);

        if (isset($this->civ13->files['tdm_sportsteams']) && file_exists($this->civ13->files['tdm_sportsteams']))
        $this->offsetSet('sportsteams', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {   
            $builder = MessageBuilder::new()->setContent('Sports Teams');      
            foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                if (file_exists($path = $gameserver->basedir . Civ13::sportsteams)) $builder->addfile($path, $gameserver->key . '_sports_teams.txt');
                else $this->logger->warning("`$path is not a valid file path!");
            }
            return $message->reply($builder);
        }), ['Owner', 'High Staff', 'Admin']);

        $log_handler = function (Message $message, string $message_content): PromiseInterface
        {
            $tokens = explode(';', $message_content);
            $keys = [];
            foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                $keys[] = $gameserver->key;
                if (trim($tokens[0]) !== $gameserver->key) continue; // Check if server is valid
                if (! isset($gameserver->basedir) || ! file_exists($gameserver->basedir . Civ13::log_basedir)) {
                    $this->logger->warning("Either basedir or `" . Civ13::log_basedir . "` is not defined or does not exist");
                    return $message->react("🔥");
                }

                unset($tokens[0]);
                $results = $this->civ13->FileNav($gameserver->basedir . Civ13::log_basedir, $tokens);
                if ($results[0]) return $message->reply(MessageBuilder::new()->addFile($results[1], 'log.txt'));
                if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
                if (! isset($results[2]) || ! $results[2]) return $this->civ13->reply($message, 'Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
                return $this->civ13->reply($message, "{$results[2]} is not an available option! Available options: " . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
            }
            return $this->civ13->reply($message, 'Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys) . '`');
        };

        $this->offsetSet('logs', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use ($log_handler): PromiseInterface
        {
            return $log_handler($message, trim(substr($message_filtered['message_content'], strlen($command))));
        }), ['Owner', 'High Staff', 'Admin']);

        $this->offsetSet('playerlogs', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $tokens = explode(';', trim(substr($message_filtered['message_content'], strlen($command))));
            $keys = [];
            foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                $keys[] = $gameserver->key;
                if (trim($tokens[0]) !== $gameserver->key) continue;
                if (! isset($gameserver->basedir) || ! file_exists($gameserver->basedir . Civ13::playerlogs) || ! $file_contents = @file_get_contents($gameserver->basedir . Civ13::playerlogs)) return $message->react("🔥");
                return $message->reply(MessageBuilder::new()->addFileFromContent('playerlogs.txt', $file_contents));
            }
            return $this->civ13->reply($message, 'Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys). '`' );
        }), ['Owner', 'High Staff', 'Admin']);

        $this->offsetSet('stop', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered)//: PromiseInterface
        {
            $promise = $message->react("🛑");
            $promise->then(function () { $this->civ13->stop(); });
            //return $promise; // Pending PromiseInterfaces v3
            return null;
        }), ['Owner', 'High Staff']);

        if (isset($this->civ13->folders['typespess_path'], $this->civ13->files['typespess_launch_server_path']))
        $this->offsetSet('ts', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            if (! $state = trim(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `ts on` or `ts off`.');
            if (! in_array($state, ['on', 'off'])) return $this->civ13->reply($message, 'Wrong format. Please try `ts on` or `ts off`.');
            if ($state === 'on') {
                \execInBackground("cd {$this->civ13->folders['typespess_path']}");
                \execInBackground('git pull');
                \execInBackground("sh {$this->civ13->files['typespess_launch_server_path']}&");
                return $this->civ13->reply($message, 'Put **TypeSpess Civ13** test server on: http://civ13.com/ts');
            } else {
                \execInBackground('killall index.js');
                return $this->civ13->reply($message, '**TypeSpess Civ13** test server down.');
            }
        }), ['Owner']);

        
        foreach ($this->civ13->enabled_gameservers as &$gameserver) {
            if (! file_exists($path = $gameserver->basedir . Civ13::ranking_path) || ! @touch($path)) continue;
            $this->offsetSet($gameserver->key.'ranking', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use ($path): PromiseInterface
            {
                if (! $this->civ13->recalculateRanking()) return $this->civ13->reply($message, 'There was an error trying to recalculate ranking! The bot may be misconfigured.');
                if (! $msg = $this->civ13->getRanking($path)) return $this->civ13->reply($message, 'There was an error trying to recalculate ranking!');
                return $this->civ13->reply($message, $msg, 'ranking.txt');
            }), ['Verified']);

            $this->offsetSet($gameserver->key.'rank', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use ($path): PromiseInterface
            {
                if (! $ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) {
                    if (! $item = $this->civ13->verifier->getVerifiedItem($message->author)) return $this->civ13->reply($message, 'Wrong format. Please try `rankme [ckey]`.');
                    $ckey = $item['ss13'];
                }
                if (! $this->civ13->recalculateRanking()) return $this->civ13->reply($message, 'There was an error trying to recalculate ranking! The bot may be misconfigured.');
                if (! $msg = $this->civ13->getRank($path, $ckey)) return $this->civ13->reply($message, 'There was an error trying to get your ranking!');
                return $this->civ13->sendMessage($message->channel, $msg, 'rank.txt');
                // return $this->civ13->reply($message, "Your ranking is too long to display.");
            }), ['Verified']);
        };
        
        if (isset($this->civ13->files['tdm_awards_path']) && file_exists($this->civ13->files['tdm_awards_path'])) {
            $medals = function (string $ckey): false|string
            {
                $result = '';
                if (! $search = @fopen($this->civ13->files['tdm_awards_path'], 'r')) return false;
                $found = false;
                while (! feof($search)) if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {  # remove '\n' at end of line
                    $found = true;
                    $duser = explode(';', $line);
                    if ($duser[0] === $ckey) {
                        switch ($duser[2]) {
                            case 'long service medal': $medal_s = '<:long_service:705786458874707978>'; break;
                            case 'combat medical badge': $medal_s = '<:combat_medical_badge:706583430141444126>'; break;
                            case 'tank destroyer silver badge': $medal_s = '<:tank_silver:705786458882965504>'; break;
                            case 'tank destroyer gold badge': $medal_s = '<:tank_gold:705787308926042112>'; break;
                            case 'assault badge': $medal_s = '<:assault:705786458581106772>'; break;
                            case 'wounded badge': $medal_s = '<:wounded:705786458677706904>'; break;
                            case 'wounded silver badge': $medal_s = '<:wounded_silver:705786458916651068>'; break;
                            case 'wounded gold badge': $medal_s = '<:wounded_gold:705786458845216848>'; break;
                            case 'iron cross 1st class': $medal_s = '<:iron_cross1:705786458572587109>'; break;
                            case 'iron cross 2nd class': $medal_s = '<:iron_cross2:705786458849673267>'; break;
                            default:  $medal_s = '<:long_service:705786458874707978>';
                        }
                        $result .= "**{$duser[1]}:** {$medal_s} **{$duser[2]}**, *{$duser[4]}*, {$duser[5]}" . PHP_EOL;
                    }
                }
                if ($result != '') return $result;
                if (! $found && ($result === '')) return 'No medals found for this ckey.';
            };
            $this->offsetSet('medals', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use ($medals): PromiseInterface
            {
                if (! $ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `medals [ckey]`.');
                if (! $msg = $medals($ckey)) return $this->civ13->reply($message, 'There was an error trying to get your medals!');
                return $this->civ13->reply($message, $msg, 'medals.txt');
            }), ['Verified']);
        }
        if (isset($this->civ13->files['tdm_awards_br_path']) && file_exists($this->civ13->files['tdm_awards_br_path'])) {
            $brmedals = function (string $ckey): string
            {
                $result = '';
                if (! $search = @fopen($this->civ13->files['tdm_awards_br_path'], 'r')) return "Error opening {$this->civ13->files['tdm_awards_br_path']}.";
                $found = false;
                while (! feof($search)) if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {
                    $found = true;
                    $duser = explode(';', $line);
                    if ($duser[0] === $ckey) $result .= "**{$duser[1]}:** placed *{$duser[2]} of {$duser[5]},* on {$duser[4]} ({$duser[3]})" . PHP_EOL;
                }
                if (! $found) return 'No medals found for this ckey.';
                return $result;
            };
            $this->offsetSet('brmedals', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use ($brmedals): PromiseInterface
            {
                if (! $ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `brmedals [ckey]`.');
                if (! $msg = $brmedals($ckey)) return $this->civ13->reply($message, 'There was an error trying to get your medals!');
                return $this->civ13->reply($message, $msg, 'brmedals.txt');
                // return $this->civ13->reply($message, "Too many medals to display.");
            }), ['Verified']);
        }

        $this->offsetSet('dumpappcommands', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $application_commands = $this->civ13->discord->__get('application_commands');
            $names = [];
            foreach ($application_commands as $command) $names[] = $command->getName();
            $namesString = '`' . implode('`, `', $names) . '`';
            return $message->reply('Application commands: ' . $namesString);
        }), ['Owner', 'High Staff']);

        $this->offsetSet('updatebans', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $updated = false;
            foreach ($this->civ13->enabled_gameservers as &$gameserver) foreach ($this->civ13->enabled_gameservers as &$gameserver2) {
                if ($gameserver->banlog_update(null, file_get_contents($gameserver2->basedir . Civ13::playerlogs)) !== false) $updated = true; // Attempts to fill in any missing data for the ban
            }
            if (! $updated) return $message->react("🔥");
            return $message->react("👍");
        }), ['Owner', 'High Staff']);

        $this->offsetSet('fixroles', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface 
        {
            if (! $guild = $guild = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)) return $message->react("🔥");
            if (! $members = $guild->members->filter(function (Member $member) {
                return ! $member->roles->has($this->civ13->role_ids['Verified'])
                    && ! $member->roles->has($this->civ13->role_ids['banished'])
                    && ! $member->roles->has($this->civ13->role_ids['permabanished'])
                    && ! $member->roles->has($this->civ13->role_ids['dungeon']);
            })) return $message->react("👎");
            foreach ($members as $member) if ($this->civ13->verifier->getVerifiedItem($member)) $member->addRole($this->civ13->role_ids['Verified'], 'fixroles');
            return $message->react("👍");
        }), ['Owner', 'High Staff']);

        $this->offsetSet('panic_bunker', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            return $this->civ13->reply($message, 'Panic bunker is now ' . (($this->civ13->panic_bunker = ! $this->civ13->panic_bunker) ? 'enabled.' : 'disabled.'));
        }), ['Owner', 'High Staff']);

        $this->offsetSet('newmembers', new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
        {
            $newMembers = $message->guild->members->toArray(); // Check all members without filtering by date (it's too slow and not necessary because we're only displaying the 10 most recent members anyway)
            // usort MIGHT be too slow if there are thousands of members. It currently resolves in less than a second with 669 members, but this is a future-proofed method.
            $promise = \React\Promise\resolve($newMembers)
                ->then(function ($members) {
                    return \React\Promise\all($members);
                })
                ->then(function ($members) {
                    usort($members, function ($a, $b) {
                        return $b->joined_at->getTimestamp() - $a->joined_at->getTimestamp();
                    });
                    return \React\Promise\map($members, function (Member $member) {
                        return [
                            'username' => $member->user->username,
                            'id' => $member->id,
                            'join_date' => $member->joined_at->format('Y-m-d H:i:s')
                        ];
                    });
                })
                ->then(function ($sortedMembers) use ($message) {
                    $memberCount = 10; // Number of members to display
                    $mostRecentMembers = array_slice($sortedMembers, 0, $memberCount);
                    // if (count($mostRecentMembers) < $memberCount) $memberCount = count($mostRecentMembers); // If there are less than 10 members, display all of them

                    $membersData = [];
                    foreach ($mostRecentMembers as $member) {
                        $membersData[] = [
                            'username' => $member['username'],
                            'id' => $member['id'],
                            'join_date' => $member['join_date']
                        ];
                    }
                    return $membersData;
                })
                ->then(function ($membersData) use ($message) {
                    $message->react('👍');
                    return $message->reply(MessageBuilder::new()->addFileFromContent('new_members.json', json_encode($membersData, JSON_PRETTY_PRINT)));
                });

            $message->react('⏱️');
            return $promise;
        }), ['Owner', 'High Staff', 'Admin']);
        
        $this->__generateServerMessageCommands();
    }

    /**
     * This method generates server functions based on the server settings.
     * It loops through the server settings and generates server functions for each enabled server.
     * For each server, it generates the following message-related functions, prefixed with the server name:
     * - configexists: checks if the server configuration exists.
     * - host: starts the server host process.
     * - kill: kills the server process.
     * - restart: restarts the server process by killing and starting it again.
     * - mapswap: swaps the current map of the server with a new one.
     * - ban: bans a player from the server.
     * - unban: unbans a player from the server.
     * Also, for each server, it generates the following functions:
     * - discord2ooc: relays message to the server's OOC channel.
     * - discord2admin: relays messages to the server's admin channel.
     * 
     * @return void
     */
    private function __generateServerMessageCommands(): void
    {
        foreach ($this->civ13->enabled_gameservers as &$gameserver) {
            if (! file_exists($gameserver->basedir . Civ13::playernotes_basedir)) $this->logger->debug("Skipping server function `{$gameserver->key}notes` because the required config files were not found.");
            else {
                $servernotes = function (Message $message, array $message_filtered) use (&$gameserver): PromiseInterface
                {
                    if (! $ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content'], strlen("{$gameserver->key}notes")))) return $this->civ13->reply($message, 'Missing ckey! Please use the format `notes ckey`');
                    $first_letter_lower = strtolower(substr($ckey, 0, 1));
                    $first_letter_upper = strtoupper(substr($ckey, 0, 1));
                    
                    $letter_dir = '';
                    
                    if (is_dir($basedir = $gameserver->basedir . Civ13::playernotes_basedir. "/$first_letter_lower")) $letter_dir = $basedir . "/$first_letter_lower";
                    elseif (is_dir($basedir = $gameserver->basedir . Civ13::playernotes_basedir . "/$first_letter_upper")) $letter_dir = $basedir . "/$first_letter_upper";
                    else return $this->civ13->reply($message, "No notes found for any ckey starting with `$first_letter_upper`.");

                    $player_dir = '';
                    $dirs = [];
                    $scandir = scandir($letter_dir);
                    if ($scandir) $dirs = array_filter($scandir, function($dir) use ($ckey) {
                        return strtolower($dir) === strtolower($ckey)/* && is_dir($letter_dir . "/$dir")*/;
                    });
                    if (count($dirs) > 0) $player_dir = $letter_dir . "/" . reset($dirs);
                    else return $this->civ13->reply($message, "No notes found for `$ckey`.");

                    if (file_exists($player_dir . "/info.sav")) $file_path = $player_dir . "/info.sav";
                    else return $this->civ13->reply($message, "A notes folder was found for `$ckey`, however no notes were found in it.");

                    $result = '';
                    if ($contents = @file_get_contents($file_path)) $result = $contents;
                    else return $this->civ13->reply($message, "A notes file with path `$file_path` was found for `$ckey`, however the file could not be read.");
                    
                    return $this->civ13->reply($message, $result, 'info.sav', true);
                };
                $this->offsetSet("{$gameserver->key}notes", $servernotes, ['Owner', 'High Staff', 'Admin']);
            }
            
            $serverconfigexists = function (?Message $message = null) use (&$gameserver): PromiseInterface|bool
            {
                if (isset($gameserver->key)) {
                    if ($message) return $message->react("👍");
                    return true;
                }
                if ($message) return $message->react("👎");
                return false;
            };
            $this->logger->info("Generating {$gameserver->key}configexists command.");
            $this->offsetSet("{$gameserver->key}configexists", $serverconfigexists, ['Owner', 'High Staff']);

            $serverstatus = function (?Message $message = null, string $command, array $message_filtered): ?PromiseInterface
            {
                $builder = MessageBuilder::new();
                $content = '';
                foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                    $content .= "{$gameserver->name}: {$gameserver->ip}:{$gameserver->port}" . PHP_EOL;
                    if ($embed = $gameserver->generateServerstatusEmbed()) $builder->addEmbed($embed);
                }
                return $message->reply($builder);
            };
            $this->offsetSet('serverstatus', $serverstatus, ['Owner', 'High Staff']);
            
            $allRequiredFilesExist = true;
            foreach ([
                //$gameserver->basedir . Civ13::serverdata, // This file is created by the server host process but it doesn't need to exist for the server to be hosted, only deleted
                $gameserver->basedir . Civ13::killsudos,
                $gameserver->basedir . Civ13::dmb,
                $gameserver->basedir . Civ13::updateserverabspaths
            ] as $fp) {
                if (! file_exists($fp)) {
                    $this->logger->debug("Skipping server function `{$gameserver->key}host` because the required config file `$fp` was not found.");
                    $allRequiredFilesExist = false;
                    break;
                }
            }

            $serverhost = new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
            {
                $gameserver->Host($message);
                return $message->react("⏱️");
            });
            $this->offsetSet("{$gameserver->key}host", $serverhost, ['Owner', 'High Staff']);
            $serverkill = new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
            {
                $gameserver->Kill($message);
                return $message->react("⏱️");
            });
            $this->offsetSet("{$gameserver->key}kill", $serverkill, ['Owner', 'High Staff']);
            $serverrestart = new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
            {
                $gameserver->Restart($message);
                return $message->react("⏱️");
            });
            $this->offsetSet("{$gameserver->key}restart", $serverrestart, ['Owner', 'High Staff']);
            $servermapswap = new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
            {
                $split_message = explode("{$gameserver->key}mapswap ", $message_filtered['message_content']);
                if (isset($split_message[1])) return $this->civ13->reply($message, 'You need to include the name of the map.');
                return $this->civ13->reply($message, $gameserver->mapswap($split_message[1], (isset($this->civ13->verifier)) ? ($this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $this->civ13->discord->username) : $this->civ13->discord->username));
            });
            $this->offsetSet("{$gameserver->key}mapswap", $servermapswap, ['Owner', 'High Staff', 'Admin']);

            $serverban = function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
            {
                if (! $this->civ13->hasRequiredConfigRoles(['banished'])) $this->logger->debug("Skipping server function `{$gameserver->key} ban` because the required config roles were not found.");
                if (! $message_content = substr($message_filtered['message_content'], strlen("{$gameserver->key}ban"))) return $this->civ13->reply($message, 'Missing ban ckey! Please use the format `{server}ban ckey; duration; reason`');
                $split_message = explode('; ', $message_content); // $split_target[1] is the target
                if (! $split_message[0]) return $this->civ13->reply($message, 'Missing ban ckey! Please use the format `ban ckey; duration; reason`');
                if (! $split_message[1]) return $this->civ13->reply($message, 'Missing ban duration! Please use the format `ban ckey; duration; reason`');
                if (! $split_message[2]) return $this->civ13->reply($message, 'Missing ban reason! Please use the format `ban ckey; duration; reason`');
                if (! str_ends_with($split_message[2], '.')) $split_message[2] .= '.';
                $maxlen = 150 - strlen(" Appeal at {$this->civ13->discord_formatted}");
                if (strlen($split_message[2]) > $maxlen) return $this->civ13->reply($message, "Ban reason is too long! Please limit it to `$maxlen` characters.");
                $arr = ['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->civ13->discord_formatted}"];
                $result = $this->civ13->ban($arr, $this->civ13->verifier->getVerifiedItem($message->author)['ss13'], $gameserver);
                if ($member = $this->civ13->verifier->getVerifiedMember('id', $split_message[0]))
                    if (! $member->roles->has($this->civ13->role_ids['banished']))
                        $member->addRole($this->civ13->role_ids['banished'], $result);
                return $this->civ13->reply($message, $result);
            };
            $this->offsetSet("{$gameserver->key}ban", $serverban, ['Owner', 'High Staff', 'Admin']);

            $serverunban = function (Message $message, array $message_filtered) use (&$gameserver): PromiseInterface
            {
                if (! $ckey = $this->civ13->sanitizeInput(substr($message_filtered['message_content_lower'], strlen("{$gameserver->key}unban")))) return $this->civ13->reply($message, 'Missing unban ckey! Please use the format `{server}unban ckey`');
                if (is_numeric($ckey)) {
                    if (! $item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "No data found for Discord ID `$ckey`.");
                    $ckey = $item['ckey'];
                }
                
                $this->civ13->unban($ckey, $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'], $gameserver);
                $result = "**$admin** unbanned **$ckey** from **{$gameserver->key}**";
                if ($member = $this->civ13->verifier->getVerifiedMember('id', $ckey))
                    if ($member->roles->has($this->civ13->role_ids['banished']))
                        $member->removeRole($this->civ13->role_ids['banished'], $result);
                return $this->civ13->reply($message, $result);
            };
            $this->offsetSet("{$gameserver->key}unban",  $serverunban, ['Owner', 'High Staff', 'Admin']);
        }
        
        $this->__declareListener();
    }

    /**
     * Declares the listener for handling incoming messages.
     * If no message handlers are found, it logs a debug message and returns.
     * Otherwise, it sets up an event listener for the 'message' event and handles the message.
     *
     * @return void
     */
    private function __declareListener()
    {
        if (! $this->messageHandler->handlers) {
            $this->logger->debug('No message handlers found!');
            return;
        }

        $this->civ13->discord->on('message', function (Message $message): void
        {
            if ($message->author->bot || $message->webhook_id) return; // Ignore bots and webhooks (including slash commands) to prevent infinite loops and other issues
            if (! $this->handle($message, $message_filtered = $this->civ13->filterMessage($message))) { // This section will be deprecated in the future
                if (! empty($this->civ13->functions['message'])) foreach ($this->civ13->functions['message'] as $func) $func($this->civ13, $message, $message_filtered); // Variable functions
                //else $this->logger->debug('No message variable functions found!');
            }
        });
    }
}