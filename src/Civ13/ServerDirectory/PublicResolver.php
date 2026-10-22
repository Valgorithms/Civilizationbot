<?php

declare(strict_types=1);

namespace Civ13\ServerDirectory;

use React\Dns\Model\Message;
use React\Dns\Resolver\ResolverInterface;

use function React\Promise\reject;

/** Validate all A answers before selecting one; TCP consumes that exact IP. */
final class PublicResolver implements ResolverInterface
{
    public function __construct(private ResolverInterface $resolver)
    {
    }

    public function resolve($domain)
    {
        return $this->resolveAll($domain, Message::TYPE_A)->then(static function (array $addresses): string {
            sort($addresses, SORT_STRING);
            return $addresses[0];
        });
    }

    public function resolveAll($domain, $type)
    {
        if (!in_array($domain, Directory::PROVIDERS, true) || $type !== Message::TYPE_A) {
            return reject(new \UnexpectedValueException('Public game probes require approved hostnames and IPv4 A records'));
        }
        return $this->resolver->resolveAll($domain, $type)->then(static function ($addresses): array {
            if (!is_array($addresses) || !array_is_list($addresses) || !$addresses || count($addresses) > 32) {
                throw new \UnexpectedValueException('Invalid public DNS answer count');
            }
            foreach ($addresses as $address) {
                self::validate($address);
            }
            return $addresses;
        });
    }

    private static function validate(mixed $address): void
    {
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) === false) {
            throw new \UnexpectedValueException('Non-global IPv4 DNS answer rejected');
        }
        // PHP's global-range filter omits multicast and allows some special
        // assignments. Match the launcher's conservative special-purpose denylist.
        $bytes = array_map('intval', explode('.', $address));
        if ($bytes[0] >= 224 || ($bytes[0] === 192 && (
            ($bytes[1] === 0 && $bytes[2] === 0) ||
            ($bytes[1] === 31 && $bytes[2] === 196) ||
            ($bytes[1] === 52 && $bytes[2] === 193) ||
            ($bytes[1] === 88 && $bytes[2] === 99) ||
            ($bytes[1] === 175 && $bytes[2] === 48)
        ))) {
            throw new \UnexpectedValueException('Special-purpose IPv4 DNS answer rejected');
        }
    }
}
