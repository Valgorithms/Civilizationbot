<?php
declare(strict_types=1);
// Composer autoload alone, never bot.php or tests/bootstrap.php/.env.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
foreach (['Transport', 'Client', 'Controller', 'AsyncSpool', 'AsyncGateway', 'AsyncDriver', 'ReactTimer', 'DiscordRoute'] as $class) {
    require __DIR__.'/../../src/Civ13/Safehouse/'.$class.'.php';
}
use Civ13\Safehouse\{AsyncGateway, AsyncDriver, AsyncSpool, Client, Controller, DiscordRoute};
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use React\Promise\{Deferred, PromiseInterface};
use function React\Promise\resolve;
use function React\Promise\reject;
function check(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
class MockInteraction extends Interaction
{
    public $id = '123456';
    public $guild_id = '456';
    public $member;
    public $data;
    public array $messages = [];
    public bool $failAck = false;
    public static function fixture(string $action = 'stash.inspect', string $payload = '{}', ?int $revision = null, bool $confirm = false): self {
        $fixture = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $fixture->member = (object) ['id' => '123'];
        $fixture->data = (object) ['options' => ['action' => (object) ['value' => $action], 'payload' => (object) ['value' => $payload], 'confirm' => (object) ['value' => $confirm]]];
        if ($revision !== null) { $fixture->data->options['revision'] = (object) ['value' => $revision]; }
        return $fixture;
    }
    public function respondWithMessage(MessageBuilder|string $builder, bool $ephemeral = false): PromiseInterface {
        $this->messages[] = ['initial', $ephemeral, $builder instanceof MessageBuilder ? $builder->jsonSerialize() : $builder];
        return resolve(null);
    }
    public function acknowledgeWithResponse(bool $ephemeral = false): PromiseInterface {
        $this->messages[] = ['defer', $ephemeral];
        return $this->failAck ? reject(new RuntimeException('Expired interaction')) : resolve(null);
    }
    public function updateOriginalResponse(MessageBuilder $builder): PromiseInterface {
        $this->messages[] = ['edit', true, $builder->jsonSerialize()];
        return resolve(null);
    }
}
$gateway = new class implements AsyncGateway {
    public array $requests = [];
    public array $deferreds = [];
    public function submit(array $request): PromiseInterface {
        $this->requests[] = $request;
        $deferred = new Deferred(); $this->deferreds[] = $deferred;
        return $deferred->promise();
    }
};
$disabled = MockInteraction::fixture();
(new DiscordRoute($gateway, '456'))->handle($disabled);
check(count($gateway->requests) === 0 && $disabled->messages[0][1], 'Disabled route sent request or leaked response');
$route = new DiscordRoute($gateway, '456', true);
$wrongGuild = MockInteraction::fixture(); $wrongGuild->guild_id = '999';
$route->handle($wrongGuild);
check(count($gateway->requests) === 0, 'Wrong guild reached game');
$unconfirmed = MockInteraction::fixture('trade.confirm', '{"confirmation_token":"game-token"}', 4);
$route->handle($unconfirmed);
check(count($gateway->requests) === 0 && str_contains($unconfirmed->messages[0][2]['content'], 'confirm'), 'Mutation omitted explicit confirmation');
$forged = MockInteraction::fixture('trade.quote', '{"discord_id":"999"}', 4);
$route->handle($forged);
check(count($gateway->requests) === 0, 'Actor option reached game');
$expired = MockInteraction::fixture(); $expired->failAck = true;
$route->handle($expired)->then(null, static fn () => null);
check(count($gateway->requests) === 0, 'Failed Discord acknowledgement reached game');
$inspect = MockInteraction::fixture();
$route->handle($inspect);
check($inspect->messages === [['defer', true]], 'Inspection did not defer ephemerally');
check($gateway->requests[0]['discord_id'] === '123', 'Actor not bound to Discord member');
$gateway->deferreds[0]->resolve(['status' => 'pending', 'request_id' => '123456']);
check(str_contains($inspect->messages[1][2]['content'], 'pending') && $inspect->messages[1][2]['allowed_mentions'] === ['parse' => []], 'Pending claimed success or allowed mentions');
$confirmed = MockInteraction::fixture('trade.confirm', '{"confirmation_token":"game-token"}', 4, true); $confirmed->id = '123457';
$route->handle($confirmed);
check($gateway->requests[1]['payload'] === ['confirmation_token' => 'game-token'], 'Confirmation changed opaque game token');
$gateway->deferreds[1]->resolve(['status' => 'committed', 'request_id' => '123457', 'data' => ['nested_id' => 'child', 'fir' => true, 'condition' => 0.75]]);
check(str_contains($confirmed->messages[1][2]['content'], 'child'), 'Inspection metadata omitted');
$unicode = MockInteraction::fixture(); $unicode->id = 'unicode';
$route->handle($unicode);
$gateway->deferreds[2]->resolve(['status' => 'committed', 'request_id' => 'unicode', 'data' => ['name' => str_repeat('🌍', 2000)]]);
check(preg_match('//u', $unicode->messages[1][2]['content']) === 1 && strlen($unicode->messages[1][2]['content']) <= 3600, 'Bounded render split Unicode');

// Construct only the Slash adapter over a reflection-created inert Civ13 object.
// No Civ13/Discord constructor, token, init event or remote command save runs.
require __DIR__.'/../../src/Civ13/Civ13.php';
require __DIR__.'/../../src/Civ13/Slash.php';
class MockDiscord extends \Discord\Discord
{
    public array $bound = [];
    public function listenCommand($names, ?callable $callback = null, ?callable $autocomplete_callback = null): \Discord\Helpers\RegisteredCommand {
        $name = is_array($names) ? $names[0] : $names;
        $this->bound[$name] = $callback;
        return new \Discord\Helpers\RegisteredCommand($this, $name, $callback, $autocomplete_callback);
    }
}
$bareBot = function (bool $enabled) use ($gateway): \Civ13\Civ13 {
    $bot = (new ReflectionClass(\Civ13\Civ13::class))->newInstanceWithoutConstructor();
    $bot->discord = (new ReflectionClass(MockDiscord::class))->newInstanceWithoutConstructor();
    $bot->logger = new \Monolog\Logger('offline-safehouse');
    $bot->ready = false;
    $bot->civ13_guild_id = '456';
    $bot->options = ['safehouse_enabled' => $enabled, 'safehouse_gateway' => $gateway];
    return $bot;
};
$disabledBot = $bareBot(false); new \Civ13\Slash($disabledBot);
check(!isset($disabledBot->discord->bound['safehouse']) && isset($disabledBot->discord->bound['ping']), 'Disabled Slash route changed existing command');
$enabledBot = $bareBot(true); new \Civ13\Slash($enabledBot);
check(isset($enabledBot->discord->bound['safehouse']) && isset($enabledBot->discord->bound['ping']), 'Enabled Slash route missing or replaced existing command');
$boundInteraction = MockInteraction::fixture(); $boundInteraction->id = '654321';
($enabledBot->discord->bound['safehouse'])($boundInteraction);
check(end($gateway->requests)['request_id'] === '654321', 'Slash callback did not use actual safehouse route');
$gateway->deferreds[3]->resolve(['status' => 'rejected', 'request_id' => '654321', 'reason' => 'unlinked']);
check(str_contains($boundInteraction->messages[1][2]['content'], 'unlinked'), 'Authoritative unlinked rejection omitted');

// Fake timer driver checks finite asynchronous polling and cancellation.
$directory = sys_get_temp_dir().'/civ13-discord-safehouse-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$ticks = []; $cancelled = [];
$repeat = function (float $interval, callable $tick) use (&$ticks, &$cancelled): Closure {
    $index = count($ticks); $ticks[$index] = $tick;
    return function () use ($index, &$cancelled): void { $cancelled[$index] = true; };
};
// Exercise the installed React loop's real addPeriodicTimer/cancelTimer contract
// in this isolated test process, with no shared bot loop or external effects.
$loop = new \React\EventLoop\StreamSelectLoop();
$realTicks = 0; $cancelRealTimer = null;
$cancelRealTimer = \Civ13\Safehouse\ReactTimer::repeat($loop)(0.001, function () use (&$realTicks, &$cancelRealTimer): void {
    if (++$realTicks === 3) { $cancelRealTimer(); }
});
$loop->run();
check($realTicks === 3, 'Actual React timer cancellation contract failed');
try {
    $spool = new AsyncSpool($directory, str_repeat('synthetic-', 4), true);
    $driver = new AsyncDriver($spool, $repeat, 2, 1);
    $request = Client::envelope('timer-1', '123', 'stash.inspect', [], null);
    $outcome = null;
    $promise = $driver->submit($request);
    check($driver->submit($request) === $promise && count($ticks) === 1, 'Duplicate created second timer');
    try { $driver->submit(array_merge($request, ['discord_id' => '999'])); throw new RuntimeException('Active collision accepted'); } catch (LogicException $e) {}
    try { $driver->submit(Client::envelope('capacity', '123', 'stash.inspect', [], null)); throw new LogicException('Active capacity accepted'); } catch (RuntimeException $e) { check($e->getMessage() === 'Async request capacity reached', 'Wrong capacity rejection'); }
    $promise->then(function ($value) use (&$outcome): void { $outcome = $value; });
    $ticks[0](); check($outcome === null, 'Missing reply settled early');
    $ticks[0](); check($outcome['status'] === 'pending' && $cancelled[0], 'Timeout did not cancel/preserve pending');
    $request2 = Client::envelope('timer-2', '123', 'stash.inspect', [], null);
    $driver->submit($request2)->then(function ($value) use (&$outcome): void { $outcome = $value; });
    $response = array_merge($request2, ['status' => 'rejected', 'reason' => 'in_raid']);
    $id = hash('sha256', 'timer-2');
    file_put_contents($directory.'/'.$id.'.reply', json_encode(AsyncSpool::seal(json_encode($response), 'reply', time(), time() + 120, str_repeat('synthetic-', 4))));
    $ticks[1](); check($outcome['status'] === 'rejected' && $cancelled[1], 'Game rejection not terminal');
    echo "PASS actual DiscordPHP interaction signatures, disabled/guild/actor/confirmation/acknowledgement gates, ephemeral no-mention outcomes, bounded async timers\n";
} finally { foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory); }
