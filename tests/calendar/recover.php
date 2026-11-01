<?php
declare(strict_types=1);
// Synthetic fresh-process persistence probe; no bot bootstrap, client or environment loading.
require dirname(__DIR__, 2).'/vendor/autoload.php';
use Civ13\Calendar\{FileState, Ledger};
if (PHP_SAPI !== 'cli' || count($argv) !== 2 || basename($argv[1]) !== 'ledger.json'
    || !str_starts_with(basename(dirname($argv[1])), 'civ13-calendar-')) { exit(2); }
$file = new FileState($argv[1]);
$ledger = new Ledger('900000000000000001', $file->read(), fn (array $state) => $file->write($state));
$ledger->invalidate(1793577607);
$ledger->replace(['events' => [], 'notes' => []], 1793577607);
echo json_encode($ledger->snapshot(1793577607), JSON_THROW_ON_ERROR);
