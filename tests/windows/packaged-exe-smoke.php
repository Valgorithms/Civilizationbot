<?php
declare(strict_types=1);
// Explicit opt-in only: runs a copied EXE in CHECK_ONLY against a synthetic profile, never bot.php.
function check(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
// Only this harness's bounded, synthetic child output is recorded; no real profile/token is supplied.
function probeEvidence(int $exit,string $out,string $err):string{
 return json_encode(['exit'=>$exit,'stdout'=>substr($out,0,8192),'stderr'=>substr($err,0,8192)],JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
}
function probe(string $exe,string $cwd):array{
 $pipes=[];$environment=['CIVILIZATIONBOT_CHECK_ONLY'=>'1','TOKEN'=>'synthetic-inherited-only','CIVILIZATIONBOT_PROFILE'=>'/do-not-use.json'];
 foreach(['SystemRoot','PATH','TEMP','TMP']as$key){if(getenv($key)!==false){$environment[$key]=getenv($key);}}
 $process=proc_open([$exe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,$environment,['bypass_shell'=>true]);
 check(is_resource($process),'EXE probe unavailable');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
 $out='';$err='';$start=hrtime(true);
 try{do{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$status=proc_get_status($process);
  if((hrtime(true)-$start)/1e9>5||strlen($out)+strlen($err)>65536){proc_terminate($process);throw new RuntimeException('EXE probe exceeded five seconds');}
  if($status['running']){usleep(10000);}
 }while($status['running']);$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
 }finally{fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}return[$status['exitcode'],$out,$err];
}
$source=realpath($argv[1]??'')?:throw new InvalidArgumentException('EXE path required');
$receipt=json_decode(file_get_contents($argv[2]??''),true,16,JSON_THROW_ON_ERROR);
check(hash_file('sha256',$source)===($receipt['exe_sha256']??null),'EXE digest differs from build receipt');
$root=sys_get_temp_dir().'/civilizationbot-exe-smoke-'.bin2hex(random_bytes(6));mkdir($root);
try{
 foreach(['exe','state','admission','caller']as$path){mkdir($root.'/'.$path);}
 $exe=$root.'/exe/civilizationbot.exe';copy($source,$exe);
 file_put_contents($root.'/separate.env',"this synthetic file must never be loaded\n");
 $profile=json_decode(file_get_contents(__DIR__.'/../../config/examples/windows-test1714.json'),true,16,JSON_THROW_ON_ERROR);
 foreach(['root'=>'admission','gitdir'=>'admission','state_dir'=>'state','env_file'=>'separate.env']as$key=>$path){$profile[$key]=$root.'/'.$path;}
 $profile['servers']['test1714']['basedir']=$root.'/state';
 file_put_contents($root.'/caller/civilizationbot.json',json_encode($profile,JSON_THROW_ON_ERROR));
 [$exit,$out,$err]=probe($exe,$root.'/caller');check($exit===1&&str_contains($err,'civilizationbot.json beside'),'missing adjacent profile did not fail closed: '.probeEvidence($exit,$out,$err));
 file_put_contents($root.'/exe/civilizationbot.json',json_encode($profile,JSON_THROW_ON_ERROR));
 foreach([$root.'/caller',$root.'/exe']as$cwd){[$exit,$out,$err]=probe($exe,$cwd);check($exit===0&&$err===''&&str_contains($out,'self-path=micro')&&str_contains($out,$receipt['source_commit']),'zero-argument native discovery/extension check failed: '.probeEvidence($exit,$out,$err));}
 check(glob($root.'/state/*')===[],'EXE diagnostic wrote runtime state');
 echo "PASS pinned Windows EXE, zero arguments, executable-relative config from two cwd values, missing config and no bot/state effects\n";
}finally{
 $base=realpath($root);$remove=static function($path)use(&$remove,$base){$real=realpath($path);if($real===false||($real!==$base&&!str_starts_with($real,$base.DIRECTORY_SEPARATOR))||is_link($path)){throw new RuntimeException('Smoke cleanup boundary refused');}foreach(new DirectoryIterator($path)as$file){if($file->isDot())continue;$file->isDir()?$remove($file->getPathname()):unlink($file->getPathname());}rmdir($path);};$remove($root);
}
