<?php
declare(strict_types=1);
// Pure array fixtures only: no profile files, credentials, bootstrap, network or writer.
require_once __DIR__.'/../../src/Civ13/PortableProfile.php';
use Civ13\PortableProfile;

$checks=0;
function profileSyncCheck(bool $condition,string $message): void {
    global $checks; ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
}
function profileSyncRejects(array $profile,string $message): void {
    try { PortableProfile::runtimeOptions($profile); }
    catch (InvalidArgumentException $expected) { profileSyncCheck(true,$message); return; }
    throw new RuntimeException($message);
}
function profileSyncFixture(): array {
    $path=PHP_OS_FAMILY==='Windows'?'D:\\Synthetic-only\\admins.txt':'/synthetic-only/admins.txt';
    return [
        'schema'=>'civilizationbot.portable.v1','gitdir'=>'synthetic-gitdir','state_dir'=>'synthetic-state',
        'options'=>['owner_id'=>'','technician_id'=>'','civ13_guild_id'=>'770000000000000001',
            'channel_ids'=>[],'role_ids'=>['Admin'=>'880000000000000001']],
        'servers'=>['test1714'=>['enabled'=>false,'ip'=>'127.0.0.1','port'=>1714,'admin_file'=>$path]],
        'verification_source'=>['mode'=>'read-only','url'=>'http://civ13.com:55555/verified'],
        'admin_file_sync'=>['enabled'=>true,'max_bytes'=>4096,'debounce_seconds'=>0.25,'refresh_timeout_seconds'=>5.0],
    ];
}
$p=profileSyncFixture(); $o=PortableProfile::runtimeOptions($p);
profileSyncCheck($o['admin_file_sync']===array_merge($p['admin_file_sync'],['admin_file'=>$p['servers']['test1714']['admin_file']]),'Enabled config was not normalized/bound to sole explicit admin file');
profileSyncCheck($o['verification_read_only']===true && $o['verify_url']==='http://civ13.com:55555/verified','Read-only hosted source changed');
profileSyncCheck($p['servers']['test1714']['enabled']===false && !array_key_exists('servers',$o),'Admin file option enabled/rewrote game server');
// Top-level policy always overwrites any options injection, including omitted policy.
$p=profileSyncFixture(); unset($p['admin_file_sync']);
$p['options']['admin_file_sync']=['enabled'=>true,'admin_file'=>'injected-path'];
profileSyncCheck(PortableProfile::runtimeOptions($p)['admin_file_sync']===['enabled'=>false],'Omitted policy inherited privileged options injection');
$p['admin_file_sync']=['enabled'=>false];
profileSyncCheck(PortableProfile::runtimeOptions($p)['admin_file_sync']===['enabled'=>false],'Disabled policy inherited privileged options injection');
// Disabled mode need not configure the otherwise unavailable synchronization prerequisites.
unset($p['verification_source']); $p['options']['civ13_guild_id']=''; $p['options']['role_ids']=[]; unset($p['servers']);
profileSyncCheck(PortableProfile::runtimeOptions($p)['admin_file_sync']===['enabled'=>false],'Disabled synchronization required enabled-only dependencies');
foreach ([null,false,true,0,'enabled',[],['enabled'=>0],['enabled'=>1],['enabled'=>'false'],['enabled'=>null],
    ['enabled'=>false,'max_bytes'=>4096],['enabled'=>false,'extra'=>true]] as $config) {
    $p=profileSyncFixture(); $p['admin_file_sync']=$config;
    profileSyncRejects($p,'Malformed/extra disabled synchronization settings accepted');
}
foreach (array_keys(profileSyncFixture()['admin_file_sync']) as $key) {
    $p=profileSyncFixture(); unset($p['admin_file_sync'][$key]);
    profileSyncRejects($p,'Required enabled setting omitted: '.$key);
}
foreach (['admin_file','path','server','role_ids','retry','extra'] as $key) {
    $p=profileSyncFixture(); $p['admin_file_sync'][$key]='injected';
    profileSyncRejects($p,'Unknown enabled setting accepted: '.$key);
}
foreach ([1,16777216] as $value) {
    $p=profileSyncFixture(); $p['admin_file_sync']['max_bytes']=$value;
    profileSyncCheck(PortableProfile::runtimeOptions($p)['admin_file_sync']['max_bytes']===$value,'Valid integer byte boundary rejected');
}
foreach ([0,-1,16777217,1.0,4096.5,'4096',true,false,null,NAN,INF] as $value) {
    $p=profileSyncFixture(); $p['admin_file_sync']['max_bytes']=$value;
    profileSyncRejects($p,'Invalid byte budget accepted');
}
foreach (['debounce_seconds'=>5.0,'refresh_timeout_seconds'=>300.0] as $key=>$maximum) {
    foreach ([0.001,1,(int)$maximum,$maximum] as $value) {
        $p=profileSyncFixture(); $p['admin_file_sync'][$key]=$value;
        $actual=PortableProfile::runtimeOptions($p)['admin_file_sync'][$key];
        profileSyncCheck(is_float($actual) && $actual===(float)$value,'Valid timer was not normalized to finite seconds: '.$key);
    }
    foreach ([0,0.0,-0.1,$maximum+0.01,NAN,INF,-INF,'1',true,false,null,[]] as $value) {
        $p=profileSyncFixture(); $p['admin_file_sync'][$key]=$value;
        profileSyncRejects($p,'Invalid timer accepted: '.$key);
    }
}
foreach ([null,[],['mode'=>'read-write','url'=>'http://civ13.com:55555/verified'],
    ['mode'=>'read-only','url'=>'https://civ13.com:55555/verified'],
    ['mode'=>'read-only','url'=>'http://example.invalid/verified'],
    ['mode'=>'read-only','url'=>'http://civ13.com:55555/verified','extra'=>true]] as $source) {
    $p=profileSyncFixture(); $p['verification_source']=$source;
    profileSyncRejects($p,'Unknown/unsafe source accepted for enabled sync');
}
$p=profileSyncFixture(); unset($p['verification_source']); profileSyncRejects($p,'Enabled sync accepted absent read-only source');
foreach (['',null,123,'guild','770000000000000001/extra'] as $guild) {
    $p=profileSyncFixture(); $p['options']['civ13_guild_id']=$guild;
    profileSyncRejects($p,'Enabled sync accepted missing/invalid target guild');
}
$p=profileSyncFixture(); unset($p['options']['civ13_guild_id']); profileSyncRejects($p,'Enabled sync accepted omitted target guild');
foreach ([[],null,'roles',false] as $roles) {
    $p=profileSyncFixture(); $p['options']['role_ids']=$roles;
    profileSyncRejects($p,'Enabled sync accepted missing/invalid role map');
}
$p=profileSyncFixture(); unset($p['options']['role_ids']); profileSyncRejects($p,'Enabled sync accepted omitted role map');
foreach ([[],null,false,'server'] as $servers) {
    $p=profileSyncFixture(); $p['servers']=$servers;
    profileSyncRejects($p,'Enabled sync accepted absent/malformed server list');
}
$p=profileSyncFixture(); unset($p['servers']); profileSyncRejects($p,'Enabled sync accepted omitted server list');
$p=profileSyncFixture(); $p['servers']['second']=$p['servers']['test1714']; profileSyncRejects($p,'Enabled sync invented multi-server support');
foreach ([null,false,[],'server'] as $server) {
    $p=profileSyncFixture(); $p['servers']['test1714']=$server;
    profileSyncRejects($p,'Enabled sync accepted missing explicit server admin file');
}
foreach (['','admins.txt','../admins.txt','D:admins.txt','file:///tmp/admins.txt','https://example.invalid/admins.txt',"/bad\0path",null,false,123] as $path) {
    $p=profileSyncFixture(); $p['servers']['test1714']['admin_file']=$path;
    profileSyncRejects($p,'Enabled sync accepted unsafe/nonabsolute admin path');
}
$p=profileSyncFixture(); $p['servers']['test1714']['admin_file']=PHP_OS_FAMILY==='Windows'?'/current-drive/admins.txt':'D:\\wrong-platform\\admins.txt';
profileSyncRejects($p,'Enabled sync accepted platform-inappropriate admin path');
// load() is deliberately not exercised: its existing TEST1714/filesystem schema validation
// remains a separate contract. This test neither reads a profile nor probes path existence.
echo "PASS admin-file synchronization profile ({$checks} assertions): explicit opt-in, prerequisites, limits and path binding\n";
