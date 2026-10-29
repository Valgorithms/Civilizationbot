<?php
declare(strict_types=1);
// Dependency-free real I/O only inside an owned temporary fixture directory.
require_once __DIR__.'/../../src/Civ13/PortableProfile.php';
require_once __DIR__.'/../../src/Civ13/Server/AdminFileWriter.php';

use Civ13\Server\AdminFileWriter;

function writerCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function writerThrows(callable $call, string $message): void {
    try { $call(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException($message);
}
function writerUnlocked(string $path): void {
    $handle=fopen($path,'r+b'); writerCheck($handle!==false,'Could not reopen fixture');
    try { writerCheck(flock($handle,LOCK_EX|LOCK_NB),'Writer leaked its lock'); }
    finally { flock($handle,LOCK_UN); fclose($handle); }
}
final class FailingAdminFileWriter extends AdminFileWriter {
    public int $writes=0;
    public string $failure='';
    protected function writeChunk($file,string $bytes): int|false {
        ++$this->writes;
        if ($this->failure==='zero') { return 0; }
        if ($this->failure==='false') { return false; }
        if ($this->failure==='throw') { throw new RuntimeException('Synthetic write failure'); }
        if ($this->failure==='partial' && $this->writes>1) { return false; }
        return parent::writeChunk($file,substr($bytes,0,3));
    }
    protected function truncate($file,int $length): bool {
        return $this->failure==='truncate'?false:parent::truncate($file,$length);
    }
    protected function flush($file): bool {
        return $this->failure==='flush'?false:parent::flush($file);
    }
}

$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'admin-writer-'.bin2hex(random_bytes(8));
writerCheck(mkdir($directory),'Could not create temporary fixture directory');
$root=realpath($directory); writerCheck(is_string($root),'Fixture canonical path unavailable');
$path=$root.DIRECTORY_SEPARATOR.'admins.txt'; $link=$root.DIRECTORY_SEPARATOR.'link.txt';
$reader=null; $holder=null;
try {
    file_put_contents($path,'baseline-tail');
    $before=lstat($path);
    writerCheck(is_array($before) && is_int($before['ino']) && $before['ino']!==0 && PHP_INT_SIZE>=8,
        'Platform lacks reliable nonzero file identity: writer must remain blocked, not weaken checks');
    $writer=new AdminFileWriter($path,4096);
    $missing=$root.DIRECTORY_SEPARATOR.'missing.txt';
    $r=(new AdminFileWriter($missing,4096))('candidate',7,static fn()=>false);
    writerCheck($r['state']==='superseded' && !file_exists($missing),'Initial guard created missing path');
    $r=$writer('candidate',7,static fn()=>false);
    writerCheck($r['state']==='superseded' && file_get_contents($path)==='baseline-tail','Initial guard modified baseline');
    $r=$writer('candidate',7,static function (): bool { throw new RuntimeException('Synthetic guard error'); });
    writerCheck($r['state']==='superseded' && file_get_contents($path)==='baseline-tail','Throwing guard modified baseline');
    foreach ([2,3] as $denyAt) {
        $checks=0;
        $r=$writer('candidate',7,static function () use (&$checks,$denyAt): bool { return ++$checks<$denyAt; });
        writerCheck($r['state']==='superseded' && $checks===$denyAt && file_get_contents($path)==='baseline-tail','Under-lock/final guard modified baseline');
        writerUnlocked($path);
    }
    // The second guard is called while the exclusive lock is held.
    $checks=0; $observedLock=false;
    $r=$writer('candidate',7,static function () use (&$checks,&$observedLock,$path): bool {
        if (++$checks!==2) { return true; }
        $second=@fopen($path,'r+b');
        if ($second===false) { $observedLock=true; return false; }
        $acquired=@flock($second,LOCK_EX|LOCK_NB);
        $observedLock=!$acquired;
        if ($acquired) { flock($second,LOCK_UN); }
        fclose($second); return false;
    });
    writerCheck($r['state']==='superseded' && $observedLock && file_get_contents($path)==='baseline-tail','Guard ran without exclusive lock');
    // One attempt while another handle holds the lock: no wait/retry/mutation.
    $holder=fopen($path,'r+b'); writerCheck(flock($holder,LOCK_EX|LOCK_NB),'Could not lock fixture');
    $start=microtime(true); $r=$writer('candidate',7,static fn()=>true); $elapsed=microtime(true)-$start;
    flock($holder,LOCK_UN); fclose($holder); $holder=null;
    writerCheck($r['state']==='blocked' && $elapsed<5 && file_get_contents($path)==='baseline-tail','Lock contention did not promptly block untouched');
    $r=$writer('baseline-tail',7,static fn()=>true);
    writerCheck($r['state']==='unchanged','Exact content not checked unchanged');
    $checks=0;
    $r=$writer('baseline-tail',7,static function () use (&$checks): bool { return ++$checks<3; });
    writerCheck($r['state']==='superseded','Unchanged acknowledged after final guard failed');
    $reader=fopen($path,'rb'); $opened=fstat($reader);
    $r=$writer("fakealpha;Fixture;3|||\n",7,static fn()=>true);
    writerCheck($r['state']==='updated' && file_get_contents($path)==="fakealpha;Fixture;3|||\n",'LF update failed');
    rewind($reader); writerCheck(stream_get_contents($reader)==="fakealpha;Fixture;3|||\n",'Update replaced reader identity');
    clearstatcache(true); $after=lstat($path);
    writerCheck($before['dev']===$after['dev'] && $before['ino']===$after['ino'] && $opened['ino']===$after['ino'],'Identity changed');
    writerCheck(($before['mode']&0777)===($after['mode']&0777),'Filesystem mode changed');
    $r=$writer('x',7,static fn()=>true); writerCheck($r['state']==='updated' && file_get_contents($path)==='x','Short write retained stale tail');
    $r=$writer('',7,static fn()=>true); writerCheck($r['state']==='updated' && file_get_contents($path)==='','Empty authoritative update failed');
    rewind($reader); writerCheck(stream_get_contents($reader)==='','Empty update lost existing reader');
    fclose($reader); $reader=null;
    writerCheck($writer('',7,static fn()=>true)['state']==='unchanged','Existing empty file not checked unchanged');
    writerCheck((new AdminFileWriter($missing,4096))('x',7,static fn()=>true)['state']==='blocked' && !file_exists($missing),'Missing file was created');
    $missingParent=$root.DIRECTORY_SEPARATOR.'absent'.DIRECTORY_SEPARATOR.'admins.txt';
    writerCheck((new AdminFileWriter($missingParent,4096))('x',7,static fn()=>true)['state']==='blocked' && !file_exists(dirname($missingParent)),'Parent directory created');
    writerCheck((new AdminFileWriter($root,4096))('x',7,static fn()=>true)['state']==='blocked','Directory accepted as target');
    file_put_contents($path,'baseline');
    writerCheck((new AdminFileWriter($path,4))('x',7,static fn()=>true)['state']==='blocked' && file_get_contents($path)==='baseline','Oversized existing file mutated');
    writerCheck($writer(str_repeat('x',4097),7,static fn()=>true)['state']==='blocked' && file_get_contents($path)==='baseline','Oversized candidate mutated');
    // Temporary symlink fixture only; never change privilege settings to enable this test.
    if (function_exists('symlink') && @symlink($path,$link)) {
        writerCheck((new AdminFileWriter($link,4096))('x',7,static fn()=>true)['state']==='blocked' && file_get_contents($path)==='baseline','Observed symlink was followed for writing');
        unlink($link);
    } else { echo "SKIP symlink fixture: platform did not permit creation; writer rejection checks remain enabled\n"; }
    foreach (['zero','false','throw','partial','truncate','flush'] as $failure) {
        file_put_contents($path,'old-baseline'); $failing=new FailingAdminFileWriter($path,4096); $failing->failure=$failure;
        writerThrows(fn()=>$failing('NEW-content',7,static fn()=>true),'Mutation failure was reported as terminal success');
        if (in_array($failure,['zero','false','throw'],true)) { writerCheck(file_get_contents($path)==='old-baseline','First-write failure unexpectedly mutated fixture'); }
        if ($failure==='partial') { writerCheck(str_starts_with(file_get_contents($path),'NEW'),'Partial failure was silently rolled back'); }
        writerUnlocked($path);
    }
    file_put_contents($path,'old-baseline'); $short=new FailingAdminFileWriter($path,4096);
    writerCheck($short('NEW-content',7,static fn()=>true)['state']==='updated' && $short->writes>1 && file_get_contents($path)==='NEW-content','Short-write loop failed');
    foreach (['relative.txt',$root.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'escape.txt',"bad\0path",'https://example.invalid/admins.txt'] as $invalid) {
        try { new AdminFileWriter($invalid,4096); throw new LogicException('Unsafe path accepted'); }
        catch (InvalidArgumentException $expected) {}
    }
    echo "PASS existing-file writer: guards, nonblocking contention, checked unchanged, LF/empty/truncation, identity and deterministic failures\n";
} finally {
    if (is_resource($reader)) { fclose($reader); }
    if (is_resource($holder)) { flock($holder,LOCK_UN); fclose($holder); }
    if (is_link($link)) { unlink($link); }
    if (is_file($path)) { unlink($path); }
    rmdir($root);
}
