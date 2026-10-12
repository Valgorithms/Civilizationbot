<?php
declare(strict_types=1);
foreach (['Transport', 'Client'] as $class) {
    require __DIR__.'/../../src/Civ13/Safehouse/'.$class.'.php';
}
use Civ13\Safehouse\Client;
use Civ13\Safehouse\Transport;
[$script, $directory, $mode] = $argv;
$transport = new class($directory, $mode) implements Transport {
    public function __construct(private string $directory, private string $mode) {}
    public function exchange(array $request): array {
        // Mock game-owned durable idempotency receipt, independent of bot journal.
        file_put_contents($this->directory.'/deliveries', json_encode($request)."\n", FILE_APPEND);
        $receipt = $this->directory.'/receipt';
        if (!is_file($receipt)) {
            file_put_contents($receipt, json_encode($request));
            file_put_contents($this->directory.'/settlements', "settled\n", FILE_APPEND);
        } elseif (json_decode(file_get_contents($receipt), true) !== $request) {
            throw new RuntimeException('Mock game identity mismatch');
        }
        if ($this->mode === 'crash') {
            exit(23); // Game committed; bot never records the reply.
        }
        usleep(150000);
        return array_merge($request, ['status' => 'committed']);
    }
};
$response = (new Client($transport, $directory.'/journal', true))->request('concurrent', '123', 'trade.confirm', ['confirmation_token' => 'game-token'], 4);
if ($response['status'] !== 'committed') { exit(2); }
echo "committed\n";
