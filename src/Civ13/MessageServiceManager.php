<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Civ13\MessageCommand\Commands;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Monolog\Logger;
use React\Promise\PromiseInterface;

use Throwable;

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
            ->offsetSets(['help', 'commands'],   new Commands\Help                ($this->civ13, $this->messageHandler))
            ->offsetSet('ping',                  new Commands\Ping                ())
            ->offsetSets(['botstats', 'stats'],  new Commands\BotStats            ($this->civ13), ['Owner', 'Chief Technical Officer'])
            ->offsetSet('updatedeps',            new Commands\UpdateDependencies  ($this->civ13), ['Owner', 'Chief Technical Officer'])
            ->offsetSet('stop',                  new Commands\Stop                ($this->civ13), ['Owner', 'Chief Technical Officer'])
            ->offsetSet('ip_data',               new Commands\IPData              ($this->civ13), ['Owner', 'Chief Technical Officer'])
            ->offsetSet('botlog',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $message->reply(Civ13::createBuilder()->addFile('botlog.txt')),
                ['Owner', 'Chief Technical Officer'])
            /*->offsetSet('restart',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>                
                    $message->react("👍")->then(function () {
                        if (isset($this->civ13->restart_message)) return $this->civ13->restart_message->edit(Civ13::createBuilder()->setContent('Manually Restarting...'))->then(fn() => $this->civ13->restart());
                        elseif (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) return $this->civ13->sendMessage($channel, 'Manually Restarting...')->then(fn() => $this->civ13->restart());
                        return $this->civ13->restart();
                    }),
                ['Owner', 'Chief Technical Officer'])*/
            ->offsetSet('httphelp',              new Commands\HTTPHelp            ($this->civ13), ['Chief Technical Officer'])
            ->offsetSet('dumpsessions',          new Commands\DumpOAuthIPSessions ($this->civ13), ['Chief Technical Officer'])
            ->offsetSet('cleanupgamelogs',       new Commands\Civ13CleanupGameLogs($this->civ13), ['Chief Technical Officer'])
            ->offsetSet('playerlist',            new Commands\Civ13PlayerList     ($this->civ13), ['Chief Technical Officer'])
            ->offsetSet('civ13register',         new Commands\Civ13Register       ($this->civ13), ['Chief Technical Officer'])
            ->offsetSet('civ14register',         new Commands\Civ14Register       ($this->civ13), ['Chief Technical Officer'])
            ->offsetSet('civ13unverify',         new Commands\Civ13UnVerify       ($this->civ13), ['Chief Technical Officer'])
            ->offsetSet('civ14unverify',         new Commands\Civ14UnVerify       ($this->civ13), ['Chief Technical Officer'])
            ->offsetSet('dumpappcommands',       new Commands\DumpAppCommands     ($this->civ13), ['Chief Technical Officer'])
            ->offsetSet('ages',                  new Commands\Ages                ($this->civ13), ['Chief Technical Officer'])
            ->offsetSet('tests',                 new Commands\Tests               ($this->civ13), ['Ambassador'])
            ->offsetSet('fullbancheck',          new Commands\BanCheckFull        ($this->civ13), ['Ambassador'])
            ->offsetSet('updatebans',            new Commands\BansUpdate          ($this->civ13), ['Ambassador'])
            ->offsetSet('fixroles',              new Commands\FixRoles            ($this->civ13), ['Ambassador'])
            ->offsetSet('panic_bunker',          new Commands\PanicBunkerToggle   ($this->civ13), ['Ambassador'])
            ->offsetSet('serverstatus',          new Commands\ServerStatus        ($this->civ13), ['Ambassador'])
            ->offsetSet('newmembers',            new Commands\NewMembers          ($this->civ13), ['Ambassador'])
            ->offsetSet('fullaltcheck',          new Commands\FullAltCheck        ($this->civ13), ['Ambassador'])
            ->offsetSet('togglerelaymethod',     new Commands\RelayMethodToggle   ($this->civ13), ['Ambassador'])
            ->offsetSet('listrounds',            new Commands\ListRounds          ($this->civ13), ['Ambassador'])
            ->offsetSet('updateadmins',          new Commands\AdminListUpdate     ($this->civ13), ['Ambassador'])
            ->offsetSet('pullrepo',              new Commands\PullCivRepository   ($this->civ13), ['Ambassador'])
            ->offsetSet('byondage',              new Commands\ByondAge            ($this->civ13), ['Ambassador'])
            ->offsetSet('cpu',                   new Commands\CPU                 ($this->civ13), ['Admin'])
            ->offsetSet('ckeyinfo',              new Commands\CkeyInfo            ($this->civ13), ['Admin'])
            ->offsetSet('ckey2discord',          new Commands\CkeyToDiscord       ($this->civ13), ['Admin'])
            ->offsetSet('discord2ckey',          new Commands\DiscordToCkey       ($this->civ13), ['Admin'])
            ->offsetSets(['dm', 'pm'],           new Commands\DM                  ($this->civ13), ['Admin'])
            ->offsetSet('globalooc',             new Commands\GlobalOOC           ($this->civ13), ['Admin'])
            ->offsetSet('globalasay',            new Commands\GlobalASay          ($this->civ13), ['Admin'])
            ->offsetSet('permit',                new Commands\Permit              ($this->civ13), ['Admin'])
            ->offsetSets(['unpermit', 'revoke'], new Commands\UnPermit            ($this->civ13), ['Admin'])
            ->offsetSet('permitted',             new Commands\PermitList          ($this->civ13), ['Admin'], 'exact')
            ->offsetSet('listbans',              new Commands\ListBans            ($this->civ13), ['Admin'])
            ->offsetSet('softban',               new Commands\SoftBan             ($this->civ13), ['Admin'])
            ->offsetSet('unsoftban',             new Commands\UnSoftBan           ($this->civ13), ['Admin'])
            ->offsetSet('ban',                   new Commands\Ban                 ($this->civ13), ['Admin'])
            ->offsetSet('unban',                 new Commands\UnBan               ($this->civ13), ['Admin'])
            ->offsetSet('maplist',               new Commands\MapList             ($this->civ13), ['Admin'])
            ->offsetSet('listadmins',            new Commands\ListAdmins          ($this->civ13), ['Admin'])
            ->offsetSet('factionlist',           new Commands\FactionList         ($this->civ13), ['Admin'])
            ->offsetSet('getrounds',             new Commands\GetRounds           ($this->civ13), ['Admin'])
            ->offsetSet('poll',                  new Commands\Poll                ($this->civ13), ['Admin'])
            ->offsetSet('civ13logs',             new Commands\Civ13Logs           ($this->civ13), ['Admin'])
            ->offsetSet('civ13playerlogs',       new Commands\Civ13PlayerLogs     ($this->civ13), ['Admin'])
            ->offsetSet('checkip',               new Commands\CheckIP             ($this->civ13), ['Verified', 'SS14 Verified'])
            ->offsetSet('ckey',                  new Commands\Ckey                ($this->civ13), ['Verified'])
            ->offsetSet('ooc',                   new Commands\OOC                 ($this->civ13), ['Verified'])
            ->offsetSet('asay',                  new Commands\ASay                ($this->civ13), ['Verified'])
            ->offsetSet('bancheck_centcom',      new Commands\BanCheckCentcom     ($this->civ13), ['Verified'])
            ->offsetSet('bancheck',              new Commands\BanCheck            ($this->civ13), ['Verified'])
            ->offsetSet('getround',              new Commands\GetRound            ($this->civ13), ['Verified'])
            ;
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
            if (isset($this->civ13->ss14verifier, $this->civ13->role_ids['SS14 Verified'])) $this->messageHandler->offsetSet('verifyme', new Commands\SS14Verify($this->civ13));
            if (isset($this->civ13->verifier, $this->civ13->role_ids['Verified']))
                $this->messageHandler
                    ->offsetSets(['approveme', 'aproveme', 'approvme'], new Commands\ApproveMe($this->civ13))
                    ->offsetSet('joinroles', new Commands\JoinRoles($this->civ13), ['Chief Technical Officer']);
            if (file_exists(Civ13::insults_path)) $this->messageHandler->offsetSet('insult', new Commands\Insult($this->civ13), ['Verified', 'SS14 Verified']);
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
        if (isset($this->civ13->enabled_gameservers['tdm'], $this->civ13->enabled_gameservers['tdm']->basedir)) {
            if (file_exists($this->civ13->enabled_gameservers['tdm']->basedir . Civ13::awards))
                $this->messageHandler->offsetSet('civ13medals',   new Commands\Civ13GameServerMedals  ($this->civ13, $this->civ13->enabled_gameservers['tdm']), ['Verified']);
            if (file_exists($this->civ13->enabled_gameservers['tdm']->basedir . Civ13::awards_br))
                $this->messageHandler->offsetSet('civ13brmedals', new Commands\Civ13GameServerBRMedals($this->civ13, $this->civ13->enabled_gameservers['tdm']), ['Verified']);
        }
        
        foreach ($this->civ13->enabled_gameservers as &$gameserver) {
            /*if (! file_exists($gameserver->basedir . Civ13::playernotes_basedir)) $this->logger->warning("Skipping server function `{$gameserver->key}notes` because the required config files were not found.");
            else $this->messageHandler->offsetSet("{$gameserver->key}notes",
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
                    if ($scandir) $dirs = array_filter($scandir, static fn($dir) =>
                        strtolower($dir) === strtolower($ckey) //&& is_dir($letter_dir . "/$dir")
                    );
                    if (count($dirs) > 0) $player_dir = $letter_dir . "/" . reset($dirs);
                    else return $this->civ13->reply($message, "No notes found for `$ckey`.");

                    if (file_exists($player_dir . "/info.sav")) $file_path = $player_dir . "/info.sav";
                    else return $this->civ13->reply($message, "A notes folder was found for `$ckey`, however no notes were found in it.");

                    $result = '';
                    if ($contents = @file_get_contents($file_path)) $result = $contents;
                    else return $this->civ13->reply($message, "A notes file with path `$file_path` was found for `$ckey`, however the file could not be read.");
                    
                    return $this->civ13->reply($message, $result, 'info.sav', true);
                },
                ['Admin']);*/
            $this->messageHandler
                /*->offsetSet("{$gameserver->key}fixembedtimer",
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
                    ['Owner', 'Chief Technical Officer'])
                ->offsetSet("{$gameserver->key}configexists",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        isset($gameserver->key)
                            ? $message->react("👍")
                            : $message->react("👎"),
                    ['Ambassador'])
                ->offsetSet("{$gameserver->key}sportsteam",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface => // I don't know what this is supposed to be used for anymore but the file exists, is empty, and can't be read from.
                        $gameserver->sportsteam()->then(
                            fn($content) => $message->reply(Civ13::createBuilder()->setContent('Sports Teams')->addfileFromContent("{$gameserver->key}_sports_teams.txt", $content)),
                            fn(\Throwable $error): PromiseInterface => $message->react("🔥")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
                        ),
                    ['Ambassador', 'Admin'])*/
                ->offsetSet("{$gameserver->key}host",    new Commands\Civ13GameServerHost   ($this->civ13, $gameserver), ['Ambassador'])
                ->offsetSet("{$gameserver->key}kill",    new Commands\Civ13GameServerKill   ($this->civ13, $gameserver), ['Ambassador'])
                ->offsetSet("{$gameserver->key}restart", new Commands\Civ13GameServerRestart($this->civ13, $gameserver), ['Ambassador'])
                ->offsetSet("{$gameserver->key}mapswap", new Commands\Civ13GameServerMapSwap($this->civ13, $gameserver), ['Ambassador'])
                ->offsetSet("{$gameserver->key}panic",   new Commands\Civ13GameServerPanic  ($this->civ13, $gameserver), ['Ambassador'])
                ->offsetSet("{$gameserver->key}ban",     new Commands\Civ13GameServerBan    ($this->civ13, $gameserver), ['Admin'])
                ->offsetSet("{$gameserver->key}unban",   new Commands\Civ13GameServerUnBan  ($this->civ13, $gameserver), ['Admin'])
                ->offsetSet("{$gameserver->key}ranking", new Commands\Civ13GameServerRanking($this->civ13, $gameserver), ['Verified'])
                ->offsetSet("{$gameserver->key}rank",    new Commands\Civ13GameServerRank   ($this->civ13, $gameserver), ['Verified'])
        ;}
        foreach ($this->civ13->civ14_enabled_gameservers as &$gameserver) {
            $this->messageHandler
                ->offsetSet("{$gameserver->key}medals", new Commands\SS14Medals($this->civ13, $gameserver), ['Verified', 'SS14 Verified'])
        ;}
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