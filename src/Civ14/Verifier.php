<?php declare(strict_types=1);

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ14;

use Civ13\Civ13;
use Discord\Parts\User\Member;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
//use SS14\Endpoints\OAuth2Endpoint;
use VerifierServer\Endpoints\SS14VerifiedEndpoint;

use RuntimeException;
use Traversable;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * @property Civ13 $civ13
 * @property SS14VerifiedEndpoint $endpoint
 * 
 * @property-read LoggerInterface $logger
 */
class Verifier
{
    use DynamicPropertyAccessorTrait;

    protected $civ13;
    //protected $oauth_endpoint;
    protected $endpoint;

    public function __construct(&$civ13) {
        /** @var Civ13 $civ13 */
        $this->civ13 = &$civ13;
        if (! isset($this->civ13->verifier_server)) {
            $this->logger->error($err = "No SS14 verifier server set.");
            throw new RuntimeException($err);
        }
        /*if (!$oauth_endpoint = &$this->civ13->verifier_server->getEndpoint('/ss14wa')) {
            $this->logger->error($err = "No SS14 OAuth2 endpoint found.");
            throw new RuntimeException($err);
        }
        $this->oauth_endpoint = &$oauth_endpoint;*/
        if (!$endpoint = &$this->civ13->verifier_server->getEndpoint('/ss14verified')) {
            $this->logger->error($err = "No SS14 verified endpoint found.");
            throw new RuntimeException($err);
        }
        $this->endpoint = &$endpoint;
        $this->afterConstruct();
    }
    protected function afterConstruct(): void
    {
        $this->civ13->ss14verifier = &$this;
        $this->logger->info("Added SS14 Verifier");
        $this->civ13->discord->on('GUILD_MEMBER_ADD', fn(Member $member) => $this->joinRoles($member));
    }

    /**
     * @throws RuntimeException
     * @return PromiseInterface<User>
     */
    public function process(string $discord): PromiseInterface
    {
        return $this->getIPFromDiscord($discord)
            ->then(fn(string $requesting_ip) => $this->getSS14FromIP($requesting_ip))
            ->then(fn(string $ss14)          => $this->verify($discord, $ss14))
            ->then(function(string $ss14) use ($discord) {
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                    $channel->sendMessage("`$ss14` has been verified and registered to <@$discord> (Civ14)");
                }
                return $ss14;
            });
    }
    
    /**
     * @throws RuntimeException
     * @return PromiseInterface
     */
    public function getIPFromDiscord(string $discord): PromiseInterface
    {
        $sessions = $this->civ13->verifier_server->getSessions();
        if (!isset($sessions['dwa'])) {
            $this->logger->warning($err = "No DWA OAuth2 sessions found.");
            return reject(new RuntimeException($err));
        }
        if (!isset($sessions['ss14wa'])) {
            $this->logger->warning($err = "No SS14 OAuth2 sessions found.");
            return reject(new RuntimeException($err));
        }
        $requesting_ip = null;
        $discord_id = null;
        foreach ($sessions['dwa'] as $ip => $array) {
            if (!isset($array['user']->id)) continue;
            if ($array['user']->id == $discord) {
                $requesting_ip = $ip;
                $discord_id = $array['user']->id;
                break;
            }
        }
        if (!$discord_id || !$requesting_ip) {
            $this->logger->warning($err = "OAuth2 session not found for Discord ID `$discord`.");
            return reject(new RuntimeException($err));
        }
        /** @var string $requesting_ip */
        return resolve($requesting_ip);
    }

    /**
     * @throws RuntimeException
     * @return PromiseInterface<User>
     */
    public function getSS14FromIP(string $requesting_ip): PromiseInterface
    {
        $sessions = $this->civ13->verifier_server->getSessions();
        if (!isset($sessions['ss14wa'])) {
            $this->logger->warning($err = "No SS14 sessions found.");
            return reject(new RuntimeException($err));
        }
        if (!isset($sessions['ss14wa'][$requesting_ip]['user'], $sessions['ss14wa'][$requesting_ip]['user']->name) || !$ss14 = $sessions['ss14wa'][$requesting_ip]['user']->name) {
            $this->logger->warning(($err = "No SS14 sessions found for IP.") . " IP: `$requesting_ip`.");
            return reject(new RuntimeException($err));
        }
        /** @var string $ss14 */
        return resolve($ss14);
    }
    
    /** 
     * @throws RuntimeException User is already verified
     * @return PromiseInterface<string>
     */
    public function verify(string $discord, string $ss14): PromiseInterface
    {
        if ($this->endpoint->getIndex($discord, $ss14) !== false) {
            $this->logger->warning($err = "Discord ID `{$discord}` or SS14 name `{$ss14}` is already verified.");
            return reject(new RuntimeException($err));
        }
        $this->endpoint->add($discord, $ss14);
        $this->logger->info("Discord ID `$discord` has been SS14 verified as `$ss14`.");
        return resolve($ss14);
    }

    /** 
     * @throws RuntimeException User is not already verified
     * @return PromiseInterface<array>
     */
    public function unverify(string $discord, string $ss14): PromiseInterface
    {
        if (!$splice = $this->endpoint->remove($$discord, $ss14)) {
            $this->logger->warning($err = "Neither Discord ID `{$discord}` nor SS14 name `{$ss14}` is already verified.");
            return reject(new RuntimeException($err));
        }
        ;
        $this->logger->info("Unverified SS14: " . json_encode($splice));
        return resolve($splice);
    }

    /** @return PromiseInterface<Member>|null */
    public function joinRoles(Member $member): ?PromiseInterface
    {
        if ($member->guild_id !== $this->civ13->civ13_guild_id) return null;
        if (isset($this->civ13->softbanned[$member->id])) return null;
        return ($this->endpoint->getIndex($member->id) === false)
            ? null
            : $member->setroles([$this->civ13->role_ids['SS14 Verified']], "SS14 verified join");
    }

    public function getCiv13()
    {
        return $this->civ13;
    }

    public function getLoggerProperty(): LoggerInterface
    {
        return $this->civ13->logger;
    }

    public function toArray(): array
    {
        return $this->endpoint->getState()->getVerifyList();
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->toArray());
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}