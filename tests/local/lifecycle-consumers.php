<?php
declare(strict_types=1);

// Production panel callbacks and message command with inert effects only.
// No bot bootstrap, .env, network, game/controller process or real runtime files.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);

use Discord\Builders\MessageBuilder;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class LifecycleConsumerBot extends \Civ13\Civ13
{
    public array $replies = [];
    public function reply(\Discord\Parts\Channel\Message|\Discord\Parts\Thread\Thread $message, string $content, string $file_name = 'message.txt', bool $prevent_mentions = false): PromiseInterface
    {
        $this->replies[] = $content;
        return resolve(null);
    }
}
final class LifecycleConsumerDiscord extends \Discord\Discord
{
    public function __get(string $name) { return $name === 'username' ? 'synthetic-admin' : parent::__get($name); }
}
final class LifecycleConsumerMember extends \Discord\Parts\User\Member
{
    public function __get(string $name) { return new class { public function has($id): bool { return $id === 'synthetic-staff'; } }; }
}
final class LifecycleConsumerServer extends \Civ13\GameServer
{
    public Deferred $operation;
    public bool $unavailable = false;
    public array $requests = [];
    private function control(string $action, ?string $map = null): PromiseInterface
    {
        $this->requests[] = [$action, $map];
        if ($this->unavailable) { throw new RuntimeException('Synthetic control unavailable'); }
        return $this->operation->promise();
    }
    public function Host(?\Discord\Parts\Channel\Message $message = null): PromiseInterface { return $this->control('Host'); }
    public function Kill(?\Discord\Parts\Channel\Message $message = null, bool $notify = true): PromiseInterface { return $this->control('Kill'); }
    public function Restart(?\Discord\Parts\Channel\Message $message = null, bool $notify = true): PromiseInterface { return $this->control('Restart'); }
    public function MapSwap(string $mapto, string $admin): PromiseInterface { return $this->control('MapSwap', $mapto); }
}
final class LifecycleConsumerInteraction extends \Discord\Parts\Interactions\Interaction
{
    public Deferred $ack;
    public ?\Discord\Parts\User\Member $fixtureMember;
    public array $values = ['TEST'];
    public array $privateAcks = [];
    public array $replies = [];
    public array $initial = [];
    public function __isset(string $name): bool { return in_array($name, ['member', 'user', 'data'], true); }
    public function __get(string $name)
    {
        return match ($name) {
            'member' => $this->fixtureMember,
            'user' => (object) ['id' => 'synthetic-actor', 'username' => 'synthetic-admin'],
            'data' => (object) ['values' => $this->values],
            default => throw new LogicException('Unexpected interaction field'),
        };
    }
    public function acknowledgeWithResponse(bool $ephemeral = false): PromiseInterface { $this->privateAcks[] = $ephemeral; return $this->ack->promise(); }
    public function updateOriginalResponse(MessageBuilder $builder): PromiseInterface { $this->replies[] = $builder->getContent(); return resolve(null); }
    public function respondWithMessage(MessageBuilder|string $builder, bool $ephemeral = false): PromiseInterface { $this->initial[] = [$builder, $ephemeral]; return resolve(null); }
}
final class LifecycleConsumerMessage extends \Discord\Parts\Channel\Message
{
    public array $reactions = [];
    public function react($emoticon): PromiseInterface { $this->reactions[] = $emoticon; return resolve(null); }
}
function lifecycleConsumerCheck(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
$bot = (new ReflectionClass(LifecycleConsumerBot::class))->newInstanceWithoutConstructor();
$bot->logger = new \Monolog\Logger('offline-lifecycle-consumers', [new \Monolog\Handler\NullHandler()]);
$bot->discord = (new ReflectionClass(LifecycleConsumerDiscord::class))->newInstanceWithoutConstructor();
$bot->role_ids = ['Admin' => 'synthetic-staff'];
$panel = (new ReflectionClass(\Civ13\Panels::class))->newInstanceWithoutConstructor();
$panel->civ13 = $bot; $panel->discord = $bot->discord; $panel->logger = $bot->logger;
$server = (new ReflectionClass(LifecycleConsumerServer::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($server, 'name'))->setValue($server, 'Synthetic server');
$bot->enabled_gameservers = ['fixture' => $server];
$member = (new ReflectionClass(LifecycleConsumerMember::class))->newInstanceWithoutConstructor();
$interaction = static function () use ($member): LifecycleConsumerInteraction {
    $i = (new ReflectionClass(LifecycleConsumerInteraction::class))->newInstanceWithoutConstructor();
    $i->ack = new Deferred(); $i->fixtureMember = $member; return $i;
};
$reset = static function (string $mode, string $outcome) use ($server): void {
    $server->engine = $mode === 'opendream' ? 'opendream' : 'byond';
    $server->operation = new Deferred(); $server->requests = []; $server->unavailable = $outcome === 'unavailable';
};
$finish = static function (string $mode, string $outcome, bool $map = false, bool $stop = false) use ($server): void {
    if ($outcome === 'unavailable') { return; }
    if ($outcome === 'rejected') { $server->operation->reject(new RuntimeException('Synthetic outcome unknown')); return; }
    $server->operation->resolve($mode === 'opendream' ? ['ok' => true, 'running' => !$stop] : ($map ? 'Map change queued' : ($mode === 'legacy' ? ['accepted' => true, 'state' => 'legacy operation scheduled'] : ['outcome' => 'complete', 'ready' => !$stop])));
};
$action = new ReflectionMethod($panel, 'serverAction');
foreach (['opendream', 'legacy', 'native'] as $mode) {
    foreach (['Host', 'Kill', 'Restart'] as $method) {
        foreach (['success', 'rejected', 'unavailable'] as $outcome) {
            $reset($mode, $outcome); $i = $interaction(); $settled = false;
            $action->invoke($panel, $i, 'fixture', $method)->then(static function () use (&$settled) { $settled = true; });
            lifecycleConsumerCheck($server->requests === [] && $i->privateAcks === [true] && $i->replies === [], 'Panel controlled before private acknowledgement');
            $i->ack->resolve(null);
            lifecycleConsumerCheck($server->requests === [[$method, null]], 'Panel dropped or repeated control');
            if ($outcome !== 'unavailable') { lifecycleConsumerCheck(!$settled && $i->replies === [], 'Panel acknowledged unsettled control'); }
            $finish($mode, $outcome, stop: $method === 'Kill');
            lifecycleConsumerCheck($settled && count($i->replies) === 1, 'Panel lost terminal control result');
            $expected = $outcome !== 'success' ? 'failed or' : match ($mode) { 'opendream' => 'OpenDream process control completed; gameplay readiness has not been observed', 'legacy' => 'Legacy control scheduled; completion', default => 'local supervisor reports control completion' };
            lifecycleConsumerCheck(str_contains($i->replies[0], $expected), 'Panel confused completion scope or rejection');
            if ($mode === 'native' && $method === 'Kill' && $outcome === 'success') { lifecycleConsumerCheck(!str_contains($i->replies[0], 'Bound readiness reported'), 'Stopped native process reported ready'); }
        }
    }
}
// Existing staff checks and expired acknowledgements must prevent effects.
$reset('opendream', 'success'); $i = $interaction(); $i->fixtureMember = null;
$action->invoke($panel, $i, 'fixture', 'Restart');
lifecycleConsumerCheck($server->requests === [] && $i->privateAcks === [] && $i->initial[0][1] === true, 'Revoked staff member controlled a server');
$i = $interaction(); $failed = false;
$action->invoke($panel, $i, 'fixture', 'Restart')->then(null, static function () use (&$failed) { $failed = true; });
$i->ack->reject(new RuntimeException('Synthetic expired interaction'));
lifecycleConsumerCheck($failed && $server->requests === [] && $i->replies === [], 'Failed acknowledgement controlled a server');

$temporary = sys_get_temp_dir().'/bot-lifecycle-consumers-'.bin2hex(random_bytes(8));
mkdir($temporary); mkdir($temporary.'/code'); mkdir($temporary.'/code/__defines');
$mapsPath = $temporary.'/code/__defines/maps.dm'; file_put_contents($mapsPath, "TEST\n");
(new ReflectionProperty($bot, 'gitdir'))->setValue($bot, $temporary);
try {
    $prompt = $interaction();
    (new ReflectionMethod($panel, 'promptMapSwap'))->invoke($panel, $prompt, 'fixture');
    $select = $prompt->initial[0][0]->getComponents()[0]->getComponents()[1]->getComponents()[0];
    $listener = (new ReflectionProperty($select, 'listener'))->getValue($select);
    // Capture the real panel callback retained by DiscordPHP's inert component.
    $mapAction = (new ReflectionFunction($listener))->getStaticVariables()['callback'];
    foreach (['opendream', 'legacy'] as $mode) {
        foreach (['success', 'rejected', 'unavailable'] as $outcome) {
            $reset($mode, $outcome); $i = $interaction(); $settled = false;
            $mapAction($i)->then(static function () use (&$settled) { $settled = true; });
            lifecycleConsumerCheck($server->requests === [] && $i->privateAcks === [true], 'Map selection ran before private acknowledgement');
            $i->ack->resolve(null);
            if ($outcome !== 'unavailable') { lifecycleConsumerCheck(!$settled && $i->replies === [], 'Map selection acknowledged unsettled control'); }
            $finish($mode, $outcome, map: true);
            lifecycleConsumerCheck($settled && $server->requests === [['MapSwap', 'TEST']] && count($i->replies) === 1, 'Map selection lost its control result');
            lifecycleConsumerCheck(str_contains($i->replies[0], $outcome === 'success' ? ($mode === 'opendream' ? 'process control completed' : 'Legacy map swap scheduled') : 'failed or'), 'Map selection upgraded failure or readiness');
        }
        foreach (['success', 'rejected', 'unavailable'] as $outcome) {
            $reset($mode, $outcome); $bot->replies = []; $settled = false;
            $message = (new ReflectionClass(LifecycleConsumerMessage::class))->newInstanceWithoutConstructor();
            (new \Civ13\MessageCommand\Commands\Civ13GameServerMapSwap($bot, $server))($message, 'mapswap', ['message_content' => 'mapswap TEST'])->then(static function () use (&$settled) { $settled = true; });
            if ($outcome !== 'unavailable') { lifecycleConsumerCheck(!$settled && $message->reactions === [] && $bot->replies === [], 'Map command acknowledged unsettled control'); }
            $finish($mode, $outcome, map: true);
            lifecycleConsumerCheck($settled && $server->requests === [['MapSwap', 'TEST']] && count($bot->replies) === 1 && count($message->reactions) === 1, 'Map command lost typed result or rejection');
            lifecycleConsumerCheck(str_contains($bot->replies[0], $outcome === 'success' ? ($mode === 'opendream' ? 'process control completed' : 'Legacy map swap scheduled') : 'Synthetic'), 'Map command confused response scope');
        }
    }
    foreach ([null, []] as $denial) {
        $reset('opendream', 'success'); $i = $interaction();
        if ($denial === null) { $i->fixtureMember = null; } else { $i->values = []; }
        $mapAction($i);
        lifecycleConsumerCheck($server->requests === [] && $i->privateAcks === [] && $i->initial[0][1] === true, 'Invalid or unauthorized map selection controlled a server');
    }
    $reset('opendream', 'success'); $i = $interaction(); $failed = false;
    $mapAction($i)->then(null, static function () use (&$failed) { $failed = true; });
    $i->ack->reject(new RuntimeException('Synthetic expired selection'));
    lifecycleConsumerCheck($failed && $server->requests === [] && $i->replies === [], 'Failed selection acknowledgement controlled a server');
} finally { unlink($mapsPath); rmdir($temporary.'/code/__defines'); rmdir($temporary.'/code'); rmdir($temporary); }

echo "PASS lifecycle consumers: private acknowledgement before effects, deferred completion, process/supervisor/scheduling scopes, rejections, unavailable controls, typed map replies and preserved staff gates\n";
