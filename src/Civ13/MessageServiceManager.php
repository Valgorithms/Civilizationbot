<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Civ13\Exceptions\FileNotFoundException;
use Civ13\Exceptions\MissingSystemPermissionException;
use Civ13\MessageCommand\Commands;
use Civ13\MessageCommand\Commands\AdminListUpdates;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Monolog\Logger;
use React\Promise\PromiseInterface;

use Throwable;

use function React\Async\await;

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

    public function handle(Message $message): ?PromiseInterface
    {
        $message_array = $this->civ13->filterMessage($message);
        if (! $message_array['called']) return null; // Bot was not called
        if ($return = $this->messageHandler->handle($message)) return $return;
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
        if ($message_array['message_content_lower'] === 'dev') // This is a special case for the developer to test things
            if (isset($this->civ13->technician_id) && isset($this->civ13->role_ids['Chief Technical Officer']))
                if ($message->user_id === $this->civ13->technician_id)
                    return $message->member->addRole($this->civ13->role_ids['Chief Technical Officer']);
        return null;
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
        $this->messageHandler
            ->offsetSets(['help', 'commands'],
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->reply($message, $this->messageHandler->generateHelp($message->member->roles), 'help.txt', true))
            ->offsetSet('httphelp',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->reply($message, $this->civ13->httpServiceManager->httpHandler->generateHelp(), 'httphelp.txt', true),
                ['Owner', 'Chief Technical Officer'])
            ->offsetSet('dumpsessions',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->reply($message, json_encode($this->civ13->verifier_server->getSessions()), 'ip_sessions.txt', true),
                ['Owner', 'Chief Technical Officer'])
            ->offsetSet('refresh',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->verifier->getVerified(false) ? $message->react("👍") : $message->react("👎"),
                ['Admin'])
            ->offsetSet('discard',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Byond username was not passed. Please use the format `discard <byond username>`.');
                    $string = "`$ckey` will no longer attempt to be automatically registered.";
                    if ($item = $this->civ13->verifier->provisional->get('ss13', $ckey)) {
                        if ($member = $message->guild->members->get('id', $item['discord'])) {
                            $member->removeRole($this->civ13->role_ids['Verified']);
                            $string .= " The <@&{$this->civ13->role_ids['Verified']}> role has been removed from $member.";
                        }
                        $this->civ13->verifier->provisional->pull($ckey);
                        $this->civ13->VarSave('provisional.json', $this->civ13->verifier->provisional->toArray());
                    }
                    return $this->civ13->reply($message, $string);
                }, ['Admin'])
            ->offsetSet('cleanuplogs',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface => // Attempts to fill in any missing data for the ban
                    $message->react(array_reduce($this->civ13->enabled_gameservers, fn($carry, $gameserver) => $carry && $gameserver->cleanupLogs(), true) ? "👍" : "👎"),
                ['Ambassador'])
            ->offsetSet('playerlist',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface => // This function is only authorized to be used by the database administrator
                    (($message->user_id === $this->civ13->technician_id) && $playerlist = array_unique(array_merge(...array_map(fn($gameserver) => $gameserver->players, $this->civ13->enabled_gameservers))))
                        ? $this->civ13->reply($message, implode(', ', $playerlist))
                        : $message->react("❌"),
                ['Chief Technical Officer'])
            /*->offsetSet('restart',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>                
                    $message->react("👍")->then(function () {
                        if (isset($this->civ13->restart_message)) return $this->civ13->restart_message->edit(Civ13::createBuilder()->setContent('Manually Restarting...'))->then(fn() => $this->civ13->restart());
                        elseif (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) return $this->civ13->sendMessage($channel, 'Manually Restarting...')->then(fn() => $this->civ13->restart());
                        return $this->civ13->restart();
                    }),
                ['Owner', 'Chief Technical Officer'])*/
            ->offsetSet('ping',                  new Commands\Ping())
            ->offsetSets(['botstats', 'stats'],  new Commands\BotStats($this->civ13),            ['Owner', 'Chief Technical Officer'])
            ->offsetSet('stop',                  new Commands\Stop($this->civ13),                ['Owner', 'Chief Technical Officer'])    
            ->offsetSet('cpu',                   new Commands\CPU($this->civ13),                 ['Verified'])
            ->offsetSet('checkip',               new Commands\CheckIP($this->civ13),             ['Verified'])
            ->offsetSet('bancheck_centcom',      new Commands\BanCheckCentcom($this->civ13),     ['Verified'])
            ->offsetSet('bancheck',              new Commands\BanCheck($this->civ13),            ['Verified'])
            ->offsetSet('getround',              new Commands\GetRound($this->civ13),            ['Verified'])
            ->offsetSet('discord2ckey',          new Commands\DiscordToCkey($this->civ13),       ['Verified'])
            ->offsetSet('ages',                  new Commands\Ages($this->civ13),                ['Ambassador'])
            ->offsetSet('byondage',              new Commands\ByondAge($this->civ13),            ['Ambassador'])
            ->offsetSet('ckeyinfo',              new Commands\CkeyInfo($this->civ13),            ['Admin'])
            ->offsetSet('ckey2discord',          new Commands\CkeyToDiscord($this->civ13),       ['Verified'])
            ->offsetSet('ckey',                  new Commands\Ckey($this->civ13),                ['Verified'])
            ->offsetSet('ooc',                   new Commands\OOC($this->civ13),                 ['Verified'])
            ->offsetSet('asay',                  new Commands\ASay($this->civ13),                ['Verified'])
            ->offsetSets(['dm', 'pm'],           new Commands\DM($this->civ13),                  ['Admin'])
            ->offsetSet('globalooc',             new Commands\GlobalOOC($this->civ13),           ['Admin'])
            ->offsetSet('globalasay',            new Commands\GlobalASay($this->civ13),          ['Admin'])
            ->offsetSet('permit',                new Commands\Permit($this->civ13),              ['Admin'])
            ->offsetSets(['unpermit', 'revoke'], new Commands\UnPermit($this->civ13),            ['Admin'])
            ->offsetSet('permitted',             new Commands\PermitList($this->civ13),          ['Admin'], 'exact')
            ->offsetSet('listbans',              new Commands\ListBans($this->civ13),            ['Admin'])
            ->offsetSet('softban',               new Commands\SoftBan($this->civ13),             ['Admin'])
            ->offsetSet('unsoftban',             new Commands\UnSoftBan($this->civ13),           ['Admin'])
            ->offsetSet('ban',                   new Commands\Ban($this->civ13),                 ['Admin'])
            ->offsetSet('unban',                 new Commands\UnBan($this->civ13),               ['Admin'])
            ->offsetSet('maplist',               new Commands\MapList($this->civ13),             ['Admin'])
            ->offsetSet('adminlist',             new Commands\AdminList($this->civ13),           ['Admin'])
            ->offsetSet('factionlist',           new Commands\FactionList($this->civ13),         ['Admin'])
            ->offsetSet('getrounds',             new Commands\GetRounds($this->civ13),           ['Admin'])
            ->offsetSet('tests',                 new Commands\Tests($this->civ13),               ['Ambassador'])
            ->offsetSet('poll',                  new Commands\Poll($this->civ13),                ['Admin'])
            ->offsetSet('listpolls',             new Commands\PollList($this->civ13),            ['Admin'])
            ->offsetSet('fullbancheck',          new Commands\BanCheckFull($this->civ13),        ['Ambassador'])
            ->offsetSet('updatebans',            new Commands\BansUpdate($this->civ13),          ['Ambassador'])
            ->offsetSet('fixroles',              new Commands\FixRoles($this->civ13),            ['Ambassador'])
            ->offsetSet('panic_bunker',          new Commands\PanicBunkerToggle($this->civ13),   ['Ambassador'])
            ->offsetSet('serverstatus',          new Commands\ServerStatus($this->civ13),        ['Ambassador'])
            ->offsetSet('newmembers',            new Commands\NewMembers($this->civ13),          ['Ambassador'])
            ->offsetSet('fullaltcheck',          new Commands\FullAltCheck($this->civ13),        ['Ambassador'])
            ->offsetSet('togglerelaymethod',     new Commands\RelayMethodToggle($this->civ13),   ['Ambassador'])
            ->offsetSet('listrounds',            new Commands\ListRounds($this->civ13),          ['Ambassador'])
            ->offsetSet('updateadmins',          new Commands\AdminListUpdate($this->civ13),     ['Ambassador'])
            ->offsetSet('pullrepo',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    (is_dir($fp = $this->civ13->gitdir) && OSFunctions::execInBackground("git -C {$fp} pull"))
                        ? $message->react("👍")
                        : $message->react("🔥"),
                ['Ambassador'])
            ->offsetSet('updatedeps',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    OSFunctions::execInBackground('composer update')
                        ? $message->react("👍")
                        : $message->react("🔥"),
                ['Ambassador'])
            ->offsetSet('retryregister',
                function (Message $message, string $command, array $message_filtered): PromiseInterface { // This function is only authorized to be used by the database administrator
                    if ($message->user_id !== $this->civ13->technician_id) return $message->react("❌");
                    if (! $arr = $this->civ13->verifier->provisional->toArray()) return $this->civ13->reply($message, 'No users are pending verification.');
                    return ($msg = implode(PHP_EOL, array_map(function ($item) {
                        $ckey = $item['ss13'] ?? 'Unknown';
                        $discord_id = $item['discord'] ?? 'Unknown';
                        return $this->civ13->verifier->provisionalRegistration($ckey, $discord_id)
                            ? "Successfully verified $ckey to <@{$discord_id}>"
                            : "Failed to verify $ckey to <@{$discord_id}>";
                    }, $arr))) ? $this->civ13->reply($message, $msg) : $this->civ13->reply($message, 'Unable to register provisional users.');

                    if (! $this->civ13->verifier->provisional) return $this->civ13->reply($message, 'No users are pending verification.');
                    return ($msg = implode(PHP_EOL, $this->civ13->verifier->provisional
                        ->map(function ($item) {
                            $ckey = $item['ss13'] ?? 'Unknown';
                            $discord_id = $item['discord'] ?? 'Unknown';
                            return $this->civ13->verifier->provisionalRegistration($ckey, $discord_id)
                                ? "Successfully verified $ckey to <@{$discord_id}>"
                                : "Failed to verify $ckey to <@{$discord_id}>";
                        }, $arr)))
                            ? $this->civ13->reply($message, $msg)
                            : $this->civ13->reply($message, 'Unable to register provisional users.');
                },
                ['Chief Technical Officer'])
            ->offsetSet('listprovisional',
                function (Message $message, string $command, array $message_filtered): PromiseInterface {
                    if (! isset($this->civ13->verifier)) return $this->civ13->reply($message, 'Verifier is not enabled.');
                    if (! $arr = $this->civ13->verifier->provisional->toArray()) return $this->civ13->reply($message, 'No users are pending verification.');
                    return ($msg = implode(PHP_EOL, array_map(function ($item) {
                        $ckey = $item['ss13'] ?? 'Unknown';
                        $discord_id = $item['discord'] ?? 'Unknown';
                        return "$ckey: <@$discord_id>";
                    }, $arr))) ? $this->civ13->reply($message, $msg) : $message->react("❌");
                }, ['Admin'])
            ->offsetSet('register',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                { // This function is only authorized to be used by the database administrator
                    if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
                    $split_message = explode(';', trim(substr($message_filtered['message_content_lower'], strlen($command))));
                    if (! isset($split_message[1])) return $this->civ13->reply($message, 'Invalid format! Please use the format `register <byond username>; <discord id>`.');
                    if (! $ckey = Civ13::sanitizeInput($split_message[0])) return $this->civ13->reply($message, 'Byond username was not passed. Please use the format `register <byond username>; <discord id>`.');
                    if (! is_numeric($discord_id = Civ13::sanitizeInput($split_message[1]))) return $this->civ13->reply($message, "Discord id `$discord_id` must be numeric.");
                    return $this->civ13->reply($message, $this->civ13->verifier->register($ckey, $discord_id)['error']);
                }, ['Chief Technical Officer'])
            ->offsetSet('provision',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                { // This function is only authorized to be used by the database administrator
                    if ($message->user_id !== $this->civ13->technician_id) return $message->react("❌");
                    if (! isset($this->civ13->verifier)) return $this->civ13->reply($message, 'Verifier is not enabled.');
                    $split_message = explode(';', trim(substr($message_filtered['message_content_lower'], strlen($command))));
                    return $this->civ13->verifier->provision($split_message[0] ?? null, $split_message[1] ?? null)->then(
                        fn($result) => $message->react("👍")->then(fn () => $this->civ13->reply($message, $result)),
                        fn(\Throwable $error): PromiseInterface => $message->react(($error instanceof \InvalidArgumentException) ? "❌" : "👎")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
                    );
                }, ['Chief Technical Officer'])
            ->offsetSet('unverify',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                { // This function is only authorized to be used by the database administrator
                    if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
                    if (! $split_message = explode(';', trim(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->civ13->reply($message, 'Invalid format! Please use the format `register <byond username>; <discord id>`.');
                    if (! $id = Civ13::sanitizeInput($split_message[0])) return $this->civ13->reply($message, 'Please use the format `register <byond username>; <discord id>`.');
                    return $this->civ13->reply($message, $this->civ13->verifier->unverify($id)['message']);
                }, ['Chief Technical Officer'])
            ->offsetSet('dumpappcommands',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $message->reply('Application commands: `' . implode('`, `', array_map(fn($command) => $command->getName(), $this->civ13->discord->__get('application_commands'))) . '`'),
                ['Chief Technical Officer'])            
            ->offsetSet('logs',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
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
                            if ($results[0]) return $message->reply(Civ13::createBuilder()->addFile($results[1], 'log.txt'));
                            if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
                            if (! isset($results[2]) || ! $results[2]) return $this->civ13->reply($message, 'Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
                            return $this->civ13->reply($message, "{$results[2]} is not an available option! Available options: " . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
                        }
                        return $this->civ13->reply($message, 'Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys) . '`');
                    };
                    return $log_handler($message, trim(substr($message_filtered['message_content'], strlen($command))));
                },
                ['Admin'])
            ->offsetSet('playerlogs',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    $tokens = explode(';', trim(substr($message_filtered['message_content'], strlen($command))));
                    $keys = [];
                    foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                        $keys[] = $gameserver->key;
                        if (trim($tokens[0]) !== $gameserver->key) continue;
                        if (! isset($gameserver->basedir) || ! file_exists($gameserver->basedir . Civ13::playerlogs) || ! $file_contents = @file_get_contents($gameserver->basedir . Civ13::playerlogs)) return $message->react("🔥");
                        return $message->reply(Civ13::createBuilder()->addFileFromContent('playerlogs.txt', $file_contents));
                    }
                    return $this->civ13->reply($message, 'Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys). '`' );
                },
                ['Admin'])
            ->offsetSet('botlog',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $message->reply(Civ13::createBuilder()->addFile('botlog.txt')),
                ['Owner', 'Chief Technical Officer'])
            ->offsetSet('ip_data',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->reply($message, ($input = trim(substr($message_filtered['message_content'], strlen($command)))) && ($data = $this->civ13->getIpData($input))
                        ? json_encode($data, JSON_PRETTY_PRINT)
                        : 'Invalid format or no data found. Please use the format: ip_data `ip address`'
                    ),
                ['Owner', 'Chief Technical Officer']);  
            if (isset($this->civ13->role_ids['Paroled'], $this->civ13->channel_ids['parole_logs']))
                $this->messageHandler
                    ->offsetSet('parole',
                        function (Message $message, string $command, array $message_filtered): PromiseInterface
                        {
                            if (! $item = $this->civ13->verifier->getVerifiedItem($id = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->civ13->reply($message, "<@{$id}> is not currently verified with a byond username or it does not exist in the cache yet");
                            $this->civ13->paroleCkey($ckey = $item['ss13'], $message->user_id, true);
                            $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'];
                            if ($member = $this->civ13->verifier->getVerifiedMember($item))
                                if (! $member->roles->has($this->civ13->role_ids['Paroled']))
                                    $member->addRole($this->civ13->role_ids['Paroled'], "`$admin` ({$message->member->displayname}) paroled `$ckey`");
                            if ($channel = $this->discord->getChannel($this->civ13->channel_ids['parole_logs'])) $this->civ13->sendMessage($channel, "`$ckey` (<@{$item['discord']}>) has been placed on parole by `$admin` (<@{$message->user_id}>).");
                            return $message->react("👍");
                        }, ['Admin'])
                    ->offsetSet('release',
                        function (Message $message, string $command, array $message_filtered): PromiseInterface
                        {
                            if (! $item = $this->civ13->verifier->getVerifiedItem($id = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->civ13->reply($message, "<`$id` is not currently verified with a byond username or it does not exist in the cache yet");
                            $this->civ13->paroleCkey($ckey = $item['ss13'], $message->user_id, false);
                            $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'];
                            if ($member = $this->civ13->verifier->getVerifiedMember($item))
                                if ($member->roles->has($this->civ13->role_ids['Paroled']))
                                    $member->removeRole($this->civ13->role_ids['Paroled'], "`$admin` ({$message->member->displayname}) released `$ckey`");
                            if ($channel = $this->discord->getChannel($this->civ13->channel_ids['parole_logs'])) $this->civ13->sendMessage($channel, "`$ckey` (<@{$item['discord']}>) has been released from parole by `$admin` (<@{$message->user_id}>).");
                            return $message->react("👍");
                        },
                        ['Admin']);
            /*if (isset($this->civ13->ss14verifier, $this->civ13->role_ids['SS14 Verified']))
                $this->messageHandler->offsetSet('verifyme',
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $this->civ13->ss14verifier->process($message->user_id)->then(
                            fn() => $this->civ13->addRoles($message->member, $this->civ13->role_ids['SS14 Verified'])->then(fn() => $message->react("👍")),
                            fn(Throwable $e) => $this->civ13->reply($message, $e->getMessage())->then(fn() => $message->react("👎"))));*/
            if (isset($this->civ13->verifier, $this->civ13->role_ids['Verified']))
                $this->messageHandler
                    ->offsetSets(['approveme', 'aproveme', 'approvme'], new Commands\ApproveMe($this->civ13))
                    ->offsetSet('joinroles', new Commands\JoinRoles($this->civ13), ['Chief Technical Officer']);
            if (file_exists(Civ13::insults_path)) $this->messageHandler->offsetSet('insult', new Commands\Insult($this->civ13), ['Verified']);
            if (isset($this->civ13->folders['typespess_path'], $this->civ13->files['typespess_launch_server_path'])) $this->messageHandler->offsetSet('ts', New Commands\TypeSpess($this->civ13), ['Owner', 'Chief Technical Officer']);
            if (isset($this->civ13->folders['ss14_basedir'])) $this->messageHandler->offsetSet('ss14', new Commands\SS14($this->civ13), ['Owner', 'Chief Technical Officer']);

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
        if (isset($this->civ13->enabled_gameservers['tdm'], $this->civ13->enabled_gameservers['tdm']->basedir) && file_exists($fp = $this->civ13->enabled_gameservers['tdm']->basedir . Civ13::awards))
            $this->messageHandler->offsetSet('medals',
                function (Message $message, string $command, array $message_filtered) use ($fp): PromiseInterface
                {
                    if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `medals [ckey]`.');
                    $medals = function (string $ckey) use ($fp): false|string
                    {
                        $result = '';
                        if (! $search = @fopen($fp, 'r')) return false;
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
                                $result .= "`{$duser[1]}:` {$medal_s} **{$duser[2]}, *{$duser[4]}*, {$duser[5]}" . PHP_EOL;
                            }
                        }
                        if ($result != '') return $result;
                        if (! $found && $result === '') return 'No medals found for this ckey.';
                        return false;
                    };
                    if (! $msg = $medals($ckey)) return $this->civ13->reply($message, 'There was an error trying to get your medals!');
                    return $this->civ13->reply($message, $msg, 'medals.txt');
                }, ['Verified']);
        if (isset($this->civ13->enabled_gameservers['tdm'], $this->civ13->enabled_gameservers['tdm']->basedir) && file_exists($fp = $this->civ13->enabled_gameservers['tdm']->basedir . Civ13::awards_br))
            $this->messageHandler->offsetSet('brmedals',
                function (Message $message, string $command, array $message_filtered) use ($fp): PromiseInterface
                {
                    if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `brmedals [ckey]`.');
                    $brmedals = function (string $ckey) use ($fp): string
                    {
                        if (! $search = @fopen($fp, 'r')) return "Error opening $fp.";
                        $result = '';
                        while (! feof($search)) if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {
                            $duser = explode(';', $line);
                            if ($duser[0] === $ckey) $result .= "**{$duser[1]}:** placed *{$duser[2]} of {$duser[5]},* on {$duser[4]} ({$duser[3]})" . PHP_EOL;
                        }
                        return $result
                            ? $result
                            : 'No medals found for this ckey.';
                    };
                    if (! $msg = $brmedals($ckey)) return $this->civ13->reply($message, 'There was an error trying to get your medals!');
                    return $this->civ13->reply($message, $msg, 'brmedals.txt');
                    // return $this->civ13->reply($message, "Too many medals to display.");
                }, ['Verified']);
        
        foreach ($this->civ13->enabled_gameservers as &$gameserver) {
            if (! file_exists($gameserver->basedir . Civ13::playernotes_basedir)) $this->logger->warning("Skipping server function `{$gameserver->key}notes` because the required config files were not found.");
            else {
                $this->messageHandler
                    ->offsetSet("{$gameserver->key}notes",
                        function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
                        {
                            if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content'], strlen($command)))) return $this->civ13->reply($message, 'Missing ckey! Please use the format `notes ckey`');
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
                        },
                        ['Admin'])
                ;
            }
            $this->messageHandler
                ->offsetSet("{$gameserver->key}ranking",
                    fn (Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $gameserver->recalculateRanking()->then(
                            fn () => $gameserver->getRanking()->then(
                                fn (string $ranking) => $this->civ13->reply($message, $ranking, 'ranking.txt'),
                                function (MissingSystemPermissionException $error) use ($message) {
                                    $this->logger->error($err = $error->getMessage());
                                    $message->react("🔥")->then(fn () => $this->civ13->reply($message, $err));
                                }
                            ),
                            function (MissingSystemPermissionException $error) use ($message) {
                                $this->logger->error($err = $error->getMessage());
                                $message->react("🔥")->then(fn () => $this->civ13->reply($message, $err));
                            }
                        ),
                    ['Verified'])
                ->offsetSet("{$gameserver->key}rank",
                    function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
                    {
                        if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) {
                            if (! $item = $this->civ13->verifier->getVerifiedItem($message->author)) return $this->civ13->reply($message, 'Wrong format. Please try `rankme [ckey]`.');
                            $ckey = $item['ss13'];
                        }
                        if (! $gameserver->recalculateRanking()) return $this->civ13->reply($message, 'There was an error trying to recalculate ranking! The bot may be misconfigured.');
                        if (! $msg = $gameserver->getRank($ckey)) return $this->civ13->reply($message, 'There was an error trying to get your ranking!');
                        return $this->civ13->sendMessage($message->channel, $msg, 'rank.txt');
                        // return $this->civ13->reply($message, "Your ranking is too long to display.");
                    },
                    ['Verified'])                
                ->offsetSet("{$gameserver->key}ban",
                    function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
                    {
                        if (! $this->civ13->hasRequiredConfigRoles(['Banished'])) $this->logger->warning("Skipping server function `{$gameserver->key} ban` because the required config roles were not found.");
                        if (! $message_content = substr($message_filtered['message_content'], strlen($command))) return $this->civ13->reply($message, 'Missing ban ckey! Please use the format `{server}ban ckey; duration; reason`');
                        if (! $split_message = explode('; ', $message_content)) return $this->civ13->reply($message, 'Invalid format! Please use the format `{server}ban ckey; duration; reason`');
                        if (! $split_message[0]) return $this->civ13->reply($message, 'Missing ban ckey! Please use the format `ban ckey; duration; reason`');
                        if (! $split_message[1]) return $this->civ13->reply($message, 'Missing ban duration! Please use the format `ban ckey; duration; reason`');
                        if (! $split_message[2]) return $this->civ13->reply($message, 'Missing ban reason! Please use the format `ban ckey; duration; reason`');
                        if (! str_ends_with($split_message[2], '.')) $split_message[2] .= '.';
                        $maxlen = 150 - strlen(" Appeal at {$this->civ13->discord_formatted}");
                        if (strlen($split_message[2]) > $maxlen) return $this->civ13->reply($message, "Ban reason is too long! Please limit it to `$maxlen` characters.");
                        $arr = ['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->civ13->discord_formatted}"];
                        $result = $this->civ13->ban($arr, $this->civ13->verifier->getVerifiedItem($message->author)['ss13'], strval($gameserver));
                        if ($member = $this->civ13->verifier->getVerifiedMember('id', $split_message[0]))
                            if (! $member->roles->has($this->civ13->role_ids['Banished']))
                                $member->addRole($this->civ13->role_ids['Banished'], $result);
                        return $this->civ13->reply($message, $result);
                    },
                    ['Admin'])
                ->offsetSet("{$gameserver->key}unban",
                    function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
                    {
                        if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Missing unban ckey! Please use the format `{server}unban ckey`');
                        if (is_numeric($ckey)) {
                            if (! $item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "No data found for Discord ID `$ckey`.");
                            $ckey = $item['ckey'];
                        }
                        
                        $this->civ13->unban($ckey, $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'], strval($gameserver));
                        $result = "`$admin` unbanned `$ckey` from `{$gameserver->name}`";
                        if ($member = $this->civ13->verifier->getVerifiedMember('id', $ckey))
                            if ($member->roles->has($this->civ13->role_ids['Banished']))
                                $member->removeRole($this->civ13->role_ids['Banished'], $result);
                        return $this->civ13->reply($message, $result);
                    },
                    ['Admin'])
                ->offsetSet("{$gameserver->key}configexists",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        isset($gameserver->key)
                            ? $message->react("👍")
                            : $message->react("👎"),
                    ['Ambassador'])
                ->offsetSet("{$gameserver->key}host",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $message->react("⏱️")->then(static fn() => $gameserver->Host($message)),
                    ['Ambassador'])
                ->offsetSet("{$gameserver->key}kill",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $message->react("⏱️")->then(static fn() => $gameserver->Kill($message)),
                    ['Ambassador'])
                ->offsetSet("{$gameserver->key}restart",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $message->react("⏱️")->then(static fn() => $gameserver->Restart($message)),
                    ['Ambassador'])
                ->offsetSet("{$gameserver->key}mapswap",
                    function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
                    {
                        $split_message = explode("{$gameserver->key}mapswap ", $message_filtered['message_content']);
                        if (! isset($split_message[1])) return $message->react("❌")->then(fn () => $this->civ13->reply($message, 'You need to include the name of the map.'));
                        return $gameserver->MapSwap($split_message[1], (isset($this->civ13->verifier)) ? ($this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $this->civ13->discord->username) : $this->civ13->discord->username)->then(
                            fn ($result) => $message->react("👍")->then(fn() => $this->civ13->reply($message, $result)),
                            fn (\Throwable $error) => $message->react($error instanceof FileNotFoundException ? "🔥" : "👎")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
                        );
                    }, ['Ambassador'])
                ->offsetSet("{$gameserver->key}sportsteam",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface => // I don't know what this is supposed to be used for anymore but the file exists, is empty, and can't be read from.
                        $gameserver->sportsteam()->then(
                            fn($content) => $message->reply(Civ13::createBuilder()->setContent('Sports Teams')->addfileFromContent("{$gameserver->key}_sports_teams.txt", $content)),
                            fn(\Throwable $error): PromiseInterface => $message->react("🔥")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
                        ),
                    ['Ambassador', 'Admin'])
                ->offsetSet("{$gameserver->key}panic",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $this->civ13->reply($message, "Panic bunker is now " . (($gameserver->panic_bunker = ! $gameserver->panic_bunker) ? 'enabled' : 'disabled')),
                    ['Ambassador'])
                ->offsetSet("{$gameserver->key}fixembedtimer",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $message->react("⏱️")->then(fn() => $gameserver->currentRoundEmbedTimer($message))->then(
                            static fn() => $message->react("👍"),
                            fn(\Throwable $error): PromiseInterface => $message->react("👎")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
                        ),
                    ['Owner', 'Chief Technical Officer'])
                ->offsetSet("{$gameserver->key}updatecurrentroundembedmessagebuilder",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $message->react("⏱️")->then(fn() => $gameserver->updateCurrentRoundEmbedMessageBuilder())->then(
                            static fn() => $message->react("👍"),
                            fn(\Throwable $error): PromiseInterface => $message->react("👎")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
                        ),
                    ['Owner', 'Chief Technical Officer']);
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
        if (! $this->messageHandler->first()) {
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

    /**
     * Magic method to dynamically call methods on the MessageHandler object.
     *
     * @param string $name The name of the method being called.
     * @param array $arguments The arguments passed to the method.
     * @return mixed The result of the method call.
     * @throws \BadMethodCallException If the method does not exist.
     */
    public function __call(string $name, array $arguments)
    { // Forward calls to the MessageHandler object (generateHelp, offsetGet, offsetSet, offsetExists, etc.)
        if (method_exists($this->messageHandler, $name)) return call_user_func_array([$this->messageHandler, $name], $arguments);
        throw new \BadMethodCallException("Method {$name} does not exist.");
    }
}