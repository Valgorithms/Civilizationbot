<?php
declare(strict_types=1);
require_once __DIR__.'/../../src/Civ13/PortableProfile.php';
require_once __DIR__.'/../../src/Civ13/AccessListProjection.php';
require_once __DIR__.'/../../src/Civ13/Server/GatewayAccessProposal.php';

use Civ13\Server\GatewayAccessProposal;

function check(bool $test, string $message): void {
    if (!$test) { throw new RuntimeException($message); }
}
function deleteTree(string $path): void {
    foreach (scandir($path) as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $item = $path.'/'.$item;
        if (is_dir($item) && !is_link($item)) { deleteTree($item); } else { unlink($item); }
    }
    rmdir($path);
}
$root = sys_get_temp_dir().'/bot-gateway-'.bin2hex(random_bytes(10));
mkdir($root);
$root = realpath($root);
try {
    foreach (['tdm'=>['BARRACKS','BATTLE'], 'earth'=>['MAINLAND','CONTINENT']] as $group=>$kinds) {
        $dir = "$root/$group/front-door/access-inbox";
        mkdir($dir,0700,true);
        mkdir("$root/$group/front-door/SQL",0700,true);
        mkdir("$root/$group/gateway/SQL",0700,true);
        mkdir("$root/$group/first/SQL",0700,true);
        $sentinels = ["$root/$group/front-door/SQL/verified-access.json", "$root/$group/gateway/SQL/approved.txt", "$root/$group/first/SQL/approved.txt"];
        foreach ($sentinels as $path) { file_put_contents($path, "unchanged sentinel\n"); }
        $config = [
            'group'=>$group, 'root'=>"$root/$group", 'relay_port'=>$group==='tdm' ? 2714 : 3714,
            'gate'=>'gateway', 'gateway_verified_access'=>true, 'gateway_verified_access_inbox'=>true,
            'regions'=>[['id'=>'gateway','kind'=>'GATEWAY'],['id'=>'first','kind'=>$kinds[0]],['id'=>'second','kind'=>$kinds[1]]],
        ];
        $file = "$root/$group.json";
        foreach ([null, false] as $disabled) {
            $invalid = $config;
            if ($disabled === null) { unset($invalid['gateway_verified_access_inbox']); }
            else { $invalid['gateway_verified_access_inbox'] = $disabled; }
            file_put_contents($file,json_encode($invalid,JSON_THROW_ON_ERROR));
            try { GatewayAccessProposal::binding($file); throw new RuntimeException('Disabled inbox accepted'); }
            catch (InvalidArgumentException) { }
        }
        file_put_contents($file,json_encode($config,JSON_THROW_ON_ERROR));
        $binding = GatewayAccessProposal::binding($file);
        $writer = new GatewayAccessProposal($file,$binding,['approved'=>['allow_empty'=>true],'whitelist'=>['allow_empty'=>true]],4096);
        $empty = json_encode(['outputs'=>['approved'=>"\n",'whitelist'=>"\n"],'unmapped_role_holders'=>0],JSON_THROW_ON_ERROR);
        check(($writer($empty,1,static fn()=>true)['state'] ?? null)==='updated', 'complete pair submitted');
        check(json_decode(file_get_contents($dir.'/proposal.json'),true,32,JSON_THROW_ON_ERROR)===['group'=>$group,'approved'=>"\n",'whitelist'=>"\n"], 'exact empty pair and scope');
        $outputs = ['approved'=>"alpha=990000000000000001\n",'whitelist'=>"bravo=990000000000000002\n"];
        $fresh = json_encode(['outputs'=>$outputs,'unmapped_role_holders'=>0],JSON_THROW_ON_ERROR);
        check(($writer($fresh,2,static fn()=>true)['state'] ?? null)==='updated', 'existing proposal replaced');
        $before = file_get_contents($dir.'/proposal.json');
        check(json_decode($before,true,32,JSON_THROW_ON_ERROR)===['group'=>$group]+$outputs, 'replacement retains complete pair and scope');
        check(($writer->lastReceipt()['state'] ?? null)==='submitted' && glob($dir.'/.proposal-*')===[], 'replacement receipt and stage cleanup');
        $calls=0;
        check(($writer($empty,3,static function () use (&$calls): bool { return ++$calls===1; })['state'] ?? null)==='superseded', 'late generation fence');
        check(file_get_contents($dir.'/proposal.json')===$before && glob($dir.'/.proposal-*')===[], 'late refusal preserves prior pair and deletes stage');
        $calls=0;
        $disabled=$config; $disabled['gateway_verified_access_inbox']=false;
        $result=$writer($empty,4,static function () use (&$calls,$file,$disabled): bool {
            if (++$calls===2) { file_put_contents($file,json_encode($disabled,JSON_THROW_ON_ERROR)); }
            return true;
        });
        check(($result['state'] ?? null)==='blocked', 'disabled inbox during final guard refused');
        check(file_get_contents($dir.'/proposal.json')===$before && glob($dir.'/.proposal-*')===[], 'disabled inbox preserves prior pair and removes stage');
        file_put_contents($file,json_encode($config,JSON_THROW_ON_ERROR));
        check(($writer(str_replace('":0','":1',$empty),5,static fn()=>true)['state'] ?? null)==='blocked', 'unmapped role holder refused');
        $wrong = $config; $wrong['group'] = $group === 'tdm' ? 'earth' : 'tdm';
        file_put_contents($file,json_encode($wrong,JSON_THROW_ON_ERROR));
        check(($writer($empty,6,static fn()=>true)['state'] ?? null)==='blocked', 'wrong group refused');
        check(file_get_contents($dir.'/proposal.json')===$before,'prior scoped pair preserved');
        foreach ($sentinels as $path) { check(file_get_contents($path)==="unchanged sentinel\n", 'no legacy or authority direct write'); }
    }
    echo "Gateway proposals: replacement and synthetic TDM/Persistence scope checks passed\n";
} finally { deleteTree($root); }
