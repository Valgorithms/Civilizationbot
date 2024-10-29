<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

if (PHP_OS_FAMILY == 'Windows') {
    function spawnChildProcess($cmd): bool
    { // Not tested
        return execInBackground($cmd);
    }
    function execInBackground($cmd): bool
    {
        if (($p = popen("start {$cmd}", "r")) === false) return false;
        if (pclose($p) === -1) return false; // pclose(popen("start /B ". $cmd, "r"));
        return true;
    };
    function restart(): bool
    {
        if (($p = popen('cmd /c "'. getcwd() . '\run.bat"', "r")) === false) return false;
        if (pclose($p) === -1) return false; // pclose(popen("start /B ". $cmd, "r"));
        return true;
    };
} else {
    function spawnChildProcess($cmd): \React\ChildProcess\Process
    {
        $process = new React\ChildProcess\Process("sudo nohup $cmd");        
        $process->stdout->on('data', fn ($chunk) => error_log($chunk . PHP_EOL));
        $process->stdout->on('end', fn () => error_log('ended' . PHP_EOL));
        $process->stdout->on('error', fn (Exception $e) => error_log('error: ' . $e->getMessage() . PHP_EOL));
        $process->stdout->on('close', fn () => error_log('closed' . PHP_EOL));
        $process->on('exit', fn ($exitCode, $termSignal) => error_log(($termSignal === null) ? "Process exited with code $exitCode" . PHP_EOL : "Process terminated with signal $termSignal" . PHP_EOL));
        return $process;
    }
    function execInBackground($cmd): bool
    {
        // exec("sudo $cmd > /dev/null &"); // Executes within the same shell
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $output = "sudo nohup $cmd > /dev/null &";
        if (! $proc = proc_open($output, $descriptorspec, $pipes)) return false;
        if (! $proc_details = proc_get_status($proc)) return false;
        if (! isset($proc_details['pid']) || ! $pid = $proc_details['pid']) return false;
        echo "Executing external shell command `$output` with PID $pid" . PHP_EOL;
        return true;
    };
    function restart(): bool
    {
        // exec("sudo nohup php bot.php > botlog.txt &");
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $output = 'sudo nohup php bot.php > botlog.txt &';
        if (($proc = proc_open($output, $descriptorspec, $pipes)) === false) return false;
        if (! $pid = proc_get_status($proc)['pid']) return false;
        echo "Executing external shell command `$output` with PID $pid" . PHP_EOL;
        return true;
    };
}

function termChildProcess(React\ChildProcess\Process $process): bool
{
    foreach ($process->pipes as $pipe) $pipe->close();
    if (! $process->terminate()) return false;
    echo 'Child process terminated' . PHP_EOL;
    return true;
}

function portIsAvailable(int $port = 1714): bool
{
    if (($s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) return false;
    if (socket_bind($s, '127.0.0.1', $port) === false) return false;
    socket_close($s);
    return true;
}