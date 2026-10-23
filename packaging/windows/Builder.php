<?php
declare(strict_types=1);
namespace Civ13\Packaging;

/** Public-code staging only. Does not bootstrap the bot or load an environment file. */
final class Builder
{
    public const TOOL_REFERENCE = 'c26b7def6578e5a35d4a99dfbb5f4c552bf4f42b';
    public const RUNTIME_REFERENCE = '0c75828a74a42068581520a10694ce9a425465e3';
    public const RUNTIME_BLOB = '4ea718fd466f56199601f21cc1560f9bbefac857';
    public const RUNTIME_SIZE = 9632865;
    public const TOOL_FILES = [
        'src/Support/AssetManager.php'=>'d6a983ac27d4077c82c3789b5584f3cd80fa9078',
        'src/Support/Combine.php'=>'74ca16f16f1e1c1ac4eabc16c62704ed4865bde9',
        'src/Support/Config/ConfigRepository.php'=>'a3e592d75a4df8f4f06114ad0d2eef6c9cb2ec32',
        'src/constants.php'=>'d2a8602e6bd589f35958eb85a146f745dc842dce',
    ];

    public static function allowed(string $path): bool
    {
        if (str_contains($path, '\\') || preg_match('~(^|/)(\.{1,2}|\.git|\.env[^/]*|[^/]+\.env|auth\.json|token\.php|output\.log)(/|$)~i', $path)) { return false; }
        return in_array($path, ['bot.php','variable_functions.php','composer.json','composer.lock','package-manifest.json','packaging/windows/entry.php'], true)
            || (str_starts_with($path, 'src/') && str_ends_with($path, '.php'))
            || str_starts_with($path, 'vendor/');
    }

    public static function command(array $argv, string $cwd): string
    {
        $pipes = [];
        $process = proc_open($argv, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $cwd, null, ['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new \RuntimeException('Build subprocess unavailable'); }
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $errors = ''; $start = hrtime(true);
        try {
            do {
                $output .= stream_get_contents($pipes[1]); $errors .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (strlen($output)+strlen($errors)>4194304 || (hrtime(true)-$start)/1e9>60) {
                    proc_terminate($process); throw new \RuntimeException('Build subprocess exceeded its bound');
                }
                if ($status['running']) { usleep(10000); }
            } while ($status['running']);
            $output .= stream_get_contents($pipes[1]);
        } finally { fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
        if ($status['exitcode'] !== 0) { throw new \RuntimeException('Build subprocess failed: '.$argv[0]); }
        return $output;
    }

    public static function prepare(string $root): void
    {
        foreach(['COMPOSER','COMPOSER_VENDOR_DIR','COMPOSER_BIN_DIR']as$name){
            if(getenv($name)!==false&&getenv($name)!==''){throw new \RuntimeException('Use a dedicated build shell without Composer file/install-path overrides; existing settings retained');}
        }
        if (trim(self::command(['git','status','--porcelain=v1','--untracked-files=all'], $root)) !== '') {
            throw new \RuntimeException('Build from a clean committed feature/main checkout; local changes are retained');
        }
        $commit = trim(self::command(['git','rev-parse','HEAD'], $root));
        foreach (explode("\n", self::command(['git','ls-files','-v'], $root)) as $line) {
            if (preg_match('/^[a-z]|^S /', $line)) { throw new \RuntimeException('Hidden index state is unsupported; local state retained'); }
        }
        $composer = json_decode(file_get_contents($root.'/composer.json'), true, 64, JSON_THROW_ON_ERROR);
        if(($composer['config']['vendor-dir']??'vendor')!=='vendor'||($composer['config']['bin-dir']??'vendor/bin')!=='vendor/bin'){throw new \RuntimeException('Review custom Composer install paths in a dedicated build checkout');}
        foreach ($composer['repositories'] ?? [] as $repository) {
            if (is_array($repository) && ($repository['type'] ?? null) === 'path') { throw new \RuntimeException('Review path repositories in a dedicated reproducible build checkout; manifest retained'); }
        }
        $stage = $root.'/build/phpacker/app';
        self::directory($root.'/build/phpacker', $root);
        if (file_exists($stage)) {
            if (!is_file($stage.'/.civilizationbot-build-stage')) { throw new \RuntimeException('Unowned build stage retained; choose a clean feature checkout'); }
            self::retainStage($stage, $root.'/build/phpacker');
        }
        mkdir($stage, 0700, true);
        if(file_put_contents($stage.'/.civilizationbot-build-stage', "public-code-stage-v1\n")!==21){throw new \RuntimeException('Cannot mark generated build stage');}
        $files = explode("\0", self::command(['git','ls-files','-z'], $root));
        $hashes = [];
        foreach ($files as $path) {
            if ($path === '' || !self::allowed($path) || str_starts_with($path, 'vendor/')) { continue; }
            $source = $root.'/'.$path;
            self::regular($source, $root);
            if (!is_dir(dirname($stage.'/'.$path))) { mkdir(dirname($stage.'/'.$path), 0700, true); }
            if (!copy($source, $stage.'/'.$path)) { throw new \RuntimeException('Cannot stage public application code'); }
            $hashes[$path] = hash_file('sha256', $source);
        }
        self::writeReceipt($stage.'/package-manifest.json', [
            'schema'=>'civilizationbot.executable.v1', 'source_commit'=>$commit,
            'composer_lock_sha256'=>hash_file('sha256', $root.'/composer.lock'),
            'phpacker_reference'=>self::TOOL_REFERENCE,
            'files_sha256'=>$hashes,
        ]);
    }

    private static function regular(string $file, string $root): void
    {
        $relative = substr($file, strlen($root)+1); $current = $root;
        foreach (explode('/', str_replace('\\','/',$relative)) as $part) {
            $current .= '/'.$part;
            if (is_link($current)) { throw new \RuntimeException('Build input symlinks are unsupported'); }
        }
        if (!is_file($file)) { throw new \RuntimeException('Build input is not a regular file'); }
    }

    private static function retainStage(string $stage, string $parent): void
    {
        $resolved = realpath($stage); $base = realpath($parent);
        if ($resolved === false || $base === false || dirname($resolved) !== $base || is_link($stage)) {
            throw new \RuntimeException('Build stage retention boundary refused');
        }
        // Preserve prior generated/local files; no recursive deletion during a build.
        if (!rename($resolved, $base.'/previous-app-'.bin2hex(random_bytes(6)))) { throw new \RuntimeException('Cannot retain prior build stage'); }
    }

    private static function directory(string $path, string $root): void
    {
        $relative=substr($path,strlen($root)+1);$current=$root;
        foreach(explode('/',$relative) as $part){
            if($part===''||$part==='.'||$part==='..'){throw new \RuntimeException('Build directory boundary refused');}
            $current.='/'.$part;
            if(is_link($current)){throw new \RuntimeException('Build output symlinks are unsupported');}
            if(!is_dir($current)&&!mkdir($current,0700)){throw new \RuntimeException('Build directory unavailable');}
        }
    }

    public static function archive(string $stage, string $destination, ?array $sourceFiles = null): void
    {
        if (file_exists($destination)) { throw new \RuntimeException('Use a fresh PHAR destination'); }
        $phar = new \Phar($destination, 0, 'civilizationbot.phar');
        $phar->startBuffering();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\','/', substr($file->getPathname(), strlen($stage)+1));
            if (!self::allowed($path)) { continue; }
            if ($sourceFiles !== null && !str_starts_with($path,'vendor/') && $path !== 'package-manifest.json' && !in_array($path,$sourceFiles,true)) { continue; }
            self::regular($file->getPathname(), $stage);
            $phar->addFile($file->getPathname(), $path);
        }
        // PHP closes the backing stream at zero archive references, before executing a member.
        // Keep micro's offset-aware self stream: a canonical-path reopen can miss its exact-path hook.
        $phar->setStub("<?php Phar::mapPhar('civilizationbot.phar'); "
            ."\$GLOBALS['__civilizationbotExecutableArchive'] = new Phar(__FILE__); "
            ."require 'phar://'.__FILE__.'/packaging/windows/entry.php'; __HALT_COMPILER();");
        $phar->setSignatureAlgorithm(\Phar::SHA256);
        $phar->stopBuffering();
    }

    public static function verifyRuntime(string $bytes): void
    {
        if (strlen($bytes) !== self::RUNTIME_SIZE || sha1('blob '.strlen($bytes)."\0".$bytes) !== self::RUNTIME_BLOB) {
            throw new \RuntimeException('Pinned PHPacker Windows runtime Git blob mismatch; no fallback or latest download is allowed');
        }
    }

    public static function verifySourceFiles(string $root, string $stage, array $hashes): void
    {
        foreach ($hashes as $path=>$sha256) {
            if (!self::allowed($path) || str_starts_with($path,'vendor/')) { throw new \RuntimeException('Invalid prepared source inventory'); }
            self::regular($root.'/'.$path,$root); self::regular($stage.'/'.$path,$stage);
            if (hash_file('sha256',$root.'/'.$path)!==$sha256 || hash_file('sha256',$stage.'/'.$path)!==$sha256) {
                throw new \RuntimeException('Staged source differs from the committed input; prepare a fresh build');
            }
        }
    }

    private static function productionPackages(string $stage): array
    {
        $lock=json_decode(file_get_contents($stage.'/composer.lock'),true,128,JSON_THROW_ON_ERROR);
        $installed=json_decode(file_get_contents($stage.'/vendor/composer/installed.json'),true,128,JSON_THROW_ON_ERROR);
        if(($installed['dev']??null)!==false){throw new \RuntimeException('Production stage contains development dependencies');}
        $project=static function(array $packages):array{
            $result=[];
            foreach($packages as$package){
                $reference=$package['source']['reference']??$package['dist']['reference']??null;
                if(!is_string($reference)||$reference===''){throw new \RuntimeException('Dependency has no exact reviewed reference');}
                $result[$package['name']]=['version'=>$package['version'],'reference'=>$reference];
            }
            ksort($result);return$result;
        };
        $expected=$project($lock['packages']);
        if($project($installed['packages'])!==$expected){throw new \RuntimeException('Production dependencies differ from the reviewed lock');}
        return$expected;
    }

    public static function writeReceipt(string $path, array $receipt): void
    {
        if(is_link($path)){throw new \RuntimeException('Build receipt symlink refused');}
        $bytes=json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
        if(@file_put_contents($path,$bytes)!==strlen($bytes)||@file_get_contents($path)!==$bytes){throw new \RuntimeException('Cannot write and verify build receipt; build is incomplete');}
    }

    public static function vendorHashes(string $stage): array
    {
        $hashes=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage.'/vendor',\FilesystemIterator::SKIP_DOTS))as$file){
            $path=str_replace('\\','/',substr($file->getPathname(),strlen($stage)+1));
            if(!self::allowed($path)){continue;}
            self::regular($file->getPathname(),$stage);$hashes[$path]=hash_file('sha256',$file->getPathname());
        }
        ksort($hashes);return$hashes;
    }

    public static function verifyVendor(string $stage, array $hashes): void
    {
        ksort($hashes);
        if(self::vendorHashes($stage)!==$hashes){throw new \RuntimeException('Production dependency files changed after installation; prepare a fresh build');}
    }

    /** Snapshot the just-installed locked production tree before the separate build process. */
    public static function seal(string $root): void
    {
        $stage=$root.'/build/phpacker/app';
        $manifest=json_decode(file_get_contents($stage.'/package-manifest.json'),true,16,JSON_THROW_ON_ERROR);
        self::verifySourceFiles($root,$stage,$manifest['files_sha256']);
        self::writeReceipt($root.'/build/phpacker/vendor-seal.json',[
            'source_commit'=>$manifest['source_commit'],'composer_lock_sha256'=>$manifest['composer_lock_sha256'],
            'production_packages'=>self::productionPackages($stage),'files_sha256'=>self::vendorHashes($stage),
        ]);
    }

    public static function build(string $root): void
    {
        $installed = json_decode(file_get_contents($root.'/vendor/composer/installed.json'), true, 128, JSON_THROW_ON_ERROR);
        $tool = array_values(array_filter($installed['packages'] ?? [], static fn ($package) => $package['name'] === 'phpacker/phpacker'));
        if (count($tool) !== 1 || $tool[0]['version'] !== '0.6.4' || ($tool[0]['source']['reference'] ?? null) !== self::TOOL_REFERENCE) {
            throw new \RuntimeException('Install the committed development lock: reviewed PHPacker 0.6.4 source is required');
        }
        foreach(self::TOOL_FILES as$path=>$blob){
            $file=$root.'/vendor/phpacker/phpacker/'.$path;self::regular($file,$root);
            $bytes=file_get_contents($file);
            if($bytes===false||sha1('blob '.strlen($bytes)."\0".$bytes)!==$blob){throw new \RuntimeException('Installed PHPacker source differs from its reviewed Git blob');}
        }
        $stage = $root.'/build/phpacker/app';
        if(trim(self::command(['git','status','--porcelain=v1','--untracked-files=all'],$root))!==''){throw new \RuntimeException('Source changed after preparation; local changes retained');}
        $manifest = json_decode(file_get_contents($stage.'/package-manifest.json'), true, 16, JSON_THROW_ON_ERROR);
        $config=json_decode(file_get_contents($root.'/phpacker.json'),true,16,JSON_THROW_ON_ERROR);
        if($config!==['src'=>'build/phpacker/app.phar','dest'=>'bin/build/bot','ini'=>false,'platform'=>'windows','php'=>'8.4']){throw new \RuntimeException('Review changes to the pinned Windows PHPacker configuration before building');}
        if ($manifest['source_commit'] !== trim(self::command(['git','rev-parse','HEAD'], $root))
            || $manifest['composer_lock_sha256'] !== hash_file('sha256',$stage.'/composer.lock')) { throw new \RuntimeException('Prepared source changed; run composer phpacker again'); }
        $expectedFiles=[];
        foreach(explode("\0",self::command(['git','ls-files','-z'],$root))as$path){if($path!==''&&self::allowed($path)&&!str_starts_with($path,'vendor/')){$expectedFiles[]=$path;}}
        $preparedFiles=array_keys($manifest['files_sha256']??[]);sort($preparedFiles);sort($expectedFiles);
        if($preparedFiles!==$expectedFiles){throw new \RuntimeException('Prepared source inventory changed');}
        self::verifySourceFiles($root,$stage,$manifest['files_sha256']);
        $manifest['production_packages']=self::productionPackages($stage);
        $seal=json_decode(file_get_contents($root.'/build/phpacker/vendor-seal.json'),true,16,JSON_THROW_ON_ERROR);
        if($seal['source_commit']!==$manifest['source_commit']||$seal['composer_lock_sha256']!==$manifest['composer_lock_sha256']||$seal['production_packages']!==$manifest['production_packages']){throw new \RuntimeException('Installed dependency seal belongs to another source/lock');}
        self::verifyVendor($stage,$seal['files_sha256']);
        $manifest['vendor_files_sha256']=$seal['files_sha256'];
        self::writeReceipt($stage.'/package-manifest.json',$manifest);
        $run = $root.'/build/phpacker/run-'.bin2hex(random_bytes(6)); self::directory($run,$root);
        $phar = $root.'/'.$config['src'];
        if(is_link($phar)){throw new \RuntimeException('Build PHAR symlink refused');}
        if(is_file($phar)&&!rename($phar,$run.'/previous-app.phar')){throw new \RuntimeException('Cannot retain previous PHAR');}
        self::archive($stage,$phar,$expectedFiles);
        $url = 'https://raw.githubusercontent.com/phpacker/php-bin/'.self::RUNTIME_REFERENCE.'/bin/win/x64/php-8.4.zip';
        $context = stream_context_create(['http'=>['timeout'=>60,'follow_location'=>0,'header'=>"User-Agent: Civilizationbot-build\r\n"]]);
        $stream = fopen($url,'rb',false,$context);
        if (!$stream) { throw new \RuntimeException('Pinned runtime download unavailable'); }
        try { $zipBytes = stream_get_contents($stream,self::RUNTIME_SIZE+1); } finally { fclose($stream); }
        if ($zipBytes === false) { throw new \RuntimeException('Pinned runtime download failed'); }
        self::verifyRuntime($zipBytes);
        $zipPath=$run.'/runtime.zip';
        if(file_put_contents($zipPath,$zipBytes)!==strlen($zipBytes)||hash_file('sha256',$zipPath)!==hash('sha256',$zipBytes)){throw new \RuntimeException('Cannot stage verified runtime archive');}
        $zip=new \ZipArchive();if($zip->open($zipPath)!==true){throw new \RuntimeException('Pinned runtime archive cannot be opened');}
        $candidates=[];
        for($i=0;$i<$zip->numFiles;$i++){if(basename($zip->getNameIndex($i))==='micro.sfx'){$candidates[]=$i;}}
        if(count($candidates)!==1){$zip->close();throw new \RuntimeException('Expected exactly one pinned micro.sfx');}
        $stat=$zip->statIndex($candidates[0]);
        if($stat['size']>100*1024*1024){$zip->close();throw new \RuntimeException('Runtime size exceeds build bound');}
        $micro=$zip->getFromIndex($candidates[0]);$zip->close();
        if(!is_string($micro)||!str_starts_with($micro,'MZ')){throw new \RuntimeException('Expected Windows PE runtime');}
        // PHPacker's CLI loads cwd .env and downloads latest. Its pinned Combine API does neither.
        putenv('LOCALAPPDATA='.$run.'/tool-cache');putenv('XDG_DATA_HOME='.$run.'/tool-cache');
        require $root.'/vendor/autoload.php';
        $cache=APP_DATA.'/binaries/default/win/x64/8.4';mkdir($cache,0700,true);file_put_contents($cache.'/micro.sfx',$micro);
        $output=$run.'/output';
        $settings=new \PHPacker\PHPacker\Support\Config\ConfigRepository([
            'php'=>'8.4','repository'=>'phpacker/php-bin','src'=>$phar,'dest'=>$output,'ini'=>[],
        ]);
        \PHPacker\PHPacker\Support\Combine::build('windows','x64',$settings);
        $exe=$output.'/windows/windows-x64.exe';
        $expected=hash_init('sha256');hash_update($expected,$micro);$pharStream=fopen($phar,'rb');
        try{hash_update_stream($expected,$pharStream);}finally{fclose($pharStream);}
        if(filesize($exe)!==strlen($micro)+filesize($phar)||hash_file('sha256',$exe)!==hash_final($expected)){throw new \RuntimeException('PHPacker output does not match verified runtime plus PHAR');}
        $destination=$root.'/'.$config['dest'].'/windows';self::directory($destination,$root);
        foreach(['windows-x64.exe','source.json']as$name){if(is_link($destination.'/'.$name)){throw new \RuntimeException('Build artifact symlink refused');}}
        $receipt=$manifest+[
            'runtime_repository'=>'phpacker/php-bin','runtime_commit'=>self::RUNTIME_REFERENCE,'runtime_git_blob_sha1'=>self::RUNTIME_BLOB,
            'runtime_archive_sha256'=>hash('sha256',$zipBytes),'runtime_sfx_sha256'=>hash('sha256',$micro),
            'phar_sha256'=>hash_file('sha256',$phar),'exe_sha256'=>hash_file('sha256',$exe),
        ];
        self::writeReceipt($output.'/windows/source.json',$receipt);
        if(!copy($exe,$destination.'/windows-x64.exe')||hash_file('sha256',$destination.'/windows-x64.exe')!==hash_file('sha256',$exe)){throw new \RuntimeException('Cannot publish verified build output');}
        self::writeReceipt($destination.'/source.json',$receipt);
        echo "Built bin/build/bot/windows/windows-x64.exe; configuration, credentials and state are external.\n";
    }
}
