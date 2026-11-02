<?php
declare(strict_types=1);
// Explicit dependency tree; no bot/bootstrap/env/client or real access files.
if (isset($argv[1])) { require $argv[1]; }
foreach (['HostedVerificationSource','HostedVerificationSnapshot','PortableProfile','AccessListProjection'] as $class) { require_once __DIR__.'/../../src/Civ13/'.$class.'.php'; }
require_once __DIR__.'/../../src/Civ13/Server/AccessListWriter.php';
use Civ13\AccessFileSync;
use Civ13\AccessListProjection;
use Civ13\Server\AccessListWriter;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
$journalPreimage=($argv[3]??'')==='--journal-preimage';
$checks=0;
function check(bool $ok,string $message): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($message); } }
function refuses(callable $call,string $message): void { try { $call(); } catch (Throwable) { check(true,$message); return; } check(false,$message); }
$now=1700000000; $guild='770000000000000001'; $role='880000000000000001'; $alpha='990000000000000001'; $beta='990000000000000002'; $unmapped='990000000000000003';
$status=['available'=>true,'stale'=>false,'has_valid_list'=>true,'refresh_in_progress'=>false,'records'=>2,'last_success_utc'=>gmdate('Y-m-d\TH:i:s\Z',$now),'age_seconds'=>0];
$verified=[['ss13'=>'Fake.Alpha','discord'=>$alpha],['ss13'=>'Fake_Beta','discord'=>$beta]];
$membership=['guild_id'=>$guild,'generation'=>1,'cache_epoch'=>2,'roles'=>[$role],'expected_members'=>3,'members'=>[['id'=>$alpha,'roles'=>[$role]],['id'=>$beta,'roles'=>[]],['id'=>$unmapped,'roles'=>[$role]]]];
$cache=['ready'=>true,'epoch'=>2]; $policies=['approved'=>['required_roles'=>['Verified']],'whitelist'=>['required_roles'=>['Verified']]];
$render=function(?array $s=null,?array $v=null,?array $m=null,?array $c=null,?array $p=null)use($status,$verified,$membership,$cache,$policies,$guild,$now,$role):string{return AccessListProjection::render($s??$status,$v??$verified,$m??$membership,$guild,1,$now,['Verified'=>$role],$p??$policies,$c??$cache);};
$wire=$render(); $expected="fakealpha=$alpha\n";
check(AccessListProjection::decode($wire)['outputs']===['approved'=>$expected,'whitelist'=>$expected],'Separate outputs/Verified policy/canonical LF');
$preview=AccessListProjection::preview($wire);
check($preview['targets']['approved']['records']===1 && $preview['unmapped_role_holders']===1,'Bounded preview counts');
foreach ([$alpha,$beta,$unmapped,'fakealpha','Fake.Alpha'] as $identity) { check(!str_contains(json_encode($preview),$identity),'Preview leaked identity'); }
$m=$membership; $m['members'][0]['roles']=[];
check(AccessListProjection::decode($render(m:$m))['outputs']===['approved'=>"\n",'whitelist'=>"\n"],'Role removal revokes both');
$m=$membership; array_shift($m['members']); --$m['expected_members'];
check(AccessListProjection::decode($render(m:$m))['outputs']['approved']==="\n",'Departed member revoked');
foreach (['available'=>false,'stale'=>true,'has_valid_list'=>false,'refresh_in_progress'=>true,'records'=>1,'age_seconds'=>1800,'last_success_utc'=>'bad'] as $key=>$value) { $s=$status;$s[$key]=$value;refuses(fn()=>$render(s:$s),'Unavailable verification retained old file'); }
foreach (['guild_id'=>'other','generation'=>0,'cache_epoch'=>1,'expected_members'=>4,'roles'=>[]] as $key=>$value) { $m=$membership;$m[$key]=$value;refuses(fn()=>$render(m:$m),'Partial/wrong/stale membership refused'); }
refuses(fn()=>$render(c:['ready'=>false,'epoch'=>2]),'Reconnect gate closed');
$v=$verified; $v[]=['ss13'=>'fakealpha','discord'=>$unmapped]; $s=$status;$s['records']=3;refuses(fn()=>$render(s:$s,v:$v),'Ambiguous canonical mapping');
$v=$verified;$v[0]['ss13']='bad=key';refuses(fn()=>$render(v:$v),'Selected unsafe identity');
$m=$membership;$m['members'][]=$m['members'][0];++$m['expected_members'];refuses(fn()=>$render(m:$m),'Duplicate member');
$p=$policies;$p['whitelist']['required_roles']=['Admin'];refuses(fn()=>$render(p:$p),'Unapproved job policy');
foreach (["", "\n\n", "a=099000000000000001\n", "fakealpha = $alpha\n","fakealpha=$alpha\r\n","fakealpha=$alpha","z=$alpha\na=$beta\n","a=$alpha\na=$beta\n"] as $bad) { refuses(fn()=>AccessListProjection::decode(json_encode(['outputs'=>['approved'=>$bad,'whitelist'=>$bad],'unmapped_role_holders'=>0])),'Unsafe framing'); }

