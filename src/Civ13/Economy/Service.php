<?php

declare(strict_types=1);

namespace Civ13\Economy;

/** No HTTP/Discord registration. Authentication and approved policy are injected. */
final class Service
{
    public function __construct(
        private Ledger $ledger,
        private AuthoritativeAccounts $accounts,
        private Config $config = new Config(),
        private ?\Closure $authenticate = null,
        private ?\Closure $policy = null,
    ) {}

    public function read(string $requestingDiscordId): array
    {
        $this->requireEnabled();
        $account = $this->accounts->forDiscord($requestingDiscordId);
        return ['account' => $account, 'balance' => $this->ledger->balance($this->config->realm, $account), 'history' => $this->ledger->history($this->config->realm, $account)];
    }

    /** Authentication must validate this exact envelope against trusted server transport evidence. */
    public function process(array $event, mixed $authenticationContext): array
    {
        $this->requireEnabled();
        $keys = array_keys($event);
        sort($keys);
        if ($keys !== ['account', 'outcome', 'run', 'version'] || $event['version'] !== 1
            || !is_string($event['account']) || !preg_match('/\A[a-z0-9]{1,64}\z/', $event['account'])
            || !is_string($event['run']) || $event['run'] === '' || strlen($event['run']) > 128
            || !is_string($event['outcome']) || $event['outcome'] === '' || strlen($event['outcome']) > 128) {
            throw new \InvalidArgumentException('Invalid server outcome envelope.');
        }
        $server = $this->authenticate === null ? null : ($this->authenticate)($event, $authenticationContext);
        if (!is_string($server) || !in_array($server, $this->config->servers, true)) {
            throw new \RuntimeException('Authenticated allowlisted game server required.');
        }
        $this->accounts->requireAccount($event['account']);
        // The event contains no amount. A human-approved immutable policy supplies it.
        $delta = $this->policy === null ? null : ($this->policy)($event['outcome'], $this->config->policyVersion);
        if (!is_int($delta) || $delta === 0) {
            throw new \RuntimeException('No approved accounting policy for this outcome.');
        }
        // One final outcome per server/run/account. Delivery IDs and outcome type
        // cannot create additional awards or bypass death/extraction conflicts.
        $id = hash('sha256', json_encode([$server, $event['run'], $event['account']], JSON_THROW_ON_ERROR));
        $evidence = ['version' => 1, 'server' => $server, 'run' => $event['run'], 'account' => $event['account'], 'outcome' => $event['outcome'], 'policy_version' => $this->config->policyVersion];
        return $this->ledger->apply($this->config->realm, $id, $event['account'], $delta, $event['outcome'], $evidence);
    }

    private function requireEnabled(): void
    {
        if (!$this->config->enabled) {
            throw new \RuntimeException('Economy is disabled.');
        }
    }
}
