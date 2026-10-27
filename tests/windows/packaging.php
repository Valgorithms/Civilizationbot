<?php
declare(strict_types=1);
require __DIR__.'/../../packaging/windows/Builder.php';
require __DIR__.'/../../src/Civ13/PortableProfile.php';
require __DIR__.'/../../src/Civ13/ExecutableProfile.php';
use Civ13\ExecutableProfile;
use Civ13\Packaging\Builder;
function check(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
function denied(callable $call,string $message):void{try{$call();}catch(Throwable){return;}throw new RuntimeException($message);}
function child(array $argv,string $cwd):array{
 $pipes=[];$environment=['CIVILIZATIONBOT_CHECK_ONLY'=>'1','TOKEN'=>'inherited-synthetic-only','CIVILIZATIONBOT_PROFILE'=>'/must-not-be-discovered.json'];
 foreach(['SystemRoot','PATH','TEMP','TMP'] as $name){if(getenv($name)!==false){$environment[$name]=getenv($name);}}
 $process=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,$environment,['bypass_shell'=>true]);
 check(is_resource($process),'synthetic child unavailable');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
 $out='';$err='';$start=hrtime(true);
 try{do{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$status=proc_get_status($process);
  if((hrtime(true)-$start)/1e9>5||strlen($out)+strlen($err)>65536){proc_terminate($process);throw new RuntimeException('nonconnecting child exceeded bound');}
  if($status['running']){usleep(10000);}
 }while($status['running']);$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
 }finally{fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}return[$status['exitcode'],$out,$err];
}
$root=sys_get_temp_dir().'/civilizationbot-package-'.bin2hex(random_bytes(6));mkdir($root);$previous=getcwd();
try{
 foreach(['app/src/Civ13','app/packaging/windows','app/vendor','admission','state','elsewhere','out'] as $path){mkdir($root.'/'.$path,0700,true);}
 $stage=$root.'/app';
 foreach(['PortableProfile','ExecutableProfile','DefaultBranchGuard'] as $name){copy(__DIR__.'/../../src/Civ13/'.$name.'.php',$stage.'/src/Civ13/'.$name.'.php');}
 copy(__DIR__.'/../../packaging/windows/entry.php',$stage.'/packaging/windows/entry.php');
 file_put_contents($stage.'/vendor/autoload.php','<?php throw new RuntimeException("Composer must not be loaded by CHECK_ONLY");');
 file_put_contents($stage.'/bot.php','<?php throw new RuntimeException("Bot must never start in this fixture");');
 file_put_contents($stage.'/.env','TOKEN=must-never-be-packed');file_put_contents($stage.'/token.php','must-never-be-packed');
 file_put_contents($stage.'/profile.json','must-never-be-packed');file_put_contents($stage.'/output.log','must-never-be-packed');
 file_put_contents($root.'/admission/composer.lock','{}');
 $manifest=['schema'=>'civilizationbot.executable.v1','source_commit'=>str_repeat('a',40),'composer_lock_sha256'=>hash_file('sha256',$root.'/admission/composer.lock')];
 file_put_contents($stage.'/package-manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
 file_put_contents($root.'/separate.env',"this synthetic file must not be loaded\n");
 $profile=json_decode(file_get_contents(__DIR__.'/../../config/examples/windows-test1714.json'),true,16,JSON_THROW_ON_ERROR);
 foreach(['root'=>'admission','gitdir'=>'admission','state_dir'=>'state','env_file'=>'separate.env'] as $key=>$path){$profile[$key]=$root.'/'.$path;}
 $profile['servers']['test1714']['basedir']=$root.'/state';
 // Override the example's Windows admin path for this platform-local fixture too.
 $profile['servers']['test1714']['admin_file']=$root.'/state/admins.txt';
 $exe=$root.'/out/synthetic.exe';file_put_contents($exe,'MZ-not-an-executable');
 // Poison caller/ancestor config never supplies a profile or credentials.
 file_put_contents($root.'/civilizationbot.json',json_encode($profile));file_put_contents($root.'/elsewhere/civilizationbot.json',json_encode($profile));
 chdir($root.'/elsewhere');denied(fn()=>ExecutableProfile::discover($exe),'caller/ancestor configuration was used');
 file_put_contents($root.'/out/civilizationbot.json',json_encode($profile,JSON_THROW_ON_ERROR));
 $plan=ExecutableProfile::inspect($exe,$stage,$manifest,fn()=>true);
 check(realpath($plan['profile_path'])===realpath($root.'/out/civilizationbot.json'),'executable-relative profile missing');
 $guardCalled=false;
 ExecutableProfile::admit($plan,function($path)use(&$guardCalled,$root,$manifest){$guardCalled=$path===$root.'/admission';return['commit'=>$manifest['source_commit']];});
 check($guardCalled,'external source admission was skipped');
 denied(fn()=>ExecutableProfile::admit($plan,fn()=>throw new RuntimeException('existing access denial')),'guard denial was bypassed');
 denied(fn()=>ExecutableProfile::admit($plan,fn()=>['commit'=>str_repeat('b',40)]),'different source commit admitted');
 file_put_contents($root.'/admission/composer.lock','changed');denied(fn()=>ExecutableProfile::admit($plan,fn()=>['commit'=>$manifest['source_commit']]),'different dependency lock admitted');
 file_put_contents($root.'/admission/composer.lock','{}');
 denied(fn()=>Builder::verifyRuntime('MZ-unrecognized'),'unrecognized runtime accepted');
 mkdir($root.'/source');copy($stage.'/bot.php',$root.'/source/bot.php');
 $sourceHashes=['bot.php'=>hash_file('sha256',$root.'/source/bot.php')];
 Builder::verifySourceFiles($root.'/source',$stage,$sourceHashes);
 file_put_contents($stage.'/bot.php','<?php echo "injected";');
 denied(fn()=>Builder::verifySourceFiles($root.'/source',$stage,$sourceHashes),'changed staged source retained original provenance');
 copy($root.'/source/bot.php',$stage.'/bot.php');
 denied(fn()=>Builder::writeReceipt($root.'/missing/source.json',[]),'missing receipt destination accepted');
 $vendorHashes=Builder::vendorHashes($stage);Builder::verifyVendor($stage,$vendorHashes);
 $autoload=file_get_contents($stage.'/vendor/autoload.php');file_put_contents($stage.'/vendor/autoload.php','<?php echo "changed";');
 denied(fn()=>Builder::verifyVendor($stage,$vendorHashes),'changed vendor files retained installed provenance');
 file_put_contents($stage.'/vendor/autoload.php',$autoload);
 foreach(['.env','.env.local','token.php','auth.json','profile.json','output.log','json/state.json','config.json','vendor/a/.env','../bot.php'] as $path){check(!Builder::allowed($path),'unsafe archive input '.$path);}
 $phar=$root.'/out/application.phar';Builder::archive($stage,$phar);
 $archive=new Phar($phar);check(isset($archive['bot.php'])&&isset($archive['vendor/autoload.php'])&&isset($archive['packaging/windows/entry.php']),'application/dependency bundle missing');
 foreach(['.env','token.php','profile.json','output.log'] as $path){check(!isset($archive[$path]),'external state packed');}unset($archive);
 check(!str_contains(file_get_contents($phar),'must-never-be-packed'),'credential/state sentinel entered the artifact');
 foreach([$root.'/elsewhere',$root.'/admission'] as $cwd){
  [$exit,$stdout,$stderr]=child([PHP_BINARY,$phar],$cwd);
  check($exit===0&&str_contains($stdout,'PASS packaged configuration discovery')&&$stderr==='','no-argument PHAR discovery failed: '.$stderr);
  check(!str_contains($stdout,'synthetic-only')&&!str_contains($stdout,'must-never'),'credential content exposed');
 }
 unlink($root.'/out/civilizationbot.json');[$exit,$stdout,$stderr]=child([PHP_BINARY,$phar],$root.'/elsewhere');
 check($exit===1&&str_contains($stderr,'Place an operator-configured civilizationbot.json beside'),'missing-profile error is not actionable');
 check(glob($root.'/state/*')===[],'nonconnecting check wrote runtime state');
 echo "PASS allowlisted PHAR, no-argument startup from arbitrary cwd, missing configuration, preserved admission/commit/lock gates, unknown runtime rejection and no environment/bootstrap/state effects\n";
}finally{
 chdir($previous);$base=realpath($root);
 $remove=static function($path)use(&$remove,$base){$real=realpath($path);if($real===false||($real!==$base&&!str_starts_with($real,$base.DIRECTORY_SEPARATOR))||is_link($path)){throw new RuntimeException('Fixture cleanup boundary refused');}foreach(new DirectoryIterator($path)as$file){if($file->isDot())continue;$file->isDir()?$remove($file->getPathname()):unlink($file->getPathname());}rmdir($path);};$remove($root);
}
