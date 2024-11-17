<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

use Civ13\Exceptions\MissingSystemPermissionException;
use \React\ChildProcess\Process;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

if (PHP_OS_FAMILY == 'Windows') {
    /**
     * Spawns a child process to execute the given command.
     *
     * @param string $cmd The command to be executed by the child process.
     * @return Process|null Returns a Process object if the child process is successfully spawned, or null if the operation is not supported.
     */
    function spawnChildProcess($cmd): ?Process
    {
        return null; // Windows's POSIX compatibility layer is not good enough to support this
    }
    /**
     * Executes a command in the background.
     *
     * @param string $cmd The command to execute.
     * @return PromiseInterface<resource> A promise that resolves when the command is executed or rejects if there is a failure.
     * @throws MissingSystemPermissionException If the popen() or pclose() functions fail.
     */
    function execInBackground($cmd): PromiseInterface
    {
        if (($p = popen("start {$cmd}", "r")) === false) return reject(new MissingSystemPermissionException('popen() failed'));
        return resolve($p);
    };
    /**
     * Restarts the application by executing a batch file.
     *
     * This function uses the `popen` and `pclose` functions to execute a batch file
     * located in the current working directory. If either `popen` or `pclose` fails,
     * it returns a rejected promise with a `MissingSystemPermissionException`.
     *
     * @return PromiseInterface<resource> A promise that resolves if the batch file is executed successfully,
     *                                    or rejects with a `MissingSystemPermissionException` if an error occurs.
     * @throws MissingSystemPermissionException If `popen` or `pclose` fails.
     */
    function restart(): PromiseInterface
    {
        if (($p = popen('cmd /c "'. getcwd() . '\run.bat"', "r")) === false) return reject(new MissingSystemPermissionException('popen() failed'));
        return resolve($p);
    };
} else {
    /**
     * Spawns a child process to run the given command.
     *
     * @param string $cmd The command to run in the child process.
     * @return ?Process Returns a React\ChildProcess\Process instance or null.
     *
     * The function sets up event listeners for the child process:
     * - 'data': Logs the output data from the process.
     * - 'end': Logs when the process output ends.
     * - 'error': Logs any errors that occur during the process execution.
     * - 'close': Logs when the process output is closed.
     * - 'exit': Logs the exit code or termination signal of the process.
     */
    function spawnChildProcess($cmd): ?Process
    {
        $process = new React\ChildProcess\Process("sudo nohup $cmd");        
        $process->stdout->on('data', fn ($chunk) => error_log($chunk . PHP_EOL));
        $process->stdout->on('end', fn () => error_log('ended' . PHP_EOL));
        $process->stdout->on('error', fn (Exception $e) => error_log('error: ' . $e->getMessage() . PHP_EOL));
        $process->stdout->on('close', fn () => error_log('closed' . PHP_EOL));
        $process->on('exit', fn ($exitCode, $termSignal) => error_log(($termSignal === null) ? "Process exited with code $exitCode" . PHP_EOL : "Process terminated with signal $termSignal" . PHP_EOL));
        return $process;
    }
    /**
     * Executes a command in the background using `proc_open` and `nohup`.
     *
     * @param string $cmd The command to execute.
     * @return PromiseInterface<resource> A promise that resolves with the process resource on success, or rejects with an exception on failure.
     * @throws MissingSystemPermissionException If `proc_open` or `proc_get_status` fails, or if the process ID (PID) is not returned.
     */
    function execInBackground($cmd): PromiseInterface
    {
        // exec("sudo $cmd > /dev/null &"); // Executes within the same shell
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        if (! $proc = proc_open($output = "sudo nohup $cmd > /dev/null &", $descriptorspec, $pipes)) return reject(new MissingSystemPermissionException('proc_open() failed'));
        if (! $proc_details = proc_get_status($proc)) return reject(new MissingSystemPermissionException('proc_get_status() failed'));
        if (! isset($proc_details['pid']) || ! $pid = $proc_details['pid']) return reject(new MissingSystemPermissionException('proc_get_status() did not return a PID'));
        echo "Executing external shell command `$output` with PID $pid" . PHP_EOL;
        return resolve($proc);
    };
    /**
     * Restarts the bot by executing an external shell command.
     *
     * This function uses `proc_open` to execute a shell command that runs the bot script (`bot.php`)
     * in the background and redirects its output to `botlog.txt`. It returns a promise that resolves
     * with the process resource or rejects with an exception if the process fails to start.
     *
     * @return PromiseInterface<resource> A promise that resolves with the process resource or rejects with an exception.
     * @throws MissingSystemPermissionException If `proc_open` or `proc_get_status` fails.
     */
    function restart(): PromiseInterface
    {
        // exec("sudo nohup php bot.php > botlog.txt &");
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        if (($proc = proc_open($output = 'sudo nohup php bot.php > botlog.txt &', $descriptorspec, $pipes)) === false) return reject(new MissingSystemPermissionException('proc_open() failed'));
        if (! $pid = proc_get_status($proc)['pid']) return reject(new MissingSystemPermissionException('proc_get_status() failed'));
        echo "Executing external shell command `$output` with PID $pid" . PHP_EOL;
        return resolve($proc);
    };
}

/**
 * Terminates a given child process and closes its pipes.
 *
 * @param Process $process The child process to terminate.
 * 
 * @return PromiseInterface A promise that resolves if the process is successfully terminated,
 *                          or rejects with a MissingSystemPermissionException if the SIGTERM signal fails to send.
 * 
 * @throws MissingSystemPermissionException If the SIGTERM signal fails to send.
 */
/*function termChildProcess(Process $process): PromiseInterface
{
    foreach ($process->pipes as $pipe) $pipe->close();
    if (! $process->terminate()) return reject(new MissingSystemPermissionException('SIGTERM signal failed to send'));
    return resolve($process->close());
}*/

/**
 * Checks if a given port is available on localhost.
 *
 * @param int $port The port number to check. Default is 1714.
 * @return PromiseInterface<resource|\Socket> A promise that resolves if the port is available, or rejects with an exception if it is not.
 * @throws \Exception If there is an error creating or binding the socket.
 */
function portIsAvailable(int $port = 1714): PromiseInterface
{
    if (($s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) return reject(new \Exception(socket_last_error()));
    if (socket_bind($s, '127.0.0.1', $port) === false) return reject(new \Exception(socket_last_error($s)));
    socket_close($s);
    return resolve($s);
}