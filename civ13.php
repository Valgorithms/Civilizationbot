<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

class Civ13
{
    public $loop;
    public $discord;
    public $browser; //unused
    public $filesystem;
    
    protected $webapi;
    
    protected $verbose = true;
    
    public $timers = [];
    
    public $functions = array(
        'ready' => [],
        'messages' => [],
        'misc' => [],
    );
    
    public $command_symbol = '!s';
    public $owner_id = '196253985072611328';
    public $civ13_guild_id = '468979034571931648';
    
    public $files = [];
    public $ips = [];
    public $ports = [];
    public $channel_ids = [];
    public $role_ids = [];
    
    /**
     * Creates a Civ13 client instance.
     *
     * @param  array           $options Array of options.
     * @throws IntentException
     */
    public function __construct(array $options = [])
    {
        $options = $this->resolveOptions($options);
		
		$this->loop = $options['loop'];
		$this->browser = $options['browser'];
        $this->filesystem = $options['filesystem'];
        
        if(isset($options['command_symbol'])) {
            $this->command_symbol = $options['command_symbol'];
        }
        if(isset($options['owner_id'])) {
            $this->owner_id = $options['owner_id'];
        }
        if(isset($options['civ13_guild_id'])) {
            $this->civ13_guild_id = $options['civ13_guild_id'];
        }
        
        if (isset($options['discord']) || isset($options['discord_options'])) {
			if(isset($options['discord'])) $this->discord = $options['discord'];
			elseif(isset($options['discord_options'])) $this->discord = new \Discord\Discord($options['discord_options']);
		}
        
        if(isset($options['functions'])) {
            if(isset($options['functions']['ready']))
                foreach ($options['functions']['ready'] as $key => $func)
                    $this->functions['ready'][$key] = $func;
            if(isset($options['functions']['message']))
                foreach ($options['functions']['message'] as $key => $func)
                    $this->functions['message'][$key] = $func;
            if(isset($options['functions']['misc']))
                foreach ($options['functions']['misc'] as $key => $func)
                    $this->functions['misc'][$key] = $func;
        } else $this->emit('No functions passed in options!');
        if(isset($options['files']))
            foreach ($options['files'] as $key => $path)
                $this->files[$key] = $path;
        if(isset($options['ips']) && isset($options['ports'])) {
            foreach ($options['ips'] as $key => $ip)
                $this->ips[$key] = $ip;
            foreach ($options['ports'] as $key => $port)
                $this->ports[$key] = $port;
        }
        if(isset($options['channel_ids']))
            foreach ($options['channel_ids'] as $key => $id)
                $this->channel_ids[$key] = $id;
        if(isset($options['role_ids']))
            foreach ($options['role_ids'] as $key => $id)
                $this->role_ids[$key] = $id;
        $this->afterConstruct();
    }
    
    protected function afterConstruct()
    {
        if(isset($this->discord)) {
            $this->discord->once('ready', function () {
                if(! empty($this->functions['ready']))
                    foreach ($this->functions['ready'] as $func)
                        $func($this);
                else $this->emit('No ready functions found!');
                $this->discord->on('message', function ($message)
                {
                    if(! empty($this->functions['message']))
                        foreach ($this->functions['message'] as $func)
                            $func($this, $message);
                });
            });
        }
    }
    
    /*
	* Attempt to catch errors with the user-provided $options early
	*/
	protected function resolveOptions(array $options = []): array
	{
		if ($this->verbose) $this->emit('[CIV13] [RESOLVE OPTIONS]');
		$options['loop'] = $options['loop'] ?? React\EventLoop\Factory::create();
		$options['browser'] = $options['browser'] ?? new \React\Http\Browser($options['loop']);
        $options['filesystem'] = $options['filesystem'] ?? \React\Filesystem\Factory::create($options['loop']);
		return $options;
	}
    
    public function emit(string $string): void
	{
		echo "[EMIT] $string" . PHP_EOL;
	}
    
    public function run(): void
	{
		if ($this->verbose) $this->emit('[CIV13] [RUN]');
		if(!(isset($this->discord))) $this->emit('[WARNING] Discord not set!');
		else $this->discord->run();
	}
    
    public function stop(): void
	{
		if ($this->verbose) $this->emit('[CIV13] [STOP]');
		if((isset($this->discord))) $this->discord->stop();
	}
}