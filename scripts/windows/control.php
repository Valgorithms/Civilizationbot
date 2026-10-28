<?php
declare(strict_types=1);
require __DIR__.'/../../src/Civ13/PortableProfile.php';
require __DIR__.'/../../src/Civ13/PortableProcess.php';
require __DIR__.'/../../src/Civ13/DefaultBranchGuard.php';
use Civ13\PortableProfile;
use Civ13\PortableProcess;
try {
    if (count($argv)!==3 || !in_array($argv[1],['check','start','stop','update'],true)) { throw new RuntimeException('Usage: php control.php check|start|stop|update ABSOLUTE_PROFILE'); }
    $profile=PortableProfile::load($argv[2]);
    PortableProfile::assertRoot($profile, dirname(__DIR__, 2));
    if ($argv[1]==='stop') { PortableProcess::requestStop($profile['state_dir']); exit(0); }
    $errors=PortableProfile::preflight($profile,extension_loaded(...));
    if ($errors!==[]) { throw new RuntimeException(implode('; ',$errors)); }
    if ($argv[1]==='check') { echo "Preflight passed; not started\n"; exit(0); }
    if ($argv[1]==='update') {
        $lock=PortableProcess::lock($profile['state_dir']);
        try { echo json_encode(\Civ13\DefaultBranchGuard::verify($profile['root'],true),JSON_THROW_ON_ERROR)."\n"; }
        finally { fclose($lock); }
        exit(0);
    }
    \Civ13\DefaultBranchGuard::verify($profile['root']);
    if (is_file($profile['state_dir'].'/stop.request')) { throw new RuntimeException('Unresolved stop request; inspect previous instance before starting'); }
    // Clear a stale reload request only when no instance holds the lock; a live
    // instance's pending reload must survive a second start attempt.
    $lock=PortableProcess::lock($profile['state_dir']);
    PortableProcess::consumeReload($profile['state_dir']);
    fclose($lock);
    putenv('CIVILIZATIONBOT_PROFILE='.$argv[2]);
    do {
        $pipes=[];
        $process=PortableProcess::start(PortableProcess::argv(PHP_BINARY,$profile['root']),$profile['state_dir'],static function($args,$descriptors,$cwd,$options) use (&$pipes) { return proc_open($args,$descriptors,$pipes,$cwd,null,$options); });
        if (!is_resource($process)) { throw new RuntimeException('Child process creation failed'); }
        fclose($pipes[0]);
        // Foreground supervision avoids unowned background PHP copies; Ctrl+C is not a game stop.
        $exit=proc_close($process);
        // reloadconfig: relaunch so the child rereads the profile and environment. Hold the
        // instance lock while deciding, so a concurrent start cannot take the marker; if
        // another instance already owns the lock, it is the live bot and this one stands down.
        try { $lock=PortableProcess::lock($profile['state_dir']); } catch (RuntimeException) { $lock=null; }
        $reload=$lock!==null && PortableProcess::consumeReload($profile['state_dir']) && !is_file($profile['state_dir'].'/stop.request');
        if ($lock!==null) { fclose($lock); }
        // Recheck the profile and source first, so a broken edit stops here instead of looping.
        if ($reload) {
            $profile=PortableProfile::load($argv[2]);
            PortableProfile::assertRoot($profile, dirname(__DIR__, 2));
            $errors=PortableProfile::preflight($profile,extension_loaded(...));
            if ($errors!==[]) { throw new RuntimeException('Reload refused: '.implode('; ',$errors)); }
            \Civ13\DefaultBranchGuard::verify($profile['root']);
        }
    } while ($reload);
    exit($exit);
} catch (Throwable $error) { fwrite(STDERR,'Portable control failed: '.$error->getMessage().PHP_EOL); exit(1); }
