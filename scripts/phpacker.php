<?php
declare(strict_types=1);
require __DIR__.'/../packaging/windows/Builder.php';
try {
    $root=dirname(__DIR__);
    match($argv[1]??''){
        'prepare'=>\Civ13\Packaging\Builder::prepare($root),
        'seal'=>\Civ13\Packaging\Builder::seal($root),
        'build'=>\Civ13\Packaging\Builder::build($root),
        default=>throw new InvalidArgumentException('Use composer phpacker'),
    };
}catch(Throwable $error){fwrite(STDERR,'Build refused: '.$error->getMessage().PHP_EOL);exit(1);}
