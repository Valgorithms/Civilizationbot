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
    public static function fixture(string $action = 'stash.inspect', string $payload = '{"page":0}', ?int $revision = null, bool $confirm = false): self {
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
$missingPage = MockInteraction::fixture('stash.inspect', '{}');
$route->handle($missingPage);
check(count($gateway->requests) === 0 && $missingPage->messages[0][0] === 'initial', 'Missing inspection page reached transport');
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
check($gateway->requests[0]['payload'] === ['page' => 0], 'Discord inspection incompatible with native page schema');
$gateway->deferreds[0]->resolve(['status' => 'pending', 'request_id' => '123456']);
check(str_contains($inspect->messages[1][2]['content'], 'pending') && $inspect->messages[1][2]['allowed_mentions'] === ['parse' => []], 'Pending claimed success or allowed mentions');
check(str_contains($inspect->messages[1][2]['content'], $gateway->requests[0]['request_digest']), 'Pending omitted bound recovery digest');
$confirmed = MockInteraction::fixture('trade.confirm', '{"confirmation_token":"game-token"}', 4, true); $confirmed->id = '123457';
$route->handle($confirmed);
check($gateway->requests[1]['payload'] === ['confirmation_token' => 'game-token'], 'Confirmation changed opaque game token');
$gateway->deferreds[1]->resolve(['status' => 'committed', 'request_id' => '123457', 'data' => ['nested_id' => 'child', 'fir' => true, 'condition' => 0.75]]);
check(str_contains($confirmed->messages[1][2]['content'], 'child'), 'Inspection metadata omitted');
foreach (['trade.confirm','market.list','market.buy','market.cancel','room.manage'] as $action) {
    foreach ([false, 1, 'true'] as $confirmation) {
        $candidate=MockInteraction::fixture($action,'{"confirmation_token":"game-token"}',4,true);
        $candidate->data->options['confirm']->value=$confirmation;
        $before=count($gateway->requests);$route->handle($candidate);
        check(count($gateway->requests)===$before && $candidate->messages[0][0]==='initial','Nonboolean/omitted confirmation reached game '.$action);
    }
}
foreach (['unlinked_actor','account_busy','account_in_raid','item_not_owned','stale_revision','quote_expired','quote_actor_mismatch','quote_action_mismatch','listing_changed','account_recovery_required'] as $code) {
    $denied=MockInteraction::fixture('trade.confirm','{"confirmation_token":"game-token"}',4,true);$denied->id='negative-'.$code;
    $route->handle($denied);$before=count($gateway->requests);
    $gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status'=>'rejected','request_id'=>$denied->id,'reason'=>$code,'data'=>['code'=>$code]]);
    check(count($gateway->requests)===$before,'Rejected operation retried '.$code);
    $content=$denied->messages[1][2]['content'];
    check(str_contains($content,'rejected') && str_contains($content,$code) && !str_contains($content,'Game confirmed'),'Rejection falsely acknowledged '.$code);
}
$nested=MockInteraction::fixture('stash.detail','{"item_id":"root-1","page":0}');$nested->id='nested-preservation';$route->handle($nested);
$nodes=[['index'=>1,'id'=>'root-1','parent'=>0,'found_in_raid'=>false,'condition'=>['health'=>0,'maxhealth'=>100]],['index'=>2,'id'=>'child-2','parent'=>1,'found_in_raid'=>true,'condition'=>['health'=>17,'maxhealth'=>80]]];
$gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status'=>'committed','request_id'=>$nested->id,'reason'=>'saved_graph','data'=>['item_id'=>'root-1','nodes'=>$nodes,'next_page'=>null]]);
$content=$nested->messages[1][2]['content'];
check(str_contains($content,json_encode($nodes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'Distinct nested identity/FIR/zero condition changed');
$unicode = MockInteraction::fixture(); $unicode->id = 'unicode';
$route->handle($unicode);
$gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status' => 'committed', 'request_id' => 'unicode', 'data' => ['name' => str_repeat('🌍', 2000)]]);
check(preg_match('//u', $unicode->messages[1][2]['content']) === 1 && strlen($unicode->messages[1][2]['content']) <= 3600, 'Bounded render split Unicode');

$digest = str_repeat('a', 64);
foreach (['pending', 'not_found', 'committed', 'rejected', 'wrong_actor'] as $operationStatus) {
    $statusInteraction = MockInteraction::fixture('trade.status', json_encode(['operation_request_id' => 'original-operation', 'operation_request_digest' => $digest]));
    $statusInteraction->id = 'status-'.$operationStatus;
    $route->handle($statusInteraction);
    $data = ['operation_status' => $operationStatus === 'wrong_actor' ? 'committed' : $operationStatus];
    if (in_array($operationStatus, ['committed', 'rejected', 'wrong_actor'], true)) { $data['operation_receipt'] = ['protocol' => Client::PROTOCOL, 'action' => 'stash.detail', 'reason' => 'committed', 'data' => [], 'request_id' => 'original-operation', 'request_digest' => $digest, 'discord_id' => $operationStatus === 'wrong_actor' ? '999' : '123', 'status' => $data['operation_status']]; }
    $gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status' => 'committed', 'reason' => 'committed', 'data' => $data]);
    $content = $statusInteraction->messages[1][2]['content'];
    check(!str_contains($content, 'Game confirmed request'), 'Status query misreported original success');
    check(str_contains($content, $operationStatus === 'wrong_actor' ? 'unresolved' : $operationStatus), 'Original operation status omitted');
}
$validReceipt = ['protocol'=>Client::PROTOCOL,'action'=>'link.complete','request_id'=>'original-operation','request_digest'=>$digest,'discord_id'=>'123','status'=>'committed','reason'=>'linked','data'=>[]];
foreach (Client::ACTIONS as $originalAction) {
    $receipt=$validReceipt; $receipt['action']=$originalAction;
    $interaction=MockInteraction::fixture('trade.status',json_encode(['operation_request_id'=>'original-operation','operation_request_digest'=>$digest])); $route->handle($interaction);
    $gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status'=>'committed','data'=>['operation_status'=>'committed','operation_receipt'=>$receipt]]);
    check(str_contains($interaction->messages[1][2]['content'],'Original operation original-operation is committed'),'Supported non-economic original receipt rejected '.$originalAction);
}
foreach (['protocol'=>null,'action'=>'unknown.action','reason'=>null,'data'=>null,'status'=>'rejected','request_digest'=>str_repeat('b',64),'request_id'=>'other','discord_id'=>'999'] as $field=>$invalid) {
    foreach (['missing','invalid'] as $kind) {
        $receipt=$validReceipt; if ($kind==='missing') { unset($receipt[$field]); } else { $receipt[$field]=$invalid; }
        $interaction=MockInteraction::fixture('trade.status',json_encode(['operation_request_id'=>'original-operation','operation_request_digest'=>$digest])); $route->handle($interaction);
        $gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status'=>'committed','data'=>['operation_status'=>'committed','operation_receipt'=>$receipt]]);
        check(str_contains($interaction->messages[1][2]['content'],'unresolved'),'Malformed original receipt claimed completion '.$field.'/'.$kind);
    }
}
foreach (['',str_repeat('x',129),"bad\nreason","bad\u{0085}reason","bad\u{009f}reason",str_repeat("é",65),[],1,true] as $reason) {
    $receipt=$validReceipt; $receipt['reason']=$reason;
    $interaction=MockInteraction::fixture('trade.status',json_encode(['operation_request_id'=>'original-operation','operation_request_digest'=>$digest])); $route->handle($interaction);
    $gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status'=>'committed','data'=>['operation_status'=>'committed','operation_receipt'=>$receipt]]);
    check(str_contains($interaction->messages[1][2]['content'],'unresolved'),'Invalid reason accepted');
}
foreach ([str_repeat('x',128),str_repeat("é",64)] as $reason) {
    $receipt=$validReceipt; $receipt['reason']=$reason;
    $interaction=MockInteraction::fixture('trade.status',json_encode(['operation_request_id'=>'original-operation','operation_request_digest'=>$digest])); $route->handle($interaction);
    $gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status'=>'committed','data'=>['operation_status'=>'committed','operation_receipt'=>$receipt]]);
    check(str_contains($interaction->messages[1][2]['content'],'Original operation original-operation is committed'),'Exact reason byte bound rejected');
}
foreach (['pending','not_found'] as $state) {
    $interaction=MockInteraction::fixture('trade.status',json_encode(['operation_request_id'=>'original-operation','operation_request_digest'=>$digest])); $route->handle($interaction);
    $gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status'=>'committed','data'=>['operation_status'=>$state,'operation_receipt'=>$validReceipt]]);
    check(str_contains($interaction->messages[1][2]['content'],'unresolved'),'Nonterminal status accepted attached receipt');
}
foreach ([['stash.detail', '{"item_id":"native-root","page":31}', null], ['market.browse', '{"page":15}', null], ['room.quote', '{"operation":"upgrade","item_ids":["native-part"]}', 4]] as [$action, $payload, $revision]) {
    $observation = MockInteraction::fixture($action, $payload, $revision); $observation->id = 'addition-'.str_replace('.', '-', $action);
    $route->handle($observation);
    check(end($gateway->requests)['action'] === $action && $observation->messages[0] === ['defer', true], 'Additive action not routed ephemerally');
    $data = ['nodes' => [['index' => 1, 'id' => 'native-root', 'parent' => 0, 'found_in_raid' => true, 'condition' => ['health' => 20, 'maxhealth' => 100], 'material_key' => null, 'amount' => null, 'ammo_refs' => []]], 'next_page' => null];
    $gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status' => 'committed', 'reason' => 'committed', 'data' => $data]);
    check(str_contains($observation->messages[1][2]['content'], 'found_in_raid') && str_contains($observation->messages[1][2]['content'], 'maxhealth'), 'Authoritative node summary omitted');
}
$unconfirmedRoom = MockInteraction::fixture('room.manage', '{"confirmation_token":"native-room-token"}', 4);
$countBefore = count($gateway->requests); $route->handle($unconfirmedRoom);
check(count($gateway->requests) === $countBefore, 'Room mutation bypassed explicit confirmation');

$insuranceData = ['account_revision' => 4, 'mail_revision' => 9, 'completed_generation' => 5, 'expiration' => 'to be implemented', 'entries' => [['item_id' => 'return_1', 'type' => null, 'name' => null, 'condition' => ['health' => null, 'maxhealth' => null], 'found_in_raid' => null, 'returned_generation' => 5, 'eligible_generation' => 6, 'state' => 'pending']], 'next_cursor' => 'opaque.next'];
foreach (['pending', 'eligible', 'invalid', 'stale_cursor'] as $case) {
    $inspection = MockInteraction::fixture('insurance.inspect', '{"cursor":null}'); $inspection->id = 'insurance-'.$case;
    $route->handle($inspection);
    $data = $insuranceData;
    if ($case === 'eligible') { $data['entries'][0]['state'] = 'eligible'; }
    if ($case === 'invalid') { $data['entries'][0]['eligible_generation'] = 16777216; }
    $gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status' => $case === 'stale_cursor' ? 'rejected' : 'committed', 'data' => $case === 'stale_cursor' ? ['code' => 'stale_cursor'] : $data]);
    $content = $inspection->messages[1][2]['content'];
    check(str_contains($content, $case === 'invalid' ? 'invalid' : $case), 'Insurance state/rejection omitted');
    if ($case === 'pending' || $case === 'eligible') { check(str_contains($content, 'to be implemented') && str_contains($content, 'No Discord claim'), 'Insurance expiration or claim boundary misleading'); }
    check($inspection->messages[0] === ['defer', true], 'Insurance inspection not ephemeral');
}

// Construct only the Slash adapter over a reflection-created inert Civ13 object.
// No Civ13/Discord constructor, token, init event or remote command save runs.
require __DIR__.'/../../src/Civ13/Civ13.php';
require __DIR__.'/../../src/Civ13/Slash.php';
// Exercise the combined production options contract without constructing the bot.
// This catches either side of the main/safehouse defaults merge being dropped.
$optionsBot = (new ReflectionClass(\Civ13\Civ13::class))->newInstanceWithoutConstructor();
$resolveOptions = new ReflectionMethod(\Civ13\Civ13::class, 'resolveOptions');
$options = [];
$resolved = $resolveOptions->invokeArgs($optionsBot, [&$options]);
check($resolved['safehouse_enabled'] === false && $resolved['safehouse_gateway'] === null, 'Safehouse defaults activated integration');
check($resolved['restart_mode'] === 'legacy' && $resolved['discover_external_ip'] === true, 'Main startup defaults lost');
$options = ['safehouse_enabled' => true, 'safehouse_gateway' => $gateway, 'restart_mode' => 'supervised', 'discover_external_ip' => false];
$resolved = $resolveOptions->invokeArgs($optionsBot, [&$options]);
check($resolved['safehouse_gateway'] === $gateway && $resolved['restart_mode'] === 'supervised' && $resolved['discover_external_ip'] === false, 'Injected safehouse options conflict with portable startup');
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
$gateway->deferreds[array_key_last($gateway->deferreds)]->resolve(['status' => 'rejected', 'request_id' => '654321', 'reason' => 'unlinked']);
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
    $response = array_merge($request2, ['status' => 'rejected', 'reason' => 'in_raid', 'data' => ['code' => 'in_raid']]);
    $id = hash('sha256', 'timer-2');
    file_put_contents($directory.'/'.$id.'.reply', json_encode(AsyncSpool::seal(json_encode($response), 'reply', time(), time() + 120, str_repeat('synthetic-', 4))));
    $ticks[1](); check($outcome['status'] === 'rejected' && $cancelled[1], 'Game rejection not terminal');
    echo "PASS actual DiscordPHP interaction signatures, disabled/guild/actor/confirmation/acknowledgement gates, ephemeral no-mention outcomes, bounded async timers\n";
} finally { foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory); }
