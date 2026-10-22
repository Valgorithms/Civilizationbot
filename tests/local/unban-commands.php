<?php
declare(strict_types=1);
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
final class UnbanCommandCiv extends \Civ13\Civ13 {
    public Deferred $operation;
    public array $requests=[];
    public array $replies=[];
    public function unban(string $ckey,?string $admin=null,string|array|null $gameserver=null):PromiseInterface {
        $this->requests[]=[$ckey,$admin,$gameserver];return $this->operation->promise();
    }
    public function reply(\Discord\Parts\Channel\Message|\Discord\Parts\Thread\Thread $message,string $content,string $file_name='message.txt',bool $prevent_mentions=false):PromiseInterface {
        $this->replies[]=$content;return resolve(null);
    }
}
final class UnbanCommandVerifier extends \Civ13\Verifier {
    public function getVerifiedItem(\Discord\Parts\User\Member|\Discord\Parts\User\User|array|string $input):?array {return ['ss13'=>is_string($input)?'player':'admin'];}
}
final class UnbanCommandMember extends \Discord\Parts\User\Member {
    public function __get(string $name){return new class {public function has($id){return true;}};}
}
final class UnbanCommandMessage extends \Discord\Parts\Channel\Message {
    public function __get(string $name){return (new ReflectionClass($name==='author'?\Discord\Parts\User\User::class:UnbanCommandMember::class))->newInstanceWithoutConstructor();}
}
$civ=(new ReflectionClass(UnbanCommandCiv::class))->newInstanceWithoutConstructor();
$civ->verifier=(new ReflectionClass(UnbanCommandVerifier::class))->newInstanceWithoutConstructor();
$civ->ages=['player'=>true];$civ->role_ids=['Ambassador'=>'ambassador'];
$server=(new ReflectionClass(\Civ13\GameServer::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($server,'key'))->setValue($server,'fixture');(new ReflectionProperty($server,'name'))->setValue($server,'Fixture');
$message=(new ReflectionClass(UnbanCommandMessage::class))->newInstanceWithoutConstructor();
foreach([new \Civ13\MessageCommand\Commands\UnBan($civ),new \Civ13\MessageCommand\Commands\Civ13GameServerUnBan($civ,$server)] as $command){
    foreach([false,true] as $failure){
        $civ->operation=new Deferred();$civ->replies=[];$civ->requests=[];$settled=false;
        $command($message,'unban',['message_content_lower'=>'unban player'])->then(function()use(&$settled){$settled=true;});
        if($settled||$civ->replies!==[]||$civ->requests[0][0]!=='player'){throw new RuntimeException('Unban command acknowledged unsettled operation');}
        $failure?$civ->operation->reject(new RuntimeException('synthetic destination denied')):$civ->operation->resolve(null);
        if(!$settled||count($civ->replies)!==1||!str_contains($civ->replies[0],$failure?'Unban failed':'queued an unban')){throw new RuntimeException('Actual command lost terminal queue outcome');}
    }
}
echo "PASS actual unban commands: no early acknowledgement, deferred success, destination rejection\n";