$vectors=json_decode(file_get_contents(__DIR__.'/access-list-vectors.json'),true,16,JSON_THROW_ON_ERROR);
foreach($vectors['vectors'] as $vector){
    $candidate=json_encode(['outputs'=>['approved'=>$vector['bytes'],'whitelist'=>$vector['bytes']],'unmapped_role_holders'=>0]);
    $accepted=true;try{AccessListProjection::decode($candidate);}catch(Throwable){$accepted=false;}
    check($accepted===$vector['producer_accept'],'Canonical wire vector '.$vector['name']);
}

final class FaultWriter extends AccessListWriter {
    public string $fault=''; public int $replacements=0; public ?string $externalPath=null;
    protected function writeChunk($file,string $bytes): int|false { if($this->fault==='destination' && $this->externalPath!==null){file_put_contents($this->externalPath,"external=990000000000000008\n");$this->externalPath=null;} return $this->fault==='write'?false:parent::writeChunk($file,$bytes); }
    protected function sync($file): bool { return $this->fault!=='flush' && parent::sync($file); }
    protected function replace(string $from,string $to): bool {
        ++$this->replacements;
        if ($this->fault==='replace' || ($this->fault==='partial' && $this->replacements===2)) { return false; }
        if ($this->fault==='changed-throw') { parent::replace($from,$to); throw new RuntimeException('synthetic after commit'); }
        return parent::replace($from,$to);
    }
    protected function syncJournal($file,bool $pending):bool{return !($this->fault==='journal-clear-sync' && !$pending) && parent::syncJournal($file,$pending);}
    protected function replaceReservation(string $path,bool $pending):bool{
        if($this->fault==='journal-clear-sync' && !$pending && ($GLOBALS['journalPreimage']??false)){
            $file=fopen($path,'r+b');ftruncate($file,0);fclose($file);return false; // Historical live-truncate negative control.
        }
        return parent::replaceReservation($path,$pending);
    }
    protected function removeStage(string $path): bool { return $this->fault!=='cleanup' && parent::removeStage($path); }
}
$base=realpath($argv[2]??sys_get_temp_dir());if($base===false){throw new RuntimeException('Owned temp base unavailable');}
$root=$base.'/access-sync-'.bin2hex(random_bytes(8));mkdir($root);$root=realpath($root);if($root===false){throw new RuntimeException('Owned temp root unavailable');}
$targets=[];foreach(['approved','whitelist'] as $kind){$targets[$kind]=['path'=>$root.'/'.$kind.'.txt','required_roles'=>['Verified'],'allow_creation'=>false,'allow_empty'=>true,'metadata_policy'=>'inherit-parent'];}
$unmappedWire=$wire;$wire=json_encode(array_replace(AccessListProjection::decode($wire),['unmapped_role_holders'=>0]),JSON_THROW_ON_ERROR);
$read=fn()=>array_map(fn($t)=>file_get_contents($t['path']),$targets);
$setOld=function()use($targets,$root):void{foreach($targets as $t){file_put_contents($t['path'],"old=990000000000000009\n");}file_put_contents($root.'/.civilizationbot-access.lock','');file_put_contents($root.'/.civilizationbot-access.state','');};
try {
    $w=new FaultWriter($targets,4096);check(($w($unmappedWire,1,fn()=>true)['reason']??null)==='unmapped-role-holders','Incomplete role mapping refuses before file access');
    $w=new FaultWriter($targets,4096); check($w($wire,1,fn()=>true)['state']==='blocked' && !file_exists($targets['approved']['path']),'No implicit initial creation');
    $creation=$targets;foreach($creation as &$t){$t['allow_creation']=true;}unset($t);
    $w=new FaultWriter($creation,4096);check($w($wire,1,fn()=>true)['state']==='updated' && $read()===['approved'=>$expected,'whitelist'=>$expected],'Real same-directory initial publication');
    check($w($wire,2,fn()=>true)['state']==='unchanged','Checked unchanged');
    $reordered=new FaultWriter(array_reverse($creation,true),4096);check($reordered($wire,2,fn()=>true)['state']==='unchanged','JSON target ordering does not change policy/publication');
    $empty=json_encode(['outputs'=>['approved'=>"\n",'whitelist'=>"\n"],'unmapped_role_holders'=>0]);
    check($w($empty,3,fn()=>true)['state']==='updated' && $read()===['approved'=>"\n",'whitelist'=>"\n"],'Authoritative LF-empty publication revokes previous bytes');
    $denied=$targets;$denied['approved']['allow_empty']=false;$setOld();$before=$read();$deny=new FaultWriter($denied,4096);check($deny($empty,4,fn()=>true)['state']==='blocked' && $read()===$before,'Empty policy cannot be inferred');
    $lock=fopen($root.'/.civilizationbot-access.lock','c+b');flock($lock,LOCK_EX);$contended=new FaultWriter($targets,4096);check($contended($wire,1,fn()=>true)['state']==='blocked' && $read()===$before,'Cooperative writer contention retains old bytes');flock($lock,LOCK_UN);fclose($lock);
    $setOld();$before=$read();$w=new FaultWriter($targets,4096);check($w($wire,1,fn()=>false)['state']==='superseded' && $read()===$before,'Final source guard');
    foreach(['write','flush','replace'] as $fault){$setOld();$before=$read();$w=new FaultWriter($targets,4096);$w->fault=$fault;check($w($wire,1,fn()=>true)['state']==='blocked' && $read()===$before,'Known-good retained on '.$fault);check(glob($root.'/.civ13-access-*.tmp')===[],'Owned staging cleanup '.$fault);}
    $setOld();$w=new FaultWriter($targets,4096);$w->fault='destination';$w->externalPath=$targets['approved']['path'];check($w($wire,1,fn()=>true)['state']==='blocked' && file_get_contents($targets['approved']['path'])==="external=990000000000000008\n",'Observed destination change never clobbered');
    $setOld();$w=new FaultWriter($targets,4096);$w->fault='partial';refuses(fn()=>$w($wire,1,fn()=>true),'Partial publication not success');check($read()===['approved'=>$expected,'whitelist'=>"old=990000000000000009\n"],'Partial receipts reflect actual file effects');$n=$w->replacements;check($w($wire,2,fn()=>true)['state']==='blocked' && $w->replacements===$n,'Partial failure latched, no replay');$fresh=new FaultWriter($targets,4096);check(($fresh($wire,3,fn()=>true)['reason']??null)==='pending-publication-reconciliation' && $fresh->replacements===0,'Fresh owner cannot replay a retained pending publication');
    $setOld();$w=new FaultWriter($targets,4096);$w->fault='journal-clear-sync';refuses(fn()=>$w($wire,1,fn()=>true),'Clear-stage sync failure never reports success');
    $markerHeld=file_get_contents($root.'/.civilizationbot-access.state')==="civ13.access.pending.v1\n";
    $fresh=new FaultWriter($targets,4096);check(($fresh($wire,2,fn()=>true)['reason']??null)==='pending-publication-reconciliation' && $fresh->replacements===0,'Fresh owner observes hold after failed clearing sync');
    check($markerHeld,'Pending marker survives failed clearing sync');
    $setOld();$w=new FaultWriter($targets,4096);$w->fault='changed-throw';refuses(fn()=>$w($wire,1,fn()=>true),'Uncertain replace result not no-mutation');check(($w->lastReceipt()['integrity_uncertain']??false)===true,'Unknown commit retained');
    $setOld();$w=new FaultWriter($targets,4096);$w->fault='cleanup';$calls=0;$guard=function()use(&$calls){return ++$calls===1;};refuses(fn()=>$w($wire,1,$guard),'Cleanup failure not success');check(($w->lastReceipt()['cleanup_unconfirmed']??false)===true,'Cleanup ownership retained');
} finally {
    foreach(glob($root.'/*')?:[] as $file){unlink($file);}foreach(glob($root.'/.*')?:[] as $file){if(is_file($file)){unlink($file);}}rmdir($root);
}
echo "PASS $checks access projection/publication controls\n";
