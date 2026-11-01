<?php
declare(strict_types=1);
// Offline only: Composer autoload, inert transports, synthetic guild and temporary state.
require dirname(__DIR__, 2).'/vendor/autoload.php';
use Civ13\Calendar\{Visibility, Projection, Ledger, FileState, GatewaySync, Endpoint, PublicCalendar};
use React\Promise\Deferred;
use React\Http\Message\ServerRequest;
use function React\Promise\resolve;

$checks = 0;
function check(bool $ok, string $message): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($message); } }
function rejects(callable $call): void { global $checks; ++$checks; try { $call(); } catch (Throwable) { return; } throw new RuntimeException('Expected rejection'); }
const GUILD = '900000000000000001'; const ROLE = '900000000000000002'; const CHANNEL = '900000000000000003'; const EVENT = '900000000000000004';
const NOW = 1793577600;
function input(): array {
    return ['roles' => [['id' => GUILD, 'permissions' => '1024', 'managed' => false], ['id' => ROLE, 'permissions' => '0', 'managed' => false]],
        'channels' => [['id' => CHANNEL, 'guild_id' => GUILD, 'type' => 2, 'permission_overwrites' => []]],
        'events' => [['id' => EVENT, 'guild_id' => GUILD, 'channel_id' => CHANNEL, 'entity_type' => 2, 'privacy_level' => 2, 'status' => 1,
            'name' => 'Synthetic community round', 'description' => 'Fixture only <script>ignored</script> <@123456789012345678>',
            'scheduled_start_time' => '2026-11-01T01:30:00-04:00', 'scheduled_end_time' => null, 'recurrence_rule' => null,
            'creator_id' => '111111111111111111', 'user_count' => 42, 'creator' => ['username' => 'private'], 'attendees' => ['private']]]];
}
function project(array $data): array { return Projection::events(GUILD, $data['roles'], $data['channels'], $data['events'], [ROLE]); }
$base = input(); $baseline = Visibility::baseline(GUILD, $base['roles'], [ROLE]);
check(Visibility::bit('18446744073709552640', 10), 'Large decimal bits lost precision');
check(!Visibility::bit('18446744073709551616', 10), 'High bit contaminated VIEW_CHANNEL');
rejects(fn () => Visibility::bit('1e3', 10));
$channel = $base['channels'][0];
$channel['permission_overwrites'] = [['id' => GUILD, 'type' => 0, 'allow' => '0', 'deny' => '1024']];
check(Visibility::channel($channel, GUILD, 2, $baseline) === false, 'Everyone deny ignored');
$channel['permission_overwrites'][] = ['id' => ROLE, 'type' => 0, 'allow' => '1024', 'deny' => '0'];
check(Visibility::channel($channel, GUILD, 2, $baseline) === true, 'Onboarding allow order wrong');
$channel['permission_overwrites'][] = ['id' => '900000000000000009', 'type' => 0, 'allow' => '0', 'deny' => '1024'];
check(Visibility::channel($channel, GUILD, 2, $baseline) === true, 'Unrelated role applied');
$channel['permission_overwrites'][1]['allow'] = 'bad';
check(Visibility::channel($channel, GUILD, 2, $baseline) === null, 'Malformed overwrite accepted');
$staff = $base['roles']; $staff[1]['permissions'] = '8'; rejects(fn () => Visibility::baseline(GUILD, $staff, [ROLE]));
$missing = $base; $missing['channels'] = []; check(project($missing)['events'] === [], 'Missing channel disclosed');
$hidden = $base; $hidden['channels'][0]['permission_overwrites'] = [['id' => GUILD, 'type' => 0, 'allow' => '0', 'deny' => '1024']];
check(project($hidden) === ['events' => [], 'notes' => []], 'Restricted details or count disclosed');
$external = $base; $external['events'][0]['entity_type'] = 3; $external['events'][0]['channel_id'] = null; $external['channels'] = [];
check(count(project($external)['events']) === 1, 'External ordinary-member event omitted');
$stage = $base; $stage['events'][0]['entity_type'] = 1; $stage['channels'][0]['type'] = 13;
check(count(project($stage)['events']) === 1, 'Public stage event omitted');
$projection = project($base); $row = $projection['events'][0];
check($row['end_at'] === null && $row['start_at'] === '2026-11-01T05:30:00Z', 'Missing end or explicit offset changed');
check(!str_contains(json_encode($projection), 'private') && !str_contains($row['description'], '<@'), 'Personal/attendee fields disclosed');
$recurring = $base; $recurring['events'][0]['recurrence_rule'] = ['frequency' => 2, 'count' => 10];
check(project($recurring)['notes'] === ['recurrence_not_expanded'] && project($recurring)['events'][0]['recurrence'] === 'discord_only', 'Recurrence silently represented as complete');
$bad = $base; $bad['events'][0]['scheduled_start_time'] = '2026-02-30T01:00:00Z';
check(project($bad)['events'] === [] && project($bad)['notes'] !== [], 'Invalid visible event silently complete');
rejects(fn () => Projection::instant('2026-11-01T01:30:00')); rejects(fn () => Projection::instant('2026-11-01T01:30:00-00:00'));
rejects(fn () => Projection::instant('2026-11-01T24:00:00Z')); rejects(fn () => Projection::instant('2026-11-01T01:30:00+99:00'));
$age = $base['channels'][0]; $age['nsfw'] = true; check(Visibility::channel($age, GUILD, 2, $baseline) === null, 'Age restriction assumed satisfied');

