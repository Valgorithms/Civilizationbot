<?php
declare(strict_types=1);
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../src/Civ13/Civ13.php';
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use function React\Promise\reject;
final class OfflineChannel extends \Discord\Parts\Channel\Channel
{
    public bool $fail = false;
    public array $builders = [];
    public function sendMessage($message, bool $tts = false, $embed = null, $allowed_mentions = null, ?\Discord\Parts\Channel\Message $replyTo = null): PromiseInterface
    { $this->builders[]=$message; return $this->fail ? reject(new RuntimeException('synthetic Discord failure')) : resolve(null); }
}
final class OfflineCiv extends \Civ13\Civ13
{
    public function createEmbed(?bool $footer = true, int $color = 0xE1452D): \Discord\Parts\Embed\Embed
    {
        $discord=(new ReflectionClass(\Discord\Discord::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($discord,'collectionClass'))->setValue($discord,\Discord\Helpers\Collection::class);
        $embed=(new ReflectionClass(\Discord\Parts\Embed\Embed::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($embed,'discord'))->setValue($embed,$discord);
        return $embed;
    }
}
$civ=(new ReflectionClass(OfflineCiv::class))->newInstanceWithoutConstructor();
$channel=(new ReflectionClass(OfflineChannel::class))->newInstanceWithoutConstructor();
$logged=0; $civ->onFulfilledDefault=fn()=>null; $civ->onRejectedDefault=function()use(&$logged){$logged++;};
foreach(['short',str_repeat('e',3000),str_repeat('f',9000)] as $content){
    $failed=false; $channel->fail=true;
    $civ->sendMessage($channel,$content,'relay.txt',true)->then(null,function()use(&$failed){$failed=true;});
    if(!$failed){throw new RuntimeException('Actual sendMessage swallowed failed transport');}
    $wire=json_decode(json_encode(end($channel->builders),JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR);
    if(($wire['allowed_mentions']['parse']??null)!==[]){throw new RuntimeException('Relay permits mentions');}
    $channel->fail=false; $passed=false;
    $civ->sendMessage($channel,$content,'relay.txt',true)->then(function()use(&$passed){$passed=true;});
    if(!$passed){throw new RuntimeException('Actual sendMessage success lost');}
}
if($logged!==3){throw new RuntimeException('Rejected send diagnostics lost');}
echo "PASS actual sendMessage content/embed/attachment transport failures remain rejected, successes and mention isolation\n";
