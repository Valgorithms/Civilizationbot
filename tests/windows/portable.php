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
