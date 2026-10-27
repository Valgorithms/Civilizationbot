<?php
declare(strict_types=1);
// Dependency-free fixtures: no bootstrap, autoload, environment, network or file writes.
require_once __DIR__.'/../../src/Civ13/HostedVerificationSource.php';
require_once __DIR__.'/../../src/Civ13/HostedVerificationSnapshot.php';
require_once __DIR__.'/../../src/Civ13/AdminListRenderer.php';
require_once __DIR__.'/../../src/Civ13/AdminSyncSnapshotGate.php';

use Civ13\AdminListRenderer;
use Civ13\AdminSyncSnapshotGate;

function projectionCheck(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function projectionRejects(callable $call, string $message): void {
    try { $call(); } catch (InvalidArgumentException|RuntimeException $error) { return; }
    throw new RuntimeException($message);
}
// Extract only the existing constant: do not load GameServer or its dependencies.
$source = file_get_contents(__DIR__.'/../../src/Civ13/GameServer.php');
projectionCheck(is_string($source) && preg_match('/public const ADMIN_PERMISSIONS = (\[.*?\n    \]);/s', $source, $match) === 1, 'Permission constant not found');
// No eval: parse the simple literal pairs and ignore commented-out legacy entries.
$permissions = [];
foreach (explode("\n", $match[1]) as $line) {
    if (preg_match("/^\\s*'([^']+)'\\s*=>\\s*\\['([^']+)',\\s*'([0-9]+)'\\]/", $line, $parts)) {
        $permissions[$parts[1]] = [$parts[2], $parts[3]];
    }
}
projectionCheck(count($permissions) === 6 && array_key_first($permissions) === 'Owner', 'Review changed permission layout');
$roleIds = []; $i = 0;
foreach ($permissions as $name => $_) { $roleIds[$name] = '88000000000000000'.(++$i); }
$alpha = '990000000000000001'; $beta = '990000000000000002'; $guild = '770000000000000001';
$verified = [['discord'=>$alpha,'ss13'=>'Fake.Alpha'],['discord'=>$beta,'ss13'=>'Fake Beta']];
$members = [['id'=>$alpha,'roles'=>array_values($roleIds)],['id'=>$beta,'roles'=>[$roleIds['Admin']]]];
$expected = "fakealpha;Host;65535|||\nfakebeta;Admin;16254|||\n";
projectionCheck(AdminListRenderer::render($verified,$members,$roleIds,$permissions) === $expected, 'Precedence or LF framing changed');
foreach ($permissions as $name => [$rank,$flags]) {
    projectionCheck(AdminListRenderer::render([$verified[0]],[['id'=>$alpha,'roles'=>[$roleIds[$name]]]],$roleIds,$permissions) === "fakealpha;$rank;$flags|||\n", 'Rank mapping changed');
}
projectionCheck(AdminListRenderer::render(array_reverse($verified),array_reverse($members),$roleIds,$permissions) === $expected, 'Output is not deterministic');
projectionCheck(AdminListRenderer::render($verified,[['id'=>$beta,'roles'=>[$roleIds['Admin']]]],$roleIds,$permissions) === "fakebeta;Admin;16254|||\n", 'Departed member retained');
projectionCheck(AdminListRenderer::render([$verified[0]],$members,$roleIds,$permissions) === "fakealpha;Host;65535|||\n", 'Unverified member included');
projectionCheck(AdminListRenderer::render($verified,[['id'=>$alpha,'roles'=>[]]],$roleIds,$permissions) === '', 'Role removal retained entry');
projectionCheck(AdminListRenderer::render([],$members,$roleIds,$permissions) === '', 'Empty verified list not authoritative');
projectionCheck(AdminListRenderer::render($verified,[],$roleIds,$permissions) === '', 'Empty membership not authoritative');
projectionRejects(fn()=>AdminListRenderer::render($verified,$members,[],$permissions),'Empty role map accepted');
projectionRejects(fn()=>AdminListRenderer::render($verified,$members,$roleIds,[]),'Empty permissions accepted');
projectionRejects(fn()=>AdminListRenderer::render([$verified[0],$verified[0]],$members,$roleIds,$permissions),'Duplicate verification accepted');
projectionRejects(fn()=>AdminListRenderer::render([$verified[0],['discord'=>$beta,'ss13'=>'fakealpha']],$members,$roleIds,$permissions),'Ambiguous ckey accepted');
projectionRejects(fn()=>AdminListRenderer::render($verified,[$members[0],$members[0]],$roleIds,$permissions),'Duplicate member accepted');
foreach (["[badckey]", "bad;ckey", "bad|ckey", "bad\nckey", "bad\0ckey"] as $ckey) {
    projectionRejects(fn()=>AdminListRenderer::render([['discord'=>$alpha,'ss13'=>$ckey]],$members,$roleIds,$permissions),'Unsafe ckey accepted');
}
// Existing hosted normalization permits legacy bracketed spelling. Unrelated rows
// must not poison a valid admin projection; no real identities are used here.
$legacy = array_merge($verified, [
    ['discord'=>'990000000000000003','ss13'=>'[legacy-one]'],
    ['discord'=>'990000000000000004','ss13'=>'[legacy-two]'],
    ['discord'=>'990000000000000005','ss13'=>'[legacy-three]'],
]);
projectionCheck(AdminListRenderer::render($legacy,$members,$roleIds,$permissions) === $expected, 'Unrelated legacy spelling poisoned projection');
$legacy[] = ['discord'=>'990000000000000006','ss13'=>'unrelated;legacy'];
projectionCheck(AdminListRenderer::render($legacy,$members,$roleIds,$permissions) === $expected, 'Unselected framing spelling poisoned projection');
$nonAdminLegacy = array_merge($members,[['id'=>'990000000000000006','roles'=>[]]]);
projectionCheck(AdminListRenderer::render($legacy,$nonAdminLegacy,$roleIds,$permissions) === $expected, 'Non-admin legacy member poisoned projection');
$badPermissions = $permissions; $badPermissions['Admin'] = ["Bad;Rank", '16254'];
projectionRejects(fn()=>AdminListRenderer::render($verified,$members,$roleIds,$badPermissions),'Unsafe rank accepted');
$badPermissions['Admin'] = ['Admin', '65536'];
projectionRejects(fn()=>AdminListRenderer::render($verified,$members,$roleIds,$badPermissions),'Out-of-range flags accepted');
$badRoles = $roleIds; $badRoles['Admin'] = $roleIds['Owner'];
projectionRejects(fn()=>AdminListRenderer::render($verified,$members,$badRoles,$permissions),'Aliased role configuration accepted');

$now = 1800000000;
$status = ['available'=>true,'stale'=>false,'has_valid_list'=>true,'refresh_in_progress'=>false,'records'=>2,'age_seconds'=>0,'last_success_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now)];
$membership = ['guild_id'=>$guild,'complete'=>true,'connected'=>true,'generation'=>7,'roles'=>array_values($roleIds),'members'=>$members];
$render = static fn(array $s, array $v, array $m, int $generation=7, ?int $time=null): string => AdminSyncSnapshotGate::render($s,$v,$m,$guild,$generation,$time ?? $now,$roleIds,$permissions);
projectionCheck($render($status,$verified,$membership) === $expected,'Complete fresh snapshot rejected');
$emptyStatus = array_replace($status,['records'=>0]);
projectionCheck($render($emptyStatus,[],$membership) === '', 'Valid empty verification blocked');
projectionCheck($render($status,$verified,array_replace($membership,['members'=>[]])) === '', 'Valid empty guild blocked');
foreach ([['available'=>false],['available'=>1],['stale'=>true],['has_valid_list'=>false],['refresh_in_progress'=>true],['records'=>0],['age_seconds'=>1800],['age_seconds'=>-1],['last_success_utc'=>'invalid'],['last_success_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now+1)]] as $change) {
    projectionRejects(fn()=>$render(array_replace($status,$change),$verified,$membership),'Unsafe verification status accepted');
}
projectionRejects(fn()=>$render(array_replace($emptyStatus,['available'=>false]),[],$membership),'Unavailable initial empty snapshot treated as revocation');
projectionRejects(fn()=>$render(array_replace($emptyStatus,['stale'=>true]),[],$membership),'Stale empty snapshot treated as revocation');
projectionRejects(fn()=>$render($status,$verified,$membership,7,$now+1800),'Previously fresh status did not expire');
foreach ([['complete'=>false],['complete'=>1],['connected'=>false],['guild_id'=>'770000000000000002'],['generation'=>6],['roles'=>[]],['members'=>null]] as $change) {
    projectionRejects(fn()=>$render($status,$verified,array_replace($membership,$change)),'Unsafe membership accepted');
}
projectionRejects(fn()=>$render($emptyStatus,[],array_replace($membership,['complete'=>false,'members'=>[]])),'Incomplete empty guild treated as revocation');
projectionRejects(fn()=>$render($status,$verified,$membership,8),'Obsolete generation accepted');
foreach (array_keys($status) as $key) {
    $missing = $status; unset($missing[$key]);
    projectionRejects(fn()=>$render($missing,$verified,$membership),'Missing verification gate field accepted');
}
foreach (array_keys($membership) as $key) {
    $missing = $membership; unset($missing[$key]);
    projectionRejects(fn()=>$render($status,$verified,$missing),'Missing membership gate field accepted');
}
echo "PASS pure admin projection: ordered ranks, LF, removals, valid empty, invalid/stale/incomplete rejection and generation gate\n";
