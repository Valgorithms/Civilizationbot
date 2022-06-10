<?php
if (PHP_OS_FAMILY == "Windows") {
    function execInBackground($cmd) {
        pclose(popen("start ". $cmd, "r")); //pclose(popen("start /B ". $cmd, "r"));;
    };
} else {
    function execInBackground($cmd) {
        exec($cmd . " > /dev/null &");
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