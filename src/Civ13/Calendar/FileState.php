<?php
declare(strict_types=1);
namespace Civ13\Calendar;

/** Private ledger only; never serve this file. Lifetime lock rejects a second writer. */
final class FileState
{
    private mixed $lock;
    public function __construct(private string $path)
    {
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~D', $path) || !is_dir(dirname($path)) || is_link($path)) { throw new \InvalidArgumentException('Existing private directory and absolute ledger path required'); }
        $this->lock = fopen($path.'.lock', 'c');
        if ($this->lock === false || !flock($this->lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Calendar writer already owned or unavailable'); }
        @chmod($path.'.lock', 0600);
    }
    public function read(): array
    {
        if (!is_resource($this->lock)) { throw new \RuntimeException('Calendar state ownership released'); }
        if (!file_exists($this->path)) { return []; }
        if (filesize($this->path) > 8388608) { throw new \RuntimeException('Calendar ledger requires review'); }
        $state = json_decode(file_get_contents($this->path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($state)) { throw new \RuntimeException('Calendar ledger recovery required'); }
        return $state;
    }
    public function write(array $state): void
    {
        if (!is_resource($this->lock)) { throw new \RuntimeException('Calendar state ownership released'); }
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 8388608) { throw new \RuntimeException('Calendar ledger bound'); }
        $temp = $this->path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $file = fopen($temp, 'x');
            if ($file === false) { throw new \RuntimeException('Calendar staging unavailable'); }
            try {
                @chmod($temp, 0600);
                if (fwrite($file, $json) !== strlen($json) || !fflush($file) || (function_exists('fsync') && !fsync($file))) { throw new \RuntimeException('Calendar write incomplete'); }
            } finally { fclose($file); }
            if (!rename($temp, $this->path)) { throw new \RuntimeException('Calendar commit unavailable'); }
        } finally { if (file_exists($temp)) { unlink($temp); } }
    }
    public function close(): void { if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); } }
    public function __destruct() { $this->close(); }
}
