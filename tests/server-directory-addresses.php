<?php
declare(strict_types=1);

// Offline only: production factory, injected DNS/TCP and loop timers, no bot or sockets.
require $argv[1] ?? __DIR__.'/../vendor/autoload.php';
use Civ13\ServerDirectory\ByondStatusProbe;
use Evenement\EventEmitter;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;
use function React\Promise\resolve;

function addressCheck(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
final class AddressResolver implements ResolverInterface {
    public int $calls = 0;
    public function __construct(public array $answers, private StreamSelectLoop $loop, private float $delay = 0) {}
    public function resolve($domain) { throw new RuntimeException('Second DNS lookup forbidden'); }
    public function resolveAll($domain, $type) {
        $this->calls++;
        $deferred = new Deferred();
        $this->loop->addTimer($this->delay, fn () => $deferred->resolve($this->answers));
        return $deferred->promise();
    }
}
final class AddressConnection extends EventEmitter implements ConnectionInterface {
    public bool $closed = false;
    public function __construct(private StreamSelectLoop $loop, private string $kind, private AddressTcp $owner) {}
    public function isReadable() { return !$this->closed; }
    public function isWritable() { return !$this->closed; }
    public function pause() {}
    public function resume() {}
    public function pipe(WritableStreamInterface $dest, array $options = []) { return Util::pipe($this, $dest, $options); }
    public function write($data) {
        addressCheck($data === "\0\x83".pack('n', strlen("?status=1&format=json\0") + 5).str_repeat("\0",5)."?status=1&format=json\0", 'Non-fixed status query');
        if ($this->kind === 'silent') { return true; }
        $json = $this->kind === 'incomplete' ? '{}' : '{"players":0,"map_name":"Europe","map_gamemode":"Nomads","round_duration_in_ticks":0}';
        $body = "\x06".$json."\0";
        $wire = $this->kind === 'malformed' ? 'HTTP/1.1 200 OK' : "\0\x83".pack('n',strlen($body)).$body;
        $this->loop->futureTick(function () use ($wire): void { if (!$this->closed) { $this->emit('data',[$wire]); } });
        return true;
    }
    public function getRemoteAddress() { return 'tcp://93.184.215.14:24017'; }
    public function getLocalAddress() { return 'tcp://127.0.0.1:1'; }
    public function end($data = null) { $this->close(); }
    public function close() { if (!$this->closed) { $this->closed=true; $this->owner->active--; $this->emit('close'); $this->removeAllListeners(); } }
}
final class AddressTcp implements ConnectorInterface {
    public array $uris=[];
    public int $active=0;
    public int $peak=0;
    public function __construct(private StreamSelectLoop $loop, public array $kinds=[]) {}
    public function connect($uri) {
        $host=parse_url($uri,PHP_URL_HOST);
        addressCheck(filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false,'TCP received hostname');
        $this->uris[]=$uri; $this->active++; $this->peak=max($this->peak,$this->active);
        return resolve(new AddressConnection($this->loop,$this->kinds[$host]??'valid',$this));
    }
}
function runAddressProbe(array $answers, array $kinds=[], float $dnsDelay=0, float $budget=.08): array {
    $loop=new StreamSelectLoop(); $resolver=new AddressResolver($answers,$loop,$dnsDelay); $tcp=new AddressTcp($loop,$kinds);
    $probe=ByondStatusProbe::create($loop,$resolver,$tcp,timeoutSeconds:$budget);
    $value=null; $error=null; $start=microtime(true);
    $probe('valzargaming.com',1714,true)->then(static function($v)use(&$value){$value=$v;},static function($e)use(&$error){$error=$e;});
    $loop->run();
    addressCheck($tcp->active===0,'Socket retained after settlement');
    return [$value,$error,$resolver,$tcp,microtime(true)-$start];
}
foreach ([['72.218.249.221','192.64.119.81'],['192.64.119.81','72.218.249.221']] as $answers) {
    foreach (['silent','malformed','incomplete'] as $failure) {
        [$value,$error,$resolver,$tcp]=runAddressProbe($answers,['192.64.119.81'=>$failure]);
        addressCheck($error===null && $value['players']===0,'Healthy later public address lost');
        addressCheck(count($tcp->uris)===2 && $tcp->peak===1 && $resolver->calls===1,'Fanout, retries, or DNS re-resolution');
        addressCheck(parse_url($tcp->uris[1],PHP_URL_HOST)==='72.218.249.221' && parse_url($tcp->uris[1],PHP_URL_PORT)===1714,'Wrong selected literal/port');
    }
}
foreach ([[],array_fill(0,33,'72.218.249.221'),['72.218.249.221','127.0.0.1'],['10.0.0.1','72.218.249.221'],['192.0.2.1','72.218.249.221']] as $answers) {
    [$value,$error,$resolver,$tcp]=runAddressProbe($answers);
    addressCheck($value===null && $error!==null && $tcp->uris===[],'Unsafe/full answer set reached TCP');
}
[$value,$error,$resolver,$tcp]=runAddressProbe(['72.218.249.221','72.218.249.221']);
addressCheck($error===null && count($tcp->uris)===1,'Duplicate addresses retried');
[$value,$error,$resolver,$tcp,$elapsed]=runAddressProbe(['72.218.249.221','192.64.119.81'],['72.218.249.221'=>'silent','192.64.119.81'=>'silent']);
addressCheck($error!==null && $value===null && count($tcp->uris)===2 && $elapsed<.5,'Shared total deadline or all-failure cleanup');
[$value,$error,$resolver,$tcp,$elapsed]=runAddressProbe(['72.218.249.221'],[],.12,.04);
addressCheck($error!==null && $tcp->uris===[],'Late DNS completion opened socket after deadline');

$loop=new StreamSelectLoop(); $resolver=new AddressResolver(['72.218.249.221','192.64.119.81'],$loop);
$tcp=new AddressTcp($loop,['192.64.119.81'=>'silent']); $probe=ByondStatusProbe::create($loop,$resolver,$tcp,timeoutSeconds:.08);
$success=0; for($i=0;$i<32;$i++) { $probe('valzargaming.com',1024+$i,true)->then(static function()use(&$success){$success++;}); }
$loop->run(); addressCheck($success===32 && $tcp->peak===32 && $tcp->active===0,'32-target sweep exceeded global socket bound or lost peers');

$loop=new StreamSelectLoop(); $resolver=new AddressResolver(['72.218.249.221'],$loop); $tcp=new AddressTcp($loop,['72.218.249.221'=>'silent']);
$probe=ByondStatusProbe::create($loop,$resolver,$tcp,timeoutSeconds:.08); $cancelled=false;
$pending=$probe('valzargaming.com',1714,true); $pending->then(null,static function()use(&$cancelled){$cancelled=true;});
$loop->addTimer(.005,static fn()=>$pending->cancel()); $loop->run();
addressCheck($cancelled && $tcp->active===0,'Cancellation retained an active socket');
echo "PASS public address selection: both DNS orders, silent/malformed/incomplete fallback, whole-set rejection before TCP, duplicate suppression, one DNS snapshot, literal/port binding, shared deadline, late callbacks, cancellation and 32-global-socket ceiling.\n";