$durable = []; $ledger = new Ledger(GUILD, [], function (array $state) use (&$durable): void { $durable = $state; });
$ledger->replace($projection, NOW); $first = $ledger->snapshot(NOW); $uid = $first['events'][0]['uid'];
check($first['state'] === 'complete' && $first['events'][0]['sequence'] === 0, 'Initial ledger wrong');
$ledger->replace($projection, NOW + 1); check($ledger->snapshot(NOW + 1)['events'][0]['sequence'] === 0, 'Duplicate advanced event revision');
$projection['events'][0]['title'] = 'Changed public name'; $ledger->replace($projection, NOW + 2);
check($ledger->snapshot(NOW + 2)['events'][0]['sequence'] === 1, 'Edit revision not durable');
$ledger = new Ledger(GUILD, $durable, function (array $state) use (&$durable): void { $durable = $state; });
$ledger->replace(project($hidden), NOW + 3); $withdrawal = $ledger->snapshot(NOW + 3)['events'][0];
check($withdrawal['uid'] === $uid && $withdrawal['sequence'] === 2 && $withdrawal['status'] === 'withdrawn', 'UID/revision/withdrawal lost on recovery');
check(!str_contains(json_encode($durable), 'Changed public name') && !str_contains(json_encode($withdrawal), 'Synthetic community'), 'Restricted historical details retained');
$ledger->replace(project($base), NOW + 4); check($ledger->snapshot(NOW + 4)['events'][0]['sequence'] === 3, 'Reappearance reused old revision');
check($ledger->snapshot(NOW + 124)['events'] === [] && $ledger->snapshot(NOW - 1)['events'] === [], 'Expired or future snapshot served');
$ledger->invalidate(NOW + 5); $ledger->renew(NOW + 6); check($ledger->snapshot(NOW + 6)['events'] === [], 'Heartbeat revived invalidated details');
rejects(fn () => new Ledger(GUILD, ['version' => 99], static fn () => null));
$failing = new Ledger(GUILD, [], static function (): void { throw new RuntimeException('disk full'); });
rejects(fn () => $failing->replace(project($base), NOW)); check($failing->snapshot(NOW) === null, 'Failed write exposed new snapshot');

final class Emitter { public array $handlers = []; public array $options = []; public object $guilds; public function on($event, $fn): void { $this->handlers[$event][] = $fn; } public function emit($event, array $args = []): void { foreach ($this->handlers[$event] ?? [] as $fn) { $fn(...$args); } } public function removeListener($event, $fn): void { $this->handlers[$event] = array_filter($this->handlers[$event] ?? [], fn ($candidate) => $candidate !== $fn); } }
final class Timers { public array $timers = []; public function addTimer($delay, $fn): object { $timer = (object)['delay' => $delay, 'fn' => $fn]; $this->timers[spl_object_id($timer)] = $timer; return $timer; } public function cancelTimer($timer): void { unset($this->timers[spl_object_id($timer)]); } public function fire(float $delay): void { foreach ($this->timers as $id => $timer) { if ($timer->delay === $delay) { unset($this->timers[$id]); ($timer->fn)(); } } } }
$emitter = new Emitter(); $loop = new Timers(); $requests = []; $current = true; $now = NOW;
$ledger = new Ledger(GUILD, [], static fn () => null);
$sync = new GatewaySync($emitter, $loop, $ledger, GUILD, [ROLE], function () use (&$requests) { $d = new Deferred(); $requests[] = $d; return $d->promise(); }, function () use (&$current) { return $current; }, function () use (&$now) { return $now; });
$emitter->emit('init'); $loop->fire(0.2); check(count($requests) === 1, 'Init did not use existing-client refresh');
$emitter->emit('raw', [(object)['op' => 0, 't' => 'CHANNEL_UPDATE', 'd' => (object)['guild_id' => GUILD]]]);
check($ledger->snapshot(NOW)['events'] === [], 'Raw privacy change did not withdraw before handlers');
$requests[0]->resolve($base); check($ledger->snapshot(NOW)['events'] === [], 'Older refresh resurrected details');
$loop->fire(0.2); check(count($requests) === 2, 'Dirty refresh did not wait for prior drain');
$requests[1]->resolve($base); check(count($ledger->snapshot(NOW)['events']) === 1, 'Current refresh did not publish');
$emitter->emit('heartbeat-ack'); check(count($requests) === 2, 'Heartbeat polled Discord');
$emitter->emit('raw', [(object)['op' => 0, 't' => 'GUILD_ROLE_UPDATE', 'd' => (object)['guild_id' => GUILD]]]); $loop->fire(0.2); $loop->fire(30.0);
check($ledger->snapshot(NOW)['events'] === [], 'Timeout retained details');
$emitter->emit('reconnected'); $loop->fire(0.2); check(count($requests) === 3, 'Timed-out request overlapped before draining');
$requests[2]->resolve($base); $loop->fire(0.2); check(count($requests) === 4, 'Reconnect lost after late drain');
$requests[3]->resolve($base); $current = false; $emitter->emit('heartbeat-ack');
check($ledger->snapshot(NOW)['events'] === [], 'Changed role configuration retained details');
$sync->stop(); check($ledger->snapshot(NOW)['events'] === [], 'Stop exposed last-known private details');
check(array_sum(array_map('count', $emitter->handlers)) === 0, 'Stop leaked listeners');
check(PublicCalendar::attach(new stdClass(), ['enabled' => false]) === null, 'Disabled attachment read app or state');

// Real React promises exercise the repository adapter; no network-capable Discord client exists.
$drains = [];
$repository = static function (string $name) use (&$drains) {
    return new class($name, $drains) {
        public object $cache; public array $queries = []; private mixed $drains;
        public function __construct(private string $name, array &$drains) { $this->drains = &$drains; $this->cache = new class { public function getMultiple($keys) { return resolve([]); } }; }
        public function freshen(array $query = []) { $this->queries[] = $query; $d = new Deferred(); $this->drains[$this->name] = $d; return $d->promise(); }
        public function keys(): array { return []; }
    };
};
$guild = (object)['roles' => $repository('roles'), 'channels' => $repository('channels'), 'guild_scheduled_events' => $repository('events')];
$settled = false;
GatewaySync::refresh($guild)->then(static function () { throw new RuntimeException('Failed repository accepted'); }, function () use (&$settled) { $settled = true; });
$drains['roles']->reject(new RuntimeException('rate limited')); check(!$settled, 'Failed branch released other in-flight owners');
$drains['channels']->resolve(null); check(!$settled, 'Pending event list was not drained');
$drains['events']->resolve(null); check($settled, 'Failed complete refresh was not rejected');
check($guild->guild_scheduled_events->queries === [['with_user_count' => false]], 'Attendee counts requested');
foreach ([\Discord\Repository\Guild\ScheduledEventRepository::class, \Discord\Repository\Guild\RoleRepository::class, \Discord\Repository\Guild\ChannelRepository::class] as $class) { check(method_exists($class, 'freshen'), 'Locked repository contract absent'); }
check(method_exists(\Discord\Parts\Guild\ScheduledEvent\ScheduledEvent::class, 'getRawAttributes'), 'Locked raw projection seam absent');

$fresh = new Ledger(GUILD, [], static fn () => null); $fresh->replace(project($base), NOW);
$endpoint = new Endpoint('calendar.example.invalid', fn () => $fresh->snapshot(NOW));
$url = 'https://calendar.example.invalid'.Endpoint::PATH;
check($endpoint->handle(new ServerRequest('GET', $url))->getStatusCode() === 200, 'Public snapshot unavailable');
check($endpoint->handle(new ServerRequest('HEAD', $url))->getBody()->getSize() === 0, 'HEAD exposed body');
check($endpoint->handle(new ServerRequest('POST', $url))->getStatusCode() === 405, 'Mutation method accepted');
check($endpoint->handle(new ServerRequest('GET', $url.'?attendees=1'))->getStatusCode() === 404, 'Caller supplied query reached source');
check($endpoint->handle(new ServerRequest('GET', 'https://other.example'.Endpoint::PATH))->getStatusCode() === 404, 'Authority guard failed');
$fresh->invalidate(NOW + 1); check($endpoint->handle(new ServerRequest('GET', $url))->getStatusCode() === 503, 'Stale endpoint returned complete');

$root = sys_get_temp_dir().'/civ13-calendar-'.bin2hex(random_bytes(8)); mkdir($root, 0700);
try {
    $settings = ['enabled' => true, 'public_http' => false, 'state_file' => $root.'/composed.json', 'onboarding_role_keys' => ['Verified']];
    $client = new Emitter(); $client->options = ['intents' => \Discord\WebSockets\Intents::GUILDS];
    $app = (object)['civ13_guild_id' => GUILD, 'role_ids' => ['Verified' => ROLE], 'discord' => $client, 'loop' => new Timers(), 'ready' => false, 'options' => ['calendar_sync' => $settings]];
    rejects(fn () => PublicCalendar::attach($app, $settings));
    check(!file_exists($settings['state_file']), 'Missing-intent client touched durable state');
    $client->options['intents'] |= \Discord\WebSockets\Intents::GUILD_SCHEDULED_EVENTS;
    $repos = [];
    foreach (['roles' => $base['roles'], 'channels' => $base['channels'], 'guild_scheduled_events' => $base['events']] as $name => $rows) {
        $repos[$name] = new class($rows) {
            public object $cache; public int $calls = 0;
            public function __construct(private array $rows) { $this->cache = new class($rows) { public function __construct(private array $rows) {} public function getMultiple(array $keys) { return resolve(array_map(static fn ($row) => new class($row) { public function __construct(private array $row) {} public function getRawAttributes(): array { return $this->row; } }, $this->rows)); } }; }
            public function freshen(array $query = []) { ++$this->calls; return resolve($this); }
            public function keys(): array { return array_column($this->rows, 'id'); }
        };
    }
    $client->guilds = new class((object)$repos) { public function __construct(private object $guild) {} public function get($key, $id) { return $id === GUILD ? $this->guild : null; } };
    $composition = PublicCalendar::attach($app, $settings);
    check($composition !== null && !$composition->exposesHttp(), 'Inactive HTTP composition exposed route');
    $client->emit('init'); $app->loop->fire(0.2);
    check(count($composition->snapshot()['events']) === 1, 'Actual source composition failed with inert existing repositories');
    check(array_sum(array_map(static fn ($repo) => $repo->calls, $repos)) === 3, 'Source duplicated client/inventory reads');
    $app->role_ids['Verified'] = '900000000000000099';
    check($composition->snapshot()['events'] === [], 'Request-time config mutation retained details');
    check(array_sum(array_map('count', $client->handlers)) === 0, 'Config invalidation retained source listeners');
    $composition->stop(); unset($composition);
    $file = new FileState($root.'/ledger.json'); $file->write($durable); check($file->read() === $durable, 'File transaction changed durable state');
    rejects(fn () => new FileState($root.'/ledger.json'));
    unset($file); $file = new FileState($root.'/ledger.json');
    $recovered = new Ledger(GUILD, $file->read(), fn ($state) => $file->write($state)); $recovered->invalidate(NOW + 6);
    check($recovered->snapshot(NOW + 6)['events'] === [], 'Process startup reused old lease');
    unset($recovered, $file);
    $child = proc_open([PHP_BINARY, __DIR__.'/recover.php', $root.'/ledger.json'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($child), 'Fresh-process recovery worker unavailable');
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($child) === 0 && $errors === '', 'Fresh-process recovery did not exit cleanly');
    $restarted = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    check($restarted['events'][0]['uid'] === $uid && $restarted['events'][0]['sequence'] === 4 && $restarted['events'][0]['status'] === 'withdrawn', 'Fresh process reset identity or cancellation revision');
} finally { foreach (glob($root.'/*') as $path) { unlink($path); } rmdir($root); }

$fixture = new Ledger(GUILD, [], static fn () => null); $fixture->replace(project($base), NOW);
$json = json_encode($fixture->snapshot(NOW), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
if (($argv[1] ?? '') === '--write-fixture') { file_put_contents(__DIR__.'/snapshot.fixture.json', $json); }
else { check(file_get_contents(__DIR__.'/snapshot.fixture.json') === $json, 'Cross-repository producer fixture stale'); }
echo "Calendar offline assertions: $checks passed; no client, environment, server or Discord execution\n";
