<?php

declare(strict_types=1);

require __DIR__.'/../../src/Civ13/AdminConsole/ReadOnlyStatusService.php';
require __DIR__.'/../../src/Civ13/AdminConsole/PreparedCommandIntent.php';
require __DIR__.'/../../src/Civ13/AdminConsole/CommandIntentPreparer.php';

use Civ13\AdminConsole\CommandIntentPreparer;
use Civ13\AdminConsole\PreparedCommandIntent;

$checks = 0;
function check(bool $ok, string $label): void
{
    global $checks; ++$checks;
    if (!$ok) { throw new RuntimeException($label); }
}
$actor = '12345678901234567';
$calls = []; $access = true; $available = true;
$policy = static function (string $user, string $target, string $action) use (&$calls, &$access, &$available): ?bool {
    $calls[] = [$user, $target, $action];
    return $available ? $access : null;
};
$service = new CommandIntentPreparer(['nomads', 'tdm', '123'], $policy);
$request = ['target_id' => 'nomads', 'action' => 'restart', 'request_id' => '12345678-1234-4234-8234-123456789abc'];
$first = $service->prepare($actor, $request);
check($first['status'] === 200, 'Authorized typed restart preparation');
check($first['body']['state'] === 'prepared_not_executable' && $first['body']['execution_enabled'] === false, 'Never execution permission');
check($calls === [[$actor, 'nomads', 'restart']], 'Explicit action-scoped policy');
check(!isset($first['body']['accepted']) && !isset($first['body']['completed']) && !isset($first['body']['operation_id']), 'No queue or operation receipt');
check($service->prepare($actor, $request) === $first && count($calls) === 2, 'Same request produces stable intent but policy is called anew');
$access = false;
check($service->prepare($actor, $request)['status'] === 403, 'Retry does not retain authorization');
$access = true;
$second = $service->prepare($actor, array_replace($request, ['target_id' => 'tdm']));
check($second['body']['deduplication_key'] === $first['body']['deduplication_key'], 'Actor request key spans targets for conflict detection');
check($second['body']['payload_fingerprint'] !== $first['body']['payload_fingerprint'], 'Changed target has conflict fingerprint');
$third = $service->prepare($actor, array_replace($request, ['request_id' => '12345678-1234-4234-8234-123456789abd']));
check($third['body']['deduplication_key'] !== $first['body']['deduplication_key'], 'New request key differs');
check($third['body']['payload_fingerprint'] === $first['body']['payload_fingerprint'], 'Same payload independent of request key');
$other = $service->prepare('22345678901234567', $request);
check($other['body']['deduplication_key'] !== $first['body']['deduplication_key'] && $other['body']['payload_fingerprint'] !== $first['body']['payload_fingerprint'], 'Actor identity bound to both hashes');
check($service->prepare($actor, array_replace($request, ['target_id' => '123']))['body']['target_id'] === '123', 'Numeric target identity remains a string');
check($service->prepare($actor, array_replace($request, ['target_id' => 'unknown']))['status'] === 403, 'Unknown target cannot be authorized by broad policy');
$before = count($calls);
check($service->prepare('name-not-session-id', $request)['status'] === 401 && count($calls) === $before, 'Malformed actor never calls policy');
foreach (['save', 'verb', 'save_shutdown', 'status', 'Restart', 'restart;echo'] as $action) {
    check($service->prepare($actor, array_replace($request, ['action' => $action]))['status'] === 422, 'Only fixed restart intent supported');
}
foreach (['url', 'actor', 'authorization', 'operation_id', 'map', 'arguments'] as $extra) {
    check($service->prepare($actor, $request + [$extra => 'never-copy'])['status'] === 400, 'Reject surplus fields');
}
foreach (['', 'https://host', '../nomads', "nomads\n", 123, null] as $id) {
    check($service->prepare($actor, array_replace($request, ['target_id' => $id]))['status'] === 400, 'Invalid target shape');
}
foreach (['', '12345678-1234-1234-8234-123456789abc', strtoupper($request['request_id']), "\n".$request['request_id'], 123, null] as $id) {
    check($service->prepare($actor, array_replace($request, ['request_id' => $id]))['status'] === 400, 'Require canonical caller UUIDv4');
}
check($service->prepare($actor, [])['status'] === 400, 'Missing fields fail closed');
check((new CommandIntentPreparer(['nomads']))->prepare($actor, $request)['status'] === 503, 'Absent policy fails closed');
$available = false;
check($service->prepare($actor, $request)['status'] === 503, 'Maintained cache unavailable');
$throws = new CommandIntentPreparer(['nomads'], static function (): bool { throw new RuntimeException('private-policy-secret'); });
$result = $throws->prepare($actor, $request);
check($result['status'] === 503 && !str_contains(json_encode($result), 'private-policy-secret'), 'Policy exception sanitized');
foreach ([['nomads', 'nomads'], ['bad target'], [123], ['key' => 'nomads'], array_fill(0, 33, 'nomads')] as $inventory) {
    try { new CommandIntentPreparer($inventory); check(false, 'Invalid inventory accepted'); }
    catch (InvalidArgumentException) { check(true, 'Invalid inventory rejected'); }
}
try { new PreparedCommandIntent($actor, '../bad', $request['request_id']); check(false, 'Invalid DTO accepted'); }
catch (InvalidArgumentException) { check(true, 'DTO independently validates shape, not authority'); }
echo "PASS: {$checks} command-intent checks\n";
