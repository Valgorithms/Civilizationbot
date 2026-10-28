<?php

declare(strict_types=1);

require __DIR__.'/../../src/Civ13/AdminConsole/ReadOnlyStatusService.php';
require __DIR__.'/../../src/Civ13/AdminConsole/PreparedCommandIntent.php';
require __DIR__.'/../../src/Civ13/AdminConsole/OperationAdmissionStore.php';
require __DIR__.'/../../src/Civ13/AdminConsole/OperationAdmission.php';

use Civ13\AdminConsole\OperationAdmission;
use Civ13\AdminConsole\OperationAdmissionStore;
use Civ13\AdminConsole\PreparedCommandIntent;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks; ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
}
// Serial fake models an atomic API result; NOT evidence of concurrent/disk safety.
final class FakeAdmissionStore implements OperationAdmissionStore
{
    public array $rows = [];
    public int $calls = 0;
    public string $mode = 'normal';
    public ?Closure $afterWrite = null;
    public function reserve(string $key, string $fingerprint, string $operationId): string
    {
        ++$this->calls;
        if ($this->mode === 'throw_before') { throw new RuntimeException('private-storage-error'); }
        if ($this->mode === 'uncertain') { return 'uncertain'; }
        if ($this->mode === 'invalid') { return 'committed'; }
        $binding = [$fingerprint, $operationId];
        if (isset($this->rows[$key])) { return $this->rows[$key] === $binding ? 'existing' : 'conflict'; }
        $this->rows[$key] = $binding;
        if ($this->afterWrite !== null) { ($this->afterWrite)(); }
        if ($this->mode === 'throw_after') { throw new RuntimeException('lost-reservation-response'); }
        return 'reserved';
    }
}
$actor = '12345678901234567';
$requestId = '12345678-1234-4234-8234-123456789abc';
$intent = new PreparedCommandIntent($actor, 'nomads', $requestId);
$allow = true; $ready = true; $decisions = [];
$policy = static function (string $who, string $target, string $action) use (&$allow, &$ready, &$decisions): ?bool {
    $decisions[] = [$who, $target, $action]; return $ready ? $allow : null;
};
$store = new FakeAdmissionStore();
$service = new OperationAdmission(['nomads', 'tdm'], $store, $policy);
$first = $service->admit($actor, $intent);
check($first['status'] === 200 && $first['body']['state'] === 'reserved_not_dispatched', 'New reservation only');
check($first['body']['execution_enabled'] === false && $first['body']['retry_dispatch'] === false, 'No dispatch authorization');
check($first['body']['recovery_required'] === false && !isset($first['body']['completed']), 'No completion receipt');
check(count($store->rows) === 1 && count($decisions) === 2, 'Reservation bracketed by authorization');
check($decisions[0] === [$actor, 'nomads', 'restart'], 'Explicit action policy');
$id = $first['body']['operation_id'];
check(strlen($id) <= 80 && preg_match('/\A[A-Za-z0-9_-]+\z/D', $id) === 1, 'Existing lifecycle consumer identity contract');
$again = $service->admit($actor, $intent);
check($again['body']['operation_id'] === $id && count($store->rows) === 1, 'Serial replay retains one binding');
check($again['body']['state'] === 'existing_requires_reconciliation' && $again['body']['recovery_required'] && !$again['body']['retry_dispatch'], 'Existing never grants redispatch');
$otherTarget = new PreparedCommandIntent($actor, 'tdm', $requestId);
check($service->admit($actor, $otherTarget)['status'] === 409 && count($store->rows) === 1, 'Same actor request changed target conflicts');
$allow = false; $calls = $store->calls;
check($service->admit($actor, $intent)['status'] === 403 && $store->calls === $calls, 'Revocation blocks replay before storage');
$allow = true; $ready = false;
check($service->admit($actor, $intent)['status'] === 503 && $store->calls === $calls, 'Unready cache denies');
$ready = true;
check($service->admit('invalid', $intent)['status'] === 401 && $store->calls === $calls, 'Invalid session actor');
check($service->admit('22345678901234567', $intent)['status'] === 403 && $store->calls === $calls, 'Intent actor is not session authority');
check($service->admit($actor, new PreparedCommandIntent($actor, 'hidden', $requestId))['status'] === 403 && $store->calls === $calls, 'Unknown trusted target denied');
check((new OperationAdmission(['nomads'], $store))->admit($actor, $intent)['status'] === 503, 'Absent authorization provider');
check((new OperationAdmission(['nomads'], null, $policy))->admit($actor, $intent)['status'] === 503, 'Absent durable provider');
$lost = new FakeAdmissionStore(); $lost->mode = 'throw_after';
$lostService = new OperationAdmission(['nomads'], $lost, $policy);
$unknown = $lostService->admit($actor, $intent);
check($unknown['status'] === 503 && $unknown['body']['recovery_required'] && !$unknown['body']['retry_dispatch'], 'Lost commit acknowledgement is uncertain');
check(!str_contains(json_encode($unknown), 'lost-reservation-response') && count($lost->rows) === 1, 'Exception hidden, binding retained');
$lost->mode = 'normal';
$recovered = $lostService->admit($actor, $intent);
check($recovered['body']['operation_id'] === $id && $recovered['body']['state'] === 'existing_requires_reconciliation', 'Lost-response retry retains deterministic lifecycle identity');
foreach (['throw_before', 'uncertain', 'invalid'] as $mode) {
    $fake = new FakeAdmissionStore(); $fake->mode = $mode;
    $out = (new OperationAdmission(['nomads'], $fake, $policy))->admit($actor, $intent);
    check($out['status'] === 503 && $out['body']['recovery_required'] && !$out['body']['retry_dispatch'], 'Storage failure never becomes admission');
}
$revoked = new FakeAdmissionStore();
$revoked->afterWrite = static function () use (&$allow): void { $allow = false; };
$revokedService = new OperationAdmission(['nomads'], $revoked, $policy);
$out = $revokedService->admit($actor, $intent);
check($out['status'] === 403 && !isset($out['body']['operation_id']) && count($revoked->rows) === 1, 'Revocation during reservation hides receipt without deleting binding');
$allow = true;
check($revokedService->admit($actor, $intent)['body']['operation_id'] === $id, 'Reauthorized replay keeps original identity');
$otherActor = '22345678901234567';
$out = $service->admit($otherActor, new PreparedCommandIntent($otherActor, 'nomads', $requestId));
check($out['body']['operation_id'] !== $id && count($store->rows) === 2, 'Separate actor namespace');
$conflicting = new FakeAdmissionStore();
$canonical = $intent->toArray(); $conflicting->rows[$canonical['deduplication_key']] = [$canonical['payload_fingerprint'], 'different-operation'];
check((new OperationAdmission(['nomads'], $conflicting, $policy))->admit($actor, $intent)['status'] === 409, 'Existing operation identity mismatch conflicts');
$throws = static function (): bool { throw new RuntimeException('private-policy-error'); };
check((new OperationAdmission(['nomads'], $store, $throws))->admit($actor, $intent)['status'] === 503, 'Policy exception fails closed');
$unready = new FakeAdmissionStore();
$unready->afterWrite = static function () use (&$ready): void { $ready = false; };
check((new OperationAdmission(['nomads'], $unready, $policy))->admit($actor, $intent)['status'] === 503 && count($unready->rows) === 1, 'Cache becomes unavailable after reservation');
$ready = true; $policyCalls = 0;
$throwSecond = static function () use (&$policyCalls): bool {
    if (++$policyCalls === 2) { throw new RuntimeException('post-reservation-policy-failure'); }
    return true;
};
check((new OperationAdmission(['nomads'], new FakeAdmissionStore(), $throwSecond))->admit($actor, $intent)['status'] === 503, 'Policy throw after reservation fails closed');
foreach ([['nomads', 'nomads'], [123], ['bad target'], ['key' => 'nomads'], array_fill(0, 33, 'nomads')] as $inventory) {
    try { new OperationAdmission($inventory); check(false, 'Invalid inventory accepted'); }
    catch (InvalidArgumentException) { check(true, 'Invalid inventory rejected'); }
}
echo "PASS: {$checks} admission API checks (serial fake; not durability/concurrency proof)\n";
