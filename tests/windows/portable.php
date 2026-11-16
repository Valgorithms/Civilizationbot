<?php
declare(strict_types=1);
require __DIR__.'/../../src/Civ13/PortableProfile.php';
require __DIR__.'/../../src/Civ13/PortableProcess.php';
use Civ13\PortableProfile;
use Civ13\PortableProcess;
function check(bool $ok): void { if (!$ok) { throw new RuntimeException('Assertion failed'); } }
function rejects(callable $call): void { try { $call(); } catch (Throwable $e) { return; } throw new RuntimeException('Expected rejection'); }
$dir=sys_get_temp_dir().'/portable-'.bin2hex(random_bytes(8));mkdir($dir);mkdir($dir.'/vendor');
try {
 file_put_contents($dir.'/bot.php','<?php throw new Exception("must never execute");');file_put_contents($dir.'/vendor/autoload.php','');file_put_contents($dir.'/separate.env','NOT_A_REAL_TOKEN=synthetic');
 $profile=['http_port'=>25565,'schema'=>'civilizationbot.portable.v1','root'=>$dir,'state_dir'=>$dir,'env_file'=>$dir.'/separate.env','gitdir'=>$dir,'options'=>['channel_ids'=>[],'role_ids'=>[]],'servers'=>['test'=>['basedir'=>$dir,'ip'=>'127.0.0.1','port'=>1714,'name'=>'TEST1714','host'=>'fixture','enabled'=>false,'discussion'=>'','playercount'=>'','ooc'=>'','lobby'=>'','asay'=>'','ic'=>'','transit'=>'','adminlog'=>'','debug'=>'','garbage'=>'','runtime'=>'','attack'=>'']]];
 $file=$dir.'/profile.json';file_put_contents($file,json_encode($profile));$loaded=PortableProfile::load($file);
 check($loaded['servers']['test']['port']==='1714');
 $explicit=$profile;$explicit['servers']['test']['admin_file']=$dir.'/external-admins.txt';file_put_contents($file,json_encode($explicit));
 check(PortableProfile::load($file)['servers']['test']['admin_file']===$dir.'/external-admins.txt');
 $invalidAdminPaths=['relative/admins.txt','D:admins.txt','https://example.invalid/admins.txt',"D:/admins\0.txt",'',null,17];
 if (PHP_OS_FAMILY==='Windows') {
  $invalidAdminPaths=array_merge($invalidAdminPaths,['/admins.txt','\\admins.txt','//server/share/admins.txt','\\\\server\\share\\admins.txt','//?/C:/admins.txt','\\\\?\\C:\\admins.txt']);
 } else { $invalidAdminPaths[]='C:/fixtures/admins.txt'; }
 foreach ($invalidAdminPaths as $path) {
  $bad=$profile;$bad['servers']['test']['admin_file']=$path;file_put_contents($file,json_encode($bad));rejects(fn()=>PortableProfile::load($file));
 }
 // Validate strings only; do not open these admin paths or probe drives/shares.
 $validAdminPaths=PHP_OS_FAMILY==='Windows'?['C:/fixtures/admins.txt','Z:\\fixtures\\admins.txt']:['/admins.txt','/var/lib/fixtures/admins.txt'];
 foreach ($validAdminPaths as $path) {
  $valid=$profile;$valid['servers']['test']['admin_file']=$path;file_put_contents($file,json_encode($valid));check(PortableProfile::load($file)['servers']['test']['admin_file']===$path);
 }
 $options=PortableProfile::runtimeOptions($loaded);
 foreach (['owner_id','technician_id','civ13_guild_id','verify_url'] as $field) { check($options[$field]===''); }
 check($options['filecache_path']===$dir.'/json/');
 $name='CIVBOT_SYNTHETIC_TEST';$previous=getenv($name);putenv($name.'=fixture-only');
 try { check(PortableProfile::environmentValue($name,[])==='');check(PortableProfile::environmentValue($name,[$name])==='fixture-only'); }
 finally { $previous===false ? putenv($name) : putenv($name.'='.$previous); }
 $bad=$profile;$bad['options']['owner_id']='invalid';file_put_contents($file,json_encode($bad));rejects(fn()=>PortableProfile::load($file));

 check(PortableProfile::preflight($loaded,fn($name)=>true,'8.3.0')===[]);
 PortableProfile::assertRoot($loaded,$dir);rejects(fn()=>PortableProfile::assertRoot($loaded,dirname($dir)));
 rejects(fn()=>PortableProfile::load('relative.json'));
 $bad=$profile;$bad['http_port']=1714;file_put_contents($file,json_encode($bad));rejects(fn()=>PortableProfile::load($file));
 check(count(PortableProfile::preflight($loaded,fn($name)=>false,'8.2.0'))===7);
 foreach (['ip'=>'civ13.com','port'=>1715,'basedir'=>'relative'] as $field=>$value) { $bad=$profile;$bad['servers']['test'][$field]=$value;file_put_contents($file,json_encode($bad));rejects(fn()=>PortableProfile::load($file)); }
 // Shard group regions: absolute directory strings only, never probed here.
 $valid=$profile;$valid['servers']['test']['shards']=[$dir.'/region-a',$dir.'/region-b'];file_put_contents($file,json_encode($valid));check(PortableProfile::load($file)['servers']['test']['shards']===[$dir.'/region-a',$dir.'/region-b']);
 foreach (['relative/region',[$dir.'/a'=>$dir.'/a'],[17],['relative'],array_fill(0,17,$dir),[PHP_OS_FAMILY==='Windows'?'/region':'C:/region'],null] as $shards) { $bad=$profile;$bad['servers']['test']['shards']=is_array($shards)||$shards===null?$shards:[$shards];file_put_contents($file,json_encode($bad));rejects(fn()=>PortableProfile::load($file)); }
 $valid=$profile;$valid['servers']['test']['region_group']=$dir.'/separate.env';file_put_contents($file,json_encode($valid));check(PortableProfile::load($file)['servers']['test']['region_group']===$dir.'/separate.env');
 foreach (['relative/earth.json',$dir.'/missing.json',$dir,[$dir],null] as $group) { $bad=$profile;$bad['servers']['test']['region_group']=$group;file_put_contents($file,json_encode($bad));rejects(fn()=>PortableProfile::load($file)); }
 // follow_front_door: the server's paths follow the group file's front door (gate behind a relay, else mainland).
 foreach (['gaia','north'] as $region) { mkdir($dir.'/regions/'.$region.'/SQL',0777,true);mkdir($dir.'/regions/'.$region.'/data/shard-host',0777,true);file_put_contents($dir.'/regions/'.$region.'/data/shard-host/host.json','{}'); }
 $groupFile=$dir.'/earth.json';$group=['root'=>$dir.'/regions','mainland'=>'gaia','gate'=>'north','relay_port'=>1717,'regions'=>[['id'=>'gaia','name'=>'Gaia','port'=>1744],['id'=>'north','name'=>'North','port'=>1714]]];
 file_put_contents($groupFile,"\xEF\xBB\xBF".json_encode($group));
 $follow=$profile;$follow['servers']['test']['region_group']=$groupFile;$follow['servers']['test']['follow_front_door']=true;$follow['servers']['test']['admin_file']=$dir.'/old-admins.txt';
 file_put_contents($file,json_encode($follow));$server=PortableProfile::load($file)['servers']['test'];
 check($server['basedir']===$dir.'/regions/north');check($server['admin_file']===$dir.'/regions/north/SQL/admins.txt');
 // The host moved the front door: the same profile follows it, and uses the group's shared admins file once there is one.
 $group['gate']='gaia';file_put_contents($groupFile,json_encode($group));mkdir($dir.'/regions/front-door/SQL',0777,true);file_put_contents($dir.'/regions/front-door/SQL/admins.txt','');
 $server=PortableProfile::load($file)['servers']['test'];
 check($server['basedir']===$dir.'/regions/gaia');check($server['admin_file']===$dir.'/regions/front-door/SQL/admins.txt');
 $gaia=$server;$gaia['gaia_host']=['enabled'=>true,'config'=>$dir.'/old-host.json'];check(PortableProfile::followFrontDoor($gaia)['gaia_host']['config']===$dir.'/regions/gaia/data/shard-host/host.json');
 unlink($dir.'/regions/north/data/shard-host/host.json');$group['gate']='north';file_put_contents($groupFile,json_encode($group));$kept=PortableProfile::followFrontDoor($gaia);
 check($kept['basedir']===$dir.'/regions/gaia');check($kept['gaia_host']['config']===$dir.'/old-host.json'); // host control cannot follow: nothing moves
 // Without a relay the mainland is the front door; an unreadable group or a missing region folder keeps the profile's own paths.
 unset($group['relay_port']);$group['mainland']='north';file_put_contents($groupFile,json_encode($group));check(PortableProfile::load($file)['servers']['test']['basedir']===$dir.'/regions/north');
 $group['mainland']='moon';$group['regions'][]=['id'=>'moon'];file_put_contents($groupFile,json_encode($group));check(PortableProfile::load($file)['servers']['test']['basedir']===$dir);
 file_put_contents($groupFile,'not json');$server=PortableProfile::load($file)['servers']['test'];check($server['basedir']===$dir);check($server['admin_file']===$dir.'/old-admins.txt');
 $group['mainland']='north';$off=$follow;$off['servers']['test']['follow_front_door']=false;file_put_contents($groupFile,json_encode($group));file_put_contents($file,json_encode($off));check(PortableProfile::load($file)['servers']['test']['basedir']===$dir);
 foreach (['yes',1,null] as $value) { $bad=$follow;$bad['servers']['test']['follow_front_door']=$value;file_put_contents($file,json_encode($bad));rejects(fn()=>PortableProfile::load($file)); }
 $bad=$profile;$bad['servers']['test']['follow_front_door']=true;file_put_contents($file,json_encode($bad));rejects(fn()=>PortableProfile::load($file));
 $remove=function(string $path) use (&$remove): void { foreach (array_diff(scandir($path),['.','..']) as $name) { is_dir($path.'/'.$name) ? $remove($path.'/'.$name) : unlink($path.'/'.$name); } rmdir($path); };$remove($dir.'/regions');
 $bad=$profile;unset($bad['options']['role_ids']);file_put_contents($file,json_encode($bad));rejects(fn()=>PortableProfile::load($file));
 $lock=PortableProcess::lock($dir);rejects(fn()=>PortableProcess::lock($dir));fclose($lock);
 $lock=PortableProcess::lock($dir);fclose($lock);
 $calls=[];$fake=PortableProcess::start(PortableProcess::argv(PHP_BINARY,$dir),$dir,function(...$args) use (&$calls) { $calls[]=$args;return 'FakeProcess'; });
 check($fake==='FakeProcess' && $calls[0][0]===[PHP_BINARY,$dir.'/bot.php'] && $calls[0][3]===['bypass_shell'=>true]);
 PortableProcess::requestStop($dir);check(is_file($dir.'/stop.request'));rejects(fn()=>PortableProcess::requestStop($dir));
 require __DIR__.'/../../src/Civ13/GameServer.php';
 $server=(new ReflectionClass(\Civ13\GameServer::class))->newInstanceWithoutConstructor();
 $old=getenv('CIVILIZATIONBOT_PROFILE');putenv('CIVILIZATIONBOT_PROFILE=synthetic-profile');
 try { foreach (['Host','Kill','Restart'] as $method) {
  try { $server->$method();throw new RuntimeException('Expected native control rejection'); }
  catch (RuntimeException $error) { check($error->getMessage()==='Native lifecycle controls are unavailable on Windows'); }
 } } finally { $old===false ? putenv('CIVILIZATIONBOT_PROFILE') : putenv('CIVILIZATIONBOT_PROFILE='.$old); }
 echo "PASS portable profile isolation, PHP/extensions, wrong destination/port/path, shell-free FakeProcess and stop ownership\n";
} finally { foreach (glob($dir.'/*') as $path) { if (is_dir($path)) { foreach (glob($path.'/*') as $child) { unlink($child); } rmdir($path); } else { unlink($path); } } rmdir($dir); }
