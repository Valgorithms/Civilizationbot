<?php
declare(strict_types=1);
if (($argv[1] ?? '') === '--contender') {
    require $argv[4]; require __DIR__.'/../../src/Civ13/Server/FileRelay.php';
    (new \Civ13\Server\FileRelay($argv[3]))->relay($argv[2], fn()=>\React\Promise\resolve(null))
        ->then(fn($value)=>print($value ? 'DELIVERED' : 'BUSY'), fn()=>print('ERROR'));
    exit;
}
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../src/Civ13/Server/FileRelay.php';
use Civ13\Server\FileRelay;
use React\Promise\Deferred;
use function React\Promise\resolve;
use function React\Promise\reject;
function relayCheck(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }
function relayResult($promise) { $value=null; $error=null; $promise->then(function($v)use(&$value){$value=$v;},function($e)use(&$error){$error=$e;}); if($error){throw $error;} return $value; }
$root=sys_get_temp_dir().'/bot-relay-'.bin2hex(random_bytes(8)); mkdir($root,0700); mkdir($root.'/state',0700);
$log=$root.'/log'; $sent=[]; $audits=0;
$send=function($line)use(&$sent){$sent[]=$line; return resolve(null);};
$audit=function()use(&$audits){$audits++;};
try {
    file_put_contents($log,"first\npartial");
    relayResult((new FileRelay($root.'/state',$audit))->relay($log,$send));
    relayResult((new FileRelay($root.'/state',$audit))->relay($log,$send));
    relayCheck($sent===['first'],'Restart replayed acknowledged message');
    file_put_contents($log," done\n",FILE_APPEND);
    relayResult((new FileRelay($root.'/state',$audit))->relay($log,$send));
    relayCheck(end($sent)==='partial done','Restart lost incomplete message');
    file_put_contents($log,"retry\n",FILE_APPEND); $failed=false;
    (new FileRelay($root.'/state',$audit))->relay($log,fn()=>reject(new RuntimeException('transport failure')))->then(null,function()use(&$failed){$failed=true;});
    relayCheck($failed,'Transport rejection lost');
    relayResult((new FileRelay($root.'/state',$audit))->relay($log,$send));
    relayCheck(end($sent)==='retry','Failed delivery advanced durable cursor');
    file_put_contents($log,"locked\n",FILE_APPEND); $deferred=new Deferred();
    $relay=new FileRelay($root.'/state',$audit); $relay->relay($log,fn()=>$deferred->promise());
    $p=proc_open([PHP_BINARY,__FILE__,'--contender',$log,$root.'/state',realpath($argv[1]??__DIR__.'/../../vendor/autoload.php')],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,[]);
    if(!is_resource($p)){throw new RuntimeException('Cannot launch isolated contender');}
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    relayCheck(proc_close($p)===0 && $out==='BUSY' && $err==='','Cross-process exclusion failed');
    $deferred->resolve(null);
    // Truncate and regrow beyond old cursor: tail binding must still detect replacement.
    file_put_contents($log,"rotated\n".str_repeat("new\n",20)); $sent=[];
    relayResult((new FileRelay($root.'/state',$audit))->relay($log,$send));
    relayCheck($sent[0]==='rotated','Truncate/regrow identity failed');
    file_put_contents($log,str_repeat('x',150000)."\nafter\n"); $sent=[];
    for($i=0;$i<4;$i++) relayResult((new FileRelay($root.'/state',$audit))->relay($log,$send));
    relayCheck($sent===['after'] && $audits===1,'Oversized record blocked/replayed or leaked fragments');
    file_put_contents($log,str_repeat('z',9000)."\nnormal\n"); $sent=[];
    relayResult((new FileRelay($root.'/state',$audit))->relay($log,$send));
    relayCheck(strlen($sent[0])===9000 && $sent[1]==='normal','Previously blocking 8KB line lost');
    $state=glob($root.'/state/*.json')[0]; file_put_contents($state,'{invalid'); $sent=[]; $failed=false;
    (new FileRelay($root.'/state',$audit))->relay($log,$send)->then(null,function()use(&$failed){$failed=true;});
    relayCheck($failed && $sent===[],'Corrupt checkpoint caused historical replay');
    foreach ([str_repeat('x',2049), json_encode(['version'=>1,'identity'=>['invalid',0],'offset'=>0,'tail'=>hash('sha256',''),'skipping'=>false])] as $invalid) {
        file_put_contents($state,$invalid); $failed=false;
        (new FileRelay($root.'/state',$audit))->relay($log,$send)->then(null,function()use(&$failed){$failed=true;});
        relayCheck($failed && $sent===[],'Oversized or invalid identity checkpoint allowed replay');
    }
    echo "PASS durable restart/partial/retry, cross-process exclusion, truncate-regrow, bounded oversized audit/drain, 9KB delivery, corrupt-state failclosed\n";
} finally {
    foreach(glob($root.'/state/*') as $file){unlink($file);} rmdir($root.'/state');
    foreach(glob($root.'/*') as $file){unlink($file);} rmdir($root);
}
