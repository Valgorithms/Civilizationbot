<?php
declare(strict_types=1);
require __DIR__.'/../../src/Civ13/DefaultBranchGuard.php';
use Civ13\DefaultBranchGuard;
function check(bool $ok):void { if(!$ok){throw new RuntimeException('Default branch fixture failed');} }
function command(array $args, bool $success=true):array {
    $pipes=[];$p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    check(is_resource($p));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
    if($success&&$code!==0){throw new RuntimeException('Fixture Git/process operation failed');}return [$code,trim($out),$err];
}
function git(string $root,...$args):string { return command(['git','-c','user.name=Fixture','-c','user.email=fixture@example.invalid','-C',$root,...$args])[1]; }
function refused(callable $call):void { try{$call();}catch(RuntimeException $error){check(str_contains($error->getMessage(),'Default branch guard refused'));return;}throw new RuntimeException('Expected guard refusal'); }
$root=sys_get_temp_dir().'/default-branch-'.bin2hex(random_bytes(8));mkdir($root);
$previousProfile=getenv('CIVILIZATIONBOT_PROFILE');
try {
    $origin=$root.'/origin.git';$seed=$root.'/seed';$repo=$root.'/runtime';
    command(['git','init','--bare','--initial-branch=main',$origin]);command(['git','clone',$origin,$seed]);
    file_put_contents($seed.'/source.txt','first');git($seed,'add','source.txt');git($seed,'commit','-m','first');git($seed,'push','origin','main');command(['git','clone',$origin,$repo]);
    $verify=static fn(bool $update=false)=>DefaultBranchGuard::verify($repo,$update,'git',$origin);
    $first=git($repo,'rev-parse','HEAD');check($verify()['commit']===$first);
    file_put_contents($repo.'/source.txt','user edit');refused(fn()=>$verify(true));check(file_get_contents($repo.'/source.txt')==='user edit');file_put_contents($repo.'/source.txt','first');
    file_put_contents($repo.'/notes.txt','unrelated');refused(fn()=>$verify(true));check(file_get_contents($repo.'/notes.txt')==='unrelated');unlink($repo.'/notes.txt');
    git($repo,'update-index','--assume-unchanged','source.txt');refused(fn()=>$verify());git($repo,'update-index','--no-assume-unchanged','source.txt');
    git($repo,'update-index','--skip-worktree','source.txt');refused(fn()=>$verify());git($repo,'update-index','--no-skip-worktree','source.txt');
    git($repo,'config','core.sparseCheckout','true');refused(fn()=>$verify());git($repo,'config','core.sparseCheckout','false');
    git($repo,'switch','-c','pr');refused(fn()=>$verify());git($repo,'switch','main');
    git($repo,'switch','--detach','HEAD');refused(fn()=>$verify());git($repo,'switch','main');
    git($repo,'branch','--unset-upstream');refused(fn()=>$verify());git($repo,'branch','--set-upstream-to=origin/main');
    git($seed,'push','origin','main:pr');git($repo,'fetch','origin','refs/heads/pr:refs/remotes/origin/pr');
    git($repo,'branch','--set-upstream-to=origin/pr');refused(fn()=>$verify());git($repo,'branch','--set-upstream-to=origin/main');
    git($repo,'symbolic-ref','refs/remotes/origin/HEAD','refs/remotes/origin/pr');refused(fn()=>$verify());git($repo,'remote','set-head','origin','main');
    git($origin,'symbolic-ref','HEAD','refs/heads/pr');refused(fn()=>$verify());git($origin,'symbolic-ref','HEAD','refs/heads/main');
    git($repo,'remote','set-url','origin',$root.'/unexpected.git');refused(fn()=>$verify());git($repo,'remote','set-url','origin',$origin);
    file_put_contents($seed.'/source.txt','second');git($seed,'add','source.txt');git($seed,'commit','-m','second');git($seed,'push','origin','main');
    refused(fn()=>$verify());check(git($repo,'rev-parse','HEAD')===$first);
    $second=git($seed,'rev-parse','HEAD');check($verify(true)['commit']===$second&&git($repo,'symbolic-ref','--short','HEAD')==='main');
    file_put_contents($repo.'/source.txt','reviewed local code');git($repo,'add','source.txt');git($repo,'commit','-m','preserve local');$ahead=git($repo,'rev-parse','HEAD');
    refused(fn()=>$verify(true));check(git($repo,'rev-parse','HEAD')===$ahead&&file_get_contents($repo.'/source.txt')==='reviewed local code');
    file_put_contents($seed.'/source.txt','remote third');git($seed,'add','source.txt');git($seed,'commit','-m','remote third');git($seed,'push','origin','main');
    refused(fn()=>$verify(true));check(git($repo,'rev-parse','HEAD')===$ahead);
    check(str_starts_with(str_replace('\\','/',realpath($origin)),str_replace('\\','/',realpath($root)).'/'));rename($origin,$root.'/offline.git');
    refused(fn()=>$verify());check(git($repo,'rev-parse','HEAD')===$ahead);

    // Real control/direct bootstrap refuses detached source before credentials, Composer, spawn or network.
    $app=$root.'/app';$state=$root.'/state';mkdir($app);mkdir($state);mkdir($app.'/vendor');mkdir($app.'/scripts');mkdir($app.'/scripts/windows');mkdir($app.'/src');mkdir($app.'/src/Civ13');
    foreach(['DefaultBranchGuard','PortableProfile','PortableProcess'] as $class){copy(__DIR__.'/../../src/Civ13/'.$class.'.php',$app.'/src/Civ13/'.$class.'.php');}
    copy(__DIR__.'/../../scripts/windows/control.php',$app.'/scripts/windows/control.php');copy(__DIR__.'/../../bot.php',$app.'/bot.php');file_put_contents($app.'/vendor/autoload.php','<?php throw new RuntimeException("Composer must not load");');
    file_put_contents($root.'/separate.env','invalid fixture; must never load');
    $server=['basedir'=>$app,'ip'=>'127.0.0.1','port'=>1714,'name'=>'fixture','host'=>'fixture','enabled'=>false];foreach(['discussion','playercount','ooc','lobby','asay','ic','transit','adminlog','debug','garbage','runtime','attack']as$key){$server[$key]='';}
    $profile=['schema'=>'civilizationbot.portable.v1','root'=>$app,'state_dir'=>$state,'env_file'=>$root.'/separate.env','gitdir'=>$repo,'http_port'=>25565,'options'=>['channel_ids'=>[],'role_ids'=>[]],'servers'=>['test'=>$server]];
    file_put_contents($root.'/profile.json',json_encode($profile,JSON_THROW_ON_ERROR));git($app,'init','--initial-branch=main');git($app,'add','.');git($app,'commit','-m','fixture application');git($app,'switch','--detach','HEAD');
    foreach(['start','update']as$action){[$code,$out,$err]=command([PHP_BINARY,$app.'/scripts/windows/control.php',$action,$root.'/profile.json'],false);check($code!==0&&str_contains($err,'Default branch guard refused'));}
    require_once __DIR__.'/../../src/Civ13/PortableProcess.php';
    $lock=\Civ13\PortableProcess::lock($state);
    try { [$code,$out,$err]=command([PHP_BINARY,$app.'/scripts/windows/control.php','update',$root.'/profile.json'],false);check($code!==0&&str_contains($err,'Portable instance already running')); }
    finally { fclose($lock); }
    putenv('CIVILIZATIONBOT_PROFILE='.$root.'/profile.json');[$code,$out,$err]=command([PHP_BINARY,$app.'/bot.php'],false);check($code!==0&&str_contains($err,'Default branch guard refused')&&!str_contains($err,'Composer must not load'));
    echo "PASS real Git default/main/upstream/origin, detached/PR/dirty/hidden/ahead/diverged/offline controls, safe FF and actual start/update/bootstrap precredential denial\n";
} finally {
    putenv($previousProfile===false?'CIVILIZATIONBOT_PROFILE':'CIVILIZATIONBOT_PROFILE='.$previousProfile);
    $resolved=str_replace('\\','/',realpath($root));
    $remove=static function(string $path)use(&$remove,$resolved):void{
        $actual=str_replace('\\','/',realpath($path));if($actual!==$resolved&&!str_starts_with($actual,$resolved.'/')){throw new RuntimeException('Fixture cleanup escaped');}
        foreach(new DirectoryIterator($path)as$entry){if($entry->isDot())continue;if($entry->isDir()){$remove($entry->getPathname());}else{@chmod($entry->getPathname(),0600);unlink($entry->getPathname());}}rmdir($path);
    };$remove($root);
}
