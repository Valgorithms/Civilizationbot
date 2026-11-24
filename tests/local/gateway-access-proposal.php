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
        $config = [
            'group'=>$group, 'root'=>"$root/$group", 'relay_port'=>$group==='tdm' ? 2714 : 3714,
            'gate'=>'gateway', 'gateway_verified_access'=>true,
            'regions'=>[['id'=>'gateway','kind'=>'GATEWAY'],['id'=>'first','kind'=>$kinds[0]],['id'=>'second','kind'=>$kinds[1]]],
        ];
        $file = "$root/$group.json";
        file_put_contents($file,json_encode($config,JSON_THROW_ON_ERROR));
        $binding = GatewayAccessProposal::binding($file);
        $writer = new GatewayAccessProposal($file,$binding,['approved'=>['allow_empty'=>true],'whitelist'=>['allow_empty'=>true]],4096);
        $empty = "{\"outputs\":{\"approved\":\"\\n\",\"whitelist\":\"\\n\"},\"unmapped_role_holders\":0}";
        check(($writer($empty,1,static fn()=>true)['state'] ?? null)==='updated', 'complete pair submitted');
        $before = file_get_contents($dir.'/proposal.json');
        check(json_decode($before,true,32,JSON_THROW_ON_ERROR)===['group'=>$group,'approved'=>"\n",'whitelist'=>"\n"], 'exact pair and scope, no version');
        $calls=0;
        check(($writer($empty,2,static function () use (&$calls): bool { return ++$calls===1; })['state'] ?? null)==='superseded', 'late generation fence');
        check(file_get_contents($dir.'/proposal.json')===$before && glob($dir.'/.proposal-*')===[], 'late refusal preserves prior pair and deletes stage');
        check(($writer(str_replace('":0','":1',$empty),3,static fn()=>true)['state'] ?? null)==='blocked', 'unmapped role holder refused');
        $wrong = $config;
        $wrong['group'] = $group === 'tdm' ? 'earth' : 'tdm';
        // A writer bound earlier does not retarget to another group during a commit.
        file_put_contents($file,json_encode($wrong,JSON_THROW_ON_ERROR));
        check(($writer($empty,4,static fn()=>true)['state'] ?? null)==='blocked', 'wrong group refused');
        check(file_get_contents($dir.'/proposal.json')===$before,'prior scoped pair preserved');
        $legacy = ["$root/$group/gateway/SQL/approved.txt","$root/$group/first/SQL/approved.txt"];
        foreach ($legacy as $path) { check(!file_exists($path), 'no direct list write'); }
    }
    echo "Gateway proposals: synthetic TDM and Persistence group checks passed\n";
} finally { deleteTree($root); }
