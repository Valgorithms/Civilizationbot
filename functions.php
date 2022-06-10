<?php
if (PHP_OS_FAMILY == "Windows") {
    function execInBackground($cmd) {
        pclose(popen("start ". $cmd, "r")); //pclose(popen("start /B ". $cmd, "r"));;
    };
    function restart() {
        pclose(popen('cmd /c "'. getcwd() . '\run.bat"')); //pclose(popen("start /B ". $cmd, "r"));;
    };
    
} else {
    function execInBackground($cmd) {
        //exec("sudo $cmd > /dev/null &"); //Executes within the same shell
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $output = "sudo $cmd > /dev/null &";
        $proc = proc_open($output, $descriptorspec, $pipes);
        $proc_details = proc_get_status($proc);
        $pid = $proc_details['pid'];
        echo "Executing external shell command `$output` with PID $pid";
    };
    function restart() {
        //exec("sudo nohup php8.1 bot.php > botlog.txt &");
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $output = 'sudo nohup php8.1 bot.php > botlog.txt &';
        $proc = proc_open('sudo nohup php8.1 bot.php > botlog.txt &', $descriptorspec, $pipes);
        $proc_details = proc_get_status($proc);
        $pid = $proc_details['pid'];
        echo "Executing external shell command `$output` with PID $pid";
    };
}

function portIsAvailable(int $port = 1714): bool
{
    $s = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

    try {
        if (var_dump(socket_bind($s, "127.0.0.1", $port))) {
            socket_close($s);
            return true;
        }
    } catch (Exception $e) {
        socket_close($s);
        return false;
    }
    socket_close($s);
    return false;
}