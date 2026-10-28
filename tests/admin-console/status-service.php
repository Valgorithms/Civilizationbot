<?php

declare(strict_types=1);

require __DIR__.'/../../src/Civ13/AdminConsole/AdminServerProjection.php';
require __DIR__.'/../../src/Civ13/AdminConsole/CachedObservationAdapter.php';
require __DIR__.'/../../src/Civ13/AdminConsole/ReadOnlyStatusService.php';

use Civ13\AdminConsole\ReadOnlyStatusService;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks; ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
}
$actor = '12345678901234567';
$inventory = [['id' => 'visible', 'name' => 'Visible fixture', 'provider' => 'civ13.com'],
    ['id' => 'hidden', 'name' => 'PRIVATE HIDDEN NAME', 'provider' => 'valzargaming.com']];
$now = 100.0; $reads = []; $decisions = []; $access = true; $available = true;
$clock = static function () use (&$now): float { return $now; };
$captures = static function (string $id) use (&$reads): array {
    $reads[] = $id;
    return ['directory' => null, 'heartbeat' => null, 'token' => 'never-copy'];
};
$authorize = static function (string $user, string $id) use (&$decisions, &$access, &$available, $actor): ?bool {
    $decisions[] = [$user, $id];
    return !$available ? null : $access && $user === $actor && $id === 'visible';
};
$service = new ReadOnlyStatusService($inventory, 'clock-1', $clock, $captures, $authorize);
$result = $service->read($actor);
check($result['status'] === 200, 'Authenticated authorized inventory');
check(count($result['body']['servers']) === 1 && $reads === ['visible'], 'Unauthorized target never read');
check($result['body']['servers'][0]['projection']['state'] === 'unknown', 'Missing captures remain unknown');
check(!str_contains(json_encode($result), 'PRIVATE') && !str_contains(json_encode($result), 'never-copy'), 'Hidden names and raw input excluded');
check(count($decisions) === 3, 'Per-target admission and pre-response recheck');
check($result['body']['servers'][0]['projection']['capabilities']['restart'] === false, 'Controls unavailable');
$before = count($reads); $access = false;
check($service->read($actor)['status'] === 403 && count($reads) === $before, 'Revocation on next request; no observation reads');
$access = true; $available = false;
check($service->read($actor)['status'] === 503 && count($reads) === $before, 'Cache readiness unavailable fails closed');
$available = true;
check($service->read('request-supplied-name')['status'] === 401 && count($reads) === $before, 'Only validated session actor shape');
$unconfigured = new ReadOnlyStatusService($inventory, 'clock-1', $clock, $captures);
check($unconfigured->read($actor)['status'] === 503 && count($reads) === $before, 'No installed authorizer fails closed');
$empty = new ReadOnlyStatusService([], 'clock-1', $clock, $captures, $authorize);
check($empty->read($actor)['status'] === 403, 'Empty inventory is not an authorization grant');
$throws = new ReadOnlyStatusService($inventory, 'clock-1', $clock, $captures,
    static function (): bool { throw new RuntimeException('private-policy-details'); });
$result = $throws->read($actor);
check($result['status'] === 503 && !str_contains(json_encode($result), 'private-policy'), 'Policy exception hidden');
$revoked = new ReadOnlyStatusService($inventory, 'clock-1', $clock,
    static function () use (&$access): array { $access = false; return ['directory' => null, 'heartbeat' => null]; }, $authorize);
check($revoked->read($actor)['status'] === 403, 'Revocation before response drops all prepared output');
$access = true;
$badCaptures = new ReadOnlyStatusService($inventory, 'clock-1', $clock, static fn (): array => ['directory' => 'bad'], $authorize);
check($badCaptures->read($actor)['status'] === 503, 'Malformed capture boundary is unavailable');
$now = NAN;
check($service->read($actor)['status'] === 503, 'Invalid clock refuses snapshot');
$now = 100.0;
$numeric = new ReadOnlyStatusService([['id' => '123', 'name' => 'Numeric ID', 'provider' => 'civ13.com']], 'clock-1', $clock,
    static fn (string $id): array => ['directory' => null, 'heartbeat' => null],
    static fn (string $user, string $id): bool => $id === '123');
check($numeric->read($actor)['body']['servers'][0]['projection']['server_id'] === '123', 'Numeric registry ID stays a string');
foreach ([[$inventory[0], $inventory[0]], [array_merge($inventory[0], ['token' => 'forbidden'])],
    [array_replace($inventory[0], ['provider' => 'evil.invalid'])], [array_replace($inventory[0], ['name' => "bad\nname"])],
    array_fill(0, 33, $inventory[0]), ['named' => $inventory[0]]] as $invalid) {
    $rejected = false;
    try { new ReadOnlyStatusService($invalid, 'clock-1', $clock, $captures, $authorize); }
    catch (InvalidArgumentException) { $rejected = true; }
    check($rejected, 'Invalid/duplicate/unbounded registry rejected');
}
// One real normalized cached heartbeat through the complete service pipeline.
$withHeartbeat = new ReadOnlyStatusService([$inventory[0]], 'clock-1', $clock,
    static fn (): array => ['directory' => null, 'heartbeat' => ['server_id' => 'visible', 'clock_scope' => 'clock-1',
        'sampled_at' => 90.0, 'observation' => ['state' => 'advancing', 'age' => 10.0, 'data' => 'private']]], $authorize);
$view = $withHeartbeat->read($actor)['body']['servers'][0]['projection'];
check($view['state'] === 'unknown' && $view['evidence']['heartbeat']['state'] === 'advancing', 'Independent heartbeat survives service normalization');
check($view['ready'] === null && $view['evidence']['readiness']['state'] === 'unknown', 'No readiness inferred by service');
echo "Read-only status service contracts passed ($checks checks)\n";
