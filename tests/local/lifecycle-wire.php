<?php
declare(strict_types=1);
require $argv[1];
require __DIR__.'/../../src/Civ13/Local/OpenDreamControl.php';
use Civ13\Local\OpenDreamControl;
use function React\Promise\resolve;
$profile=json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);
$vectors=json_decode(file_get_contents($argv[5]),true,32,JSON_THROW_ON_ERROR);
$server=['engine'=>'opendream','host'=>'127.0.0.1','runtime_dir'=>$profile['runtime_dir'],'gameplay_port'=>$profile['game_port'],'topic_port'=>$profile['topic_port']];
$wire=$vectors['committed'];$calls=[];
$execute=static function($command,$deadline) use (&$wire,&$calls) { $calls[]=[$command,$deadline];return resolve(['exit_code'=>0,'stdout'=>json_encode($wire)]); };
$adapter=new OpenDreamControl($server,$argv[4],$argv[3],$argv[2],$execute,true,'civ13.lifecycle.v1');
function settled($promise): array { $value=null;$error=null;$promise->then(static function($v) use (&$value) {$value=$v;},static function($e) use (&$error) {$error=$e;});if($error){throw $error;}if(!is_array($value)){throw new RuntimeException('Unsettled response');}return $value; }
if(settled($adapter->request('restart',null,'wire-1'))!==$wire){throw new RuntimeException('Python terminal response changed');}
foreach(['recovery'=>'wire-1','pending'=>'wire-pending','not_found'=>'missing-1'] as $key=>$id){
    $wire=$vectors[$key];
    if(settled($adapter->recover($id,'restart'))!==$wire){throw new RuntimeException('Python recovery changed');}
    if(end($calls)[0][4]!=='operation-status'){throw new RuntimeException('Recovery mutated');}
}
foreach(['digest','profile','origin','ready','identity'] as $kind){
    $wire=$vectors['recovery'];
    switch($kind){
        case 'digest':$wire['receipt']['binding_digest']=str_repeat('0',64);break;
        case 'profile':$wire['receipt']['request']['profile_digest']=str_repeat('a',64);break;
        case 'origin':$wire['receipt']['request']['origin']='game';break;
        case 'ready':$wire['receipt']['launch_identity']['ready']=true;break;
        case 'identity':$wire['receipt']['operation_id']='other';break;
    }
    try{settled($adapter->recover('wire-1','restart'));}catch(Throwable){continue;}
    throw new RuntimeException('Accepted altered actual Python vector '.$kind);
}
echo "PASS Python-produced committed/pending/not_found PHP acceptance + digest/profile/origin/ready/identity negatives\n";
