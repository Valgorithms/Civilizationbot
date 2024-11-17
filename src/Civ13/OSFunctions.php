<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Civ13\Exceptions\MissingSystemPermissionException;
use React\ChildProcess\Process;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Class OSFunctions
 *
 * Provides various operating system related functions such as spawning child processes,
 * executing commands in the background, restarting the application, and checking port availability.
 *
 * @package Civ13
 */
class OSFunctions
{
    /**
     * Spawns a child process to execute the given command.
     *
     * This function attempts to create a new process to run the specified command.
     * On Windows systems, it returns null as the POSIX compatibility layer is insufficient.
     * On other systems, it uses `sudo` and `nohup` to run the command and sets up event listeners
     * to log the process's stdout data, end, error, and close events, as well as the exit event.
     *
     * @param string $cmd The command to be executed in the child process.
     * @return ?Process The created Process object, or null if running on Windows.
     */
    public static function spawnChildProcess(string $cmd): ?Process
    {
        if (PHP_OS_FAMILY == 'Windows') {
            return null; // Windows's POSIX compatibility layer is not good enough to support this
        } else {
            $process = new Process("sudo nohup $cmd");
            $process->stdout->on('data', fn($chunk) => error_log($chunk . PHP_EOL));
            $process->stdout->on('end', fn() => error_log('ended' . PHP_EOL));
            $process->stdout->on('error', fn(\Exception $e) => error_log('error: ' . $e->getMessage() . PHP_EOL));
            $process->stdout->on('close', fn() => error_log('closed' . PHP_EOL));
            $process->on('exit', fn($exitCode, $termSignal) => error_log(($termSignal === null) ? "Process exited with code $exitCode" . PHP_EOL : "Process terminated with signal $termSignal" . PHP_EOL));
            return $process;
        }
    }

    /**
     * Executes a command in the background.
     *
     * This function executes a given command in the background, either on Windows or Unix-based systems.
     * On Windows, it uses `popen` with the `start` command.
     * On Unix-based systems, it uses `proc_open` with `sudo nohup`.
     *
     * @param string $cmd The command to execute.
     * @return PromiseInterface<resource> A promise that resolves with the process resource on success, or rejects with an exception on failure.
     * @throws MissingSystemPermissionException If the necessary system permissions are not available or the command execution fails.
     */
    public static function execInBackground(string $cmd): PromiseInterface
    {
        if (PHP_OS_FAMILY == 'Windows') {
            if (($p = popen("start {$cmd}", "r")) === false) return reject(new MissingSystemPermissionException('popen() failed'));
            return resolve($p);
        } else {
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
        }
    }

    /**
     * Restarts the application by executing an external shell command.
     *
     * On Windows, it runs a batch file (`run.bat`) located in the current working directory.
     * On other operating systems, it runs a PHP script (`bot.php`) in the background using `nohup`.
     *
     * @return PromiseInterface<resource> A promise that resolves with the process resource on success, or rejects with a MissingSystemPermissionException on failure.
     *
     * @throws MissingSystemPermissionException If `popen()` or `proc_open()` fails, or if the process ID cannot be retrieved.
     */
    public static function restart(): PromiseInterface
    {
        if (PHP_OS_FAMILY == 'Windows') {
            if (($p = popen('cmd /c "' . getcwd() . '\run.bat"', "r")) === false) return reject(new MissingSystemPermissionException('popen() failed'));
            return resolve($p);
        } else {
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ];
            if (($proc = proc_open($output = 'sudo nohup php bot.php > botlog.txt &', $descriptorspec, $pipes)) === false) return reject(new MissingSystemPermissionException('proc_open() failed'));
            if (! $pid = proc_get_status($proc)['pid']) return reject(new MissingSystemPermissionException('proc_get_status() failed'));
            echo "Executing external shell command `$output` with PID $pid" . PHP_EOL;
            return resolve($proc);
        }
    }

    /**
     * Checks if a given port is available on the local machine.
     *
     * @param int $port The port number to check. Default is 1714.
     * @return PromiseInterface<resource|\Socket> A promise that resolves if the port is available, or rejects with an exception if it is not.
     * @throws \Exception If there is an error creating or binding the socket.
     */
    public static function portIsAvailable(int $port = 1714): PromiseInterface
    {
        if (($s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) return reject(new \Exception(socket_last_error()));
        if (socket_bind($s, '127.0.0.1', $port) === false) return reject(new \Exception(socket_last_error($s)));
        socket_close($s);
        return resolve($s);
    }
}