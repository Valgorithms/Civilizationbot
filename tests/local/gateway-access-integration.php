<?php
declare(strict_types=1);
// Reuse the existing injected Discord/cache/clock fixture. No bot bootstrap, network or credentials.
require __DIR__.'/access-file-sync.php';
$gatewayChecksStart=$checks;

final class GatewayLegacyVerifier extends \Civ13\Verifier {
    public function __construct() { (new ReflectionProperty(\Civ13\Verifier::class, 'readOnly'))->setValue($this, true); }
    public function hostedRecords(): array { return [['ss13'=>'legacytest','discord'=>'990000000000000001']]; }
}
function gatewayIntegrationRemove(string $path): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}
function gatewayLegacyOwner(array $settings, string $group, string $gateway, string $otherGroup, string $other): \Civ13\Civ13 {
    $owner=(new ReflectionClass(\Civ13\Civ13::class))->newInstanceWithoutConstructor();
    $owner->verifier=new GatewayLegacyVerifier();
    $owner->logger=new \Psr\Log\NullLogger();
    $owner->options=['access_file_sync'=>$settings];
    $owner->enabled_gameservers=[
        new class($group,$gateway) { public function __construct(private string $group, public string $basedir) {} public function regionGroupFile(): string { return $this->group; } },
        new class($otherGroup,$other) { public function __construct(private string $group, public string $basedir) {} public function regionGroupFile(): string { return $this->group; } },
    ];
    return $owner;
}
$root=sys_get_temp_dir().'/bot-gateway-composition-'.bin2hex(random_bytes(8)); mkdir($root); $root=realpath($root);
$service=null;
try {
    foreach (['gateway/SQL','first/SQL','front-door/access-inbox','front-door/SQL','other/SQL'] as $dir) { mkdir("$root/$dir",0700,true); }
    $group=['group'=>'sample','root'=>$root,'relay_port'=>2714,'gate'=>'gateway','gateway_verified_access'=>true,'gateway_verified_access_inbox'=>true,
        'regions'=>[['id'=>'gateway','kind'=>'GATEWAY'],['id'=>'first','kind'=>'BATTLE']]];
    $groupFile="$root/group.json"; file_put_contents($groupFile,json_encode($group,JSON_THROW_ON_ERROR));
    $otherGroup="$root/other.json"; file_put_contents($otherGroup,json_encode(['root'=>$root,'regions'=>[['id'=>'other']]],JSON_THROW_ON_ERROR));
    [$app,$settings,$f,$unused]=fixture();
    foreach ($settings['targets'] as $kind=>&$target) { $target['path']="$root/gateway/SQL/$kind.txt"; } unset($target);
    unset($settings['server_key'],$settings['server_basedir']); $settings['gateway_proposals']=true;
    $server=['enabled'=>true,'basedir'=>"$root/first",'ip'=>'127.0.0.1','port'=>1714,'name'=>'Synthetic','host'=>'127.0.0.1',
        'region_group'=>$groupFile,'follow_front_door'=>true];
    foreach (['discussion','playercount','ooc','lobby','asay','ic','transit','adminlog','debug','garbage','runtime','attack'] as $field) { $server[$field]=''; }
    $profile=['schema'=>'civilizationbot.portable.v1','root'=>$root,'env_file'=>"$root/unused-synthetic.env",'state_dir'=>$root,'gitdir'=>$root,
        'servers'=>['test1714'=>$server], 'http_port'=>1715, 'verification_source'=>['mode'=>'read-only','url'=>'https://civ13.com/verified'],
        'options'=>['civ13_guild_id'=>$app->civ13_guild_id,'role_ids'=>$app->role_ids,'channel_ids'=>[]], 'access_file_sync'=>$settings];
    $profileFile="$root/profile.json"; file_put_contents($profileFile,json_encode($profile,JSON_THROW_ON_ERROR));
    $loaded=\Civ13\PortableProfile::load($profileFile);
    check($loaded['servers']['test1714']['basedir']==="$root/gateway", 'actual profile follows dedicated gateway');
    $options=\Civ13\PortableProfile::runtimeOptions($loaded); $mapped=$options['access_file_sync'];
    check(($mapped['gateway_group_config'] ?? null)===$groupFile && $mapped['mode']==='publish', 'profile binds publisher to intended group');
    $app->enabled_gameservers['test1714']->basedir="$root/gateway"; $app->options=$options;
    $legacy="$root/gateway/SQL/approved.txt"; $authority="$root/front-door/SQL/verified-access.json";
    file_put_contents($legacy,"legacy sentinel\n"); file_put_contents($authority,"authority sentinel\n");
    $service=\Civ13\AccessFileSync::attach($app,$mapped,null,fn()=>$f->now); fire($f);
    $proposal="$root/front-door/access-inbox/proposal.json";
    $one=json_decode(file_get_contents($proposal),true,32,JSON_THROW_ON_ERROR);
    check($one===['group'=>'sample','approved'=>"faketest=990000000000000001\n",'whitelist'=>"faketest=990000000000000001\n"], 'real composition publishes first complete scoped projection');
    $app->verifier->verified=[['ss13'=>'Second.Test','discord'=>'990000000000000001']]; $service->verificationChanged(); fire($f);
    $two=json_decode(file_get_contents($proposal),true,32,JSON_THROW_ON_ERROR);
    check($two===['group'=>'sample','approved'=>"secondtest=990000000000000001\n",'whitelist'=>"secondtest=990000000000000001\n"], 'real composition replaces prior projection on refresh');
    check(file_get_contents($legacy)==="legacy sentinel\n" && file_get_contents($authority)==="authority sentinel\n", 'publisher does not directly change legacy or authority files');
    $service->stop(); $service=null;
    $group['gateway_verified_access_inbox']=false; file_put_contents($groupFile,json_encode($group,JSON_THROW_ON_ERROR));
    $refused=false; try { \Civ13\PortableProfile::load($profileFile); } catch (InvalidArgumentException) { $refused=true; }
    check($refused && json_decode(file_get_contents($proposal),true,32,JSON_THROW_ON_ERROR)===$two, 'profile refuses disabled inbox and preserves existing proposal');
    $group['gateway_verified_access_inbox']=true; file_put_contents($groupFile,json_encode($group,JSON_THROW_ON_ERROR));

    $first="$root/first/SQL/approved.txt"; $other="$root/other/SQL/approved.txt";
    foreach (['publish','preview','legacy','disabled'] as $mode) {
        foreach ([$legacy,$first,$other] as $file) { file_put_contents($file,"old sentinel\n"); }
        $route=match($mode) {
            'publish'=>['mode'=>'publish','gateway_group_config'=>$groupFile],
            'preview'=>['mode'=>'preview','gateway_group_config'=>$groupFile],
            'legacy'=>['mode'=>'publish'],
            default=>['enabled'=>false],
        };
        $owner=gatewayLegacyOwner($route,$groupFile,"$root/gateway",$otherGroup,"$root/other");
        $owner->hostedVerificationRefreshed($owner->verifier);
        $expected="legacytest=990000000000000001\n";
        $groupExpected=$mode==='publish' ? "old sentinel\n" : $expected;
        check(file_get_contents($legacy)===$groupExpected && file_get_contents($first)===$groupExpected, "$mode routing for opted group");
        check(file_get_contents($other)===$expected, "$mode preserves independent legacy group");
    }
    echo "PASS ".($checks-$gatewayChecksStart)." gateway profile, real composition, repeat replacement and legacy routing controls\n";
} finally {
    $service?->stop();
    gatewayIntegrationRemove($root);
}
