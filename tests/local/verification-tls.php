<?php
declare(strict_types=1);
// PROPOSAL ONLY: synthetic fixtures, one owned loopback listener at a time.
// Never deploy these generated test keys or trust their CA outside this child.
require $argv[1];
$repo=realpath($argv[2]);$stage=realpath($argv[3]);
if($repo===false||$stage===false||PHP_OS_FAMILY!=='Linux'||!extension_loaded('openssl')){throw new RuntimeException('Reviewed Linux/openssl fixture required');}
if(realpath((new ReflectionClass(Civ13\HostedVerificationSource::class))->getFileName())!==$repo.'/src/Civ13/HostedVerificationSource.php'){throw new RuntimeException('Source binding mismatch');}
umask(0077);
$started=microtime(true);
function tlsRequire(bool $ok,string $reason):void{if(!$ok){throw new RuntimeException($reason);}}
function tlsStore(string $path,string $data):void{tlsRequire(strlen($data)<=16384 && file_put_contents($path,$data,LOCK_EX)===strlen($data),'Bounded fixture write failed');}
function tlsProperty(object $object,string $name):mixed{return (new ReflectionProperty($object,$name))->getValue($object);}
$config=$stage.'/openssl-test.cnf';
tlsStore($config,"[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[good]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:civ13.com\n[wrong]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:wrong.invalid\n");
function tlsKey(string $config):OpenSSLAsymmetricKey{
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048,'config'=>$config]);
    tlsRequire($key instanceof OpenSSLAsymmetricKey,'Synthetic RSA fixture generation failed');return $key;
}
function tlsCertificate(string $name,OpenSSLAsymmetricKey $key,?OpenSSLCertificate $ca,OpenSSLAsymmetricKey $signer,string $extension,int $serial,string $config):OpenSSLCertificate{
    $csr=openssl_csr_new(['commonName'=>$name],$key,['config'=>$config,'digest_alg'=>'sha256']);
    tlsRequire($csr instanceof OpenSSLCertificateSigningRequest,'Synthetic CSR failed');
    $cert=openssl_csr_sign($csr,$ca,$signer,1,['config'=>$config,'digest_alg'=>'sha256','x509_extensions'=>$extension],$serial);
    tlsRequire($cert instanceof OpenSSLCertificate,'Synthetic certificate failed');return $cert;
}
$caKey=tlsKey($config);$otherKey=tlsKey($config);$leafKey=tlsKey($config);
$ca=tlsCertificate('PUBLIC SYNTHETIC TEST ROOT',$caKey,null,$caKey,'ca',1,$config);
$other=tlsCertificate('UNTRUSTED SYNTHETIC TEST ROOT',$otherKey,null,$otherKey,'ca',2,$config);
$rootPem='';tlsRequire(openssl_x509_export($ca,$rootPem),'Root serialization failed');tlsStore($stage.'/root-ca.pem',$rootPem);
$leafKeyPem='';tlsRequire(openssl_pkey_export($leafKey,$leafKeyPem,null,['config'=>$config]),'Leaf key serialization failed');tlsStore($stage.'/leaf-key.pem',$leafKeyPem);
$certs=[];$certHashes=[];
foreach([['good','good',$ca,$caKey,10],['wrong-host','wrong',$ca,$caKey,11],['untrusted','good',$other,$otherKey,12]] as [$name,$extension,$issuer,$signer,$serial]){
    $cert=tlsCertificate($extension==='wrong'?'wrong.invalid':'civ13.com',$leafKey,$issuer,$signer,$extension,$serial,$config);
    $pem='';tlsRequire(openssl_x509_export($cert,$pem),'Leaf serialization failed');
    $chain='';tlsRequire(openssl_x509_export($issuer,$chain),'Chain serialization failed');
    $path=$stage.'/'.$name.'.pem';tlsStore($path,$pem.$chain);$certs[$name]=$path;$certHashes[$name]=hash('sha256',$pem);
    $parsed=openssl_x509_parse($cert);tlsRequire(($parsed['extensions']['subjectAltName']??null)===($extension==='wrong'?'DNS:wrong.invalid':'DNS:civ13.com'),'Independent SAN oracle failed');
    $purpose=openssl_x509_checkpurpose($cert,X509_PURPOSE_SSL_SERVER,[$stage.'/root-ca.pem']);
    tlsRequire($name==='untrusted'?$purpose!==true&&$purpose!==1:$purpose===true||$purpose===1,'Independent trust-chain oracle failed');
}
tlsRequire(ini_get('openssl.cafile')===$stage.'/root-ca.pem' && ini_get('openssl.capath')===$stage.'/empty-trust','Child-only trust configuration mismatch');
final class FixtureResolver implements React\Dns\Resolver\ResolverInterface {
    public array $queries=[];
    public function resolve($domain){$this->queries[]=$domain;tlsRequire($domain==='civ13.com','Unexpected resolver name');return React\Promise\resolve('127.0.0.1');}
    public function resolveAll($domain,$type){$this->queries[]=$domain;tlsRequire($domain==='civ13.com','Unexpected resolver name');return React\Promise\resolve($type===React\Dns\Model\Message::TYPE_A?['127.0.0.1']:[]);}
}
final class FixtureConnector implements React\Socket\ConnectorInterface {
    public array $uris=[];public array $pending=[];public array $connections=[];private int $serial=0;
    public function __construct(private React\Socket\ConnectorInterface $inner,private string $expected){}
    public function connect($uri){
        tlsRequire($uri===$this->expected && count($this->uris)<2,'Unexpected client URI or attempt count');
        $this->uris[]=$uri;$id=++$this->serial;$this->pending[$id]=null;
        $original=$this->inner->connect($uri);
        $mapped=$original->then(function(React\Socket\ConnectionInterface $connection)use($id){
            unset($this->pending[$id]);$key=spl_object_id($connection);$this->connections[$key]=$connection;
            $connection->on('close',function()use($key){unset($this->connections[$key]);});return $connection;
        },function(Throwable $error)use($id){unset($this->pending[$id]);throw $error;});
        if(array_key_exists($id,$this->pending)){$this->pending[$id]=$original;}
        return $mapped;
    }
}
$cases=[['success','good',200,true,1],['wrong-hostname','wrong-host',200,false,2],['untrusted-chain','untrusted',200,false,2],['https-redirect','good',302,false,1],['http-downgrade','good',302,false,1]];
$results=[];$globalConnections=0;$activeListeners=0;
foreach($cases as [$name,$certName,$status,$expected,$expectedConnections]){
    tlsRequire(microtime(true)-$started<35,'Admission budget exhausted before case');
    $loop=new React\EventLoop\StreamSelectLoop();$owned=[];$listener=null;$tcp=null;$timer=null;$promise=null;$resolver=new FixtureResolver();
    $accepted=0;$requests=0;$serverErrors=0;$fault=null;$result=null;$done=false;$tracker=null;$primary=null;$cleanup=[];
    try{
        tlsRequire($activeListeners===0,'Listener ownership overlap');
        $tcp=new React\Socket\SocketServer('127.0.0.1:0',[], $loop);++$activeListeners;
        $address=$tcp->getAddress();tlsRequire(is_string($address)&&preg_match('~^tcp://127[.]0[.]0[.]1:([1-9][0-9]*)$~D',$address,$portMatch)===1,'Unexpected listener address');
        $port=(int)$portMatch[1];$url='https://civ13.com:'.$port.'/verified';
        $tcp->on('connection',function(React\Socket\ConnectionInterface $connection)use(&$owned,&$accepted,&$globalConnections){
            ++$accepted;++$globalConnections;tlsRequire($accepted<=2&&$globalConnections<=7,'Connection budget exceeded');
            tlsRequire(str_starts_with((string)$connection->getRemoteAddress(),'tcp://127.0.0.1:'),'Non-loopback peer');
            $id=spl_object_id($connection);$owned[$id]=$connection;
            $connection->on('close',static function()use(&$owned,$id){unset($owned[$id]);});
        });
        $listener=new React\Socket\SecureServer($tcp,$loop,['local_cert'=>$certs[$certName],'local_pk'=>$stage.'/leaf-key.pem']);
        $listener->on('error',function(Throwable $error)use(&$serverErrors){++$serverErrors;});
        $listener->on('connection',function(React\Socket\ConnectionInterface $connection)use(&$requests,$status,$name,$port){
            $input='';
            $connection->on('data',function($chunk)use(&$input,&$requests,$connection,$status,$name,$port){
                $input.=$chunk;tlsRequire(strlen($input)<=8192,'Request budget exceeded');
                if(!str_contains($input,"\r\n\r\n")){return;}
                ++$requests;tlsRequire($requests<=1&&str_starts_with($input,"GET /verified HTTP/1.1\r\n"),'Unexpected/followed request');
                tlsRequire(str_contains($input,"\r\nHost: civ13.com:".$port."\r\n"),'Host binding mismatch');
                $body='[{"ss13":"fakealpha","discord":"990000000000000001"}]';
                $headers=$status===302?'Location: '.($name==='http-downgrade'?'http':'https').'://civ13.com:'.$port."/other\r\n":'';
                $connection->end('HTTP/1.1 '.$status.($status===200?" OK\r\n":" Found\r\n").$headers."Content-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
            });
        });
        // Use the exact production TLS policy; replace only DNS with a closed fixture resolver.
        // This avoids system DNS discovery/WMIC and does not disable certificate or hostname checks.
        $options=(new ReflectionMethod(Civ13\HostedVerificationSource::class,'authenticatedConnectorOptions'))->invoke(null);
        tlsRequire($options['tls']===['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false],'Production TLS policy changed');
        $options['dns']=$resolver;
        $tracker=new FixtureConnector(new React\Socket\Connector($options,$loop),'tls://civ13.com:'.$port);
        $browser=new React\Http\Browser($tracker,$loop);
        $promise=(new ReflectionMethod(Civ13\HostedVerificationSource::class,'read'))->invoke(null,$browser,$url,true);
        $promise->then(function(array $value)use(&$result,&$done,$loop){$result=$value;$done=true;$loop->stop();},
            function(Throwable $error)use(&$fault,&$done,$loop){$fault='unexpected-rejection';$done=true;$loop->stop();});
        $timer=$loop->addTimer(5,function()use(&$fault,$loop){$fault='case-deadline';$loop->stop();});
        if(!$done){$loop->run();}
        tlsRequire($fault===null&&$done&&is_array($result),'Case did not settle successfully');
        $authority=($result['authority']??null) instanceof Civ13\HostedVerificationSource;
        tlsRequire($authority===$expected && ($result['http_status']??null)===($expected?200:0),'Independent expected admission/refusal failed');
        tlsRequire($accepted===$expectedConnections,'Unexpected retry/connection count');
        tlsRequire($requests===($certName==='good'?1:0),'TLS refusal reached HTTP or redirect followed');
        if($expected){
            $records=Civ13\HostedVerificationSource::validate($result['json']);
            tlsRequire($records===[['ss13'=>'fakealpha','discord'=>'990000000000000001']]&&$result['authority']->matches($url,$records),'Positive receipt binding failed');
        }
        $results[]=['case'=>$name,'expected_authority'=>$expected,'actual_authority'=>$authority,'connections'=>$accepted,'client_attempts'=>count($tracker->uris),'http_requests'=>$requests,'tls_server_error_count'=>$serverErrors,'resolver_names'=>array_values(array_unique($resolver->queries))];
    }catch(Throwable $error){$primary=$error;}
    finally{
        $clean=function(callable $action)use(&$cleanup){try{$action();}catch(Throwable $error){$cleanup[]=get_class($error);}};
        if($timer!==null){$clean(fn()=>$loop->cancelTimer($timer));}
        if($promise!==null){$clean(fn()=>$promise->cancel());}
        if($tracker!==null){
            foreach($tracker->pending as $pending){if($pending!==null){$clean(fn()=>$pending->cancel());}}
            foreach($tracker->connections as $connection){$clean(fn()=>$connection->close());}
        }
        foreach($owned as $connection){$clean(fn()=>$connection->close());}
        if($listener!==null){$clean(fn()=>$listener->close());}
        if($tcp!==null){$clean(fn()=>$tcp->close());--$activeListeners;if($tcp->getAddress()!==null){$cleanup[]='listener-unconfirmed';}}
        if($owned!==[]||$activeListeners!==0||($tracker!==null&&($tracker->pending!==[]||$tracker->connections!==[]))){$cleanup[]='owned-connection-unconfirmed';}
    }
    if($primary!==null||$cleanup!==[]){throw new RuntimeException('Fixture '.$name.' failed; primary='.($primary?->getMessage()??'none').'; cleanup='.implode(',',$cleanup),0,$primary);}
}
tlsRequire(count($results)===5&&$globalConnections===7&&$activeListeners===0,'Finite case/cleanup contract incomplete');
$receipt=['schema'=>'civ13.synthetic-mapping-tls.v1','cases'=>$results,'listeners_closed'=>true,'owned_connections_closed'=>true,'connections'=>$globalConnections,'source_sha256'=>hash_file('sha256',$repo.'/src/Civ13/HostedVerificationSource.php'),'test_certificate_sha256'=>$certHashes,'seconds'=>round(microtime(true)-$started,3),'real_endpoint_contacted'=>false,'trust_scope'=>'child-only synthetic CA','os_egress_containment_claimed'=>false];
$json=json_encode($receipt,JSON_THROW_ON_ERROR);tlsRequire(strlen($json)<8192,'Receipt cap');tlsStore($stage.'/tls-receipt.json',$json."\n");
echo $json,"\n";
