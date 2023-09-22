<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2023-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13\Interfaces;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response as HttpResponse;

interface HttpHandlerInterface extends HandlerInterface
{
    public function handle(ServerRequestInterface $request): HttpResponse;
}

interface HttpHandlerCallbackInterface
{
    public function __invoke(ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse;
}

namespace Civ13;

use Civ13\Interfaces\HttpHandlerCallbackInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response as HttpResponse;

class HttpHandlerCallback implements HttpHandlerCallbackInterface
{
    private $callback;

    public function __construct(callable $callback)
    {
        $reflection = new \ReflectionFunction($callback);
        $parameters = $reflection->getParameters();

        $expectedParameterTypes = [ServerRequestInterface::class, 'array', 'bool', 'string'];

        if (count($parameters) !== $count = count($expectedParameterTypes)) {
            throw new \InvalidArgumentException("The callback must take exactly $count parameters: " . implode(', ', $expectedParameterTypes));
        }

        foreach ($parameters as $index => $parameter) {
            if (! $parameter->hasType()) {
                throw new \InvalidArgumentException("Parameter $index must have a type hint.");
            }

            $type = $parameter->getType()->getName();

            if ($type !== $expectedParameterTypes[$index]) {
                throw new \InvalidArgumentException("Parameter $index must be of type {$expectedParameterTypes[$index]}.");
            }
        }

        $this->callback = $callback;
    }

    public function __invoke(ServerRequestInterface $request, array $data = [], bool $whitelisted = false, string $endpoint = ''): HttpResponse
    {
        return call_user_func($this->callback, $request, $data, $whitelisted, $endpoint);
    }
}

use Civ13\Interfaces\HttpHandlerInterface;
use Discord\Helpers\Collection;
use React\Http\Message\Response;

class HttpHandler extends Handler implements HttpHandlerInterface
{ // TODO
    public string $external_ip = '127.0.0.1';
    protected string $key = '';
    protected array $whitelist = [];

    protected array $endpoints = [];
    
    protected array $whitelisted = [];
    protected array $match_methods = [];
    protected array $descriptions = [];


    public function __construct(Civ13 &$civ13, array $handlers = [], array $whitelist = [], string $key = '')
    {
        parent::__construct($civ13, $handlers);
        if ($external_ip = file_get_contents('http://ipecho.net/plain')) $this->external_ip = $external_ip;
        foreach ($whitelist as $ip) $this->whitelist($ip);
        $this->key = $key;
    }

    public function handle(ServerRequestInterface $request): Response
    {
        $scheme = $request->getUri()->getScheme();
        $host = $request->getUri()->getHost();
        $port = $request->getUri()->getPort();
        $path = $request->getUri()->getPath();
        if ($path === '') $path = '/';
        $query = $request->getUri()->getQuery();
        $fragment = $request->getUri()->getFragment(); // Only used on the client side, ignored by the server

        //$url = "$scheme://$host:$port$path". ($query ? "?$query" : '') . ($fragment ? "#$fragment" : '');
        if (str_starts_with($path, '/webhook/')) $this->civ13->logger->debug("[WEBAPI URL] $path");
        else $this->civ13->logger->info("[WEBAPI URL] $path");
        //$this->civ13->logger->info("[WEBAPI PATH] $path");
        //$ext = pathinfo($query, PATHINFO_EXTENSION);

        $response = $this->processEndpoint($request);
        if ($response instanceof Response) return $response;
        $this->civ13->logger->warning('HTTP Server error: `An endpoint for `' . $request->getUri()->getPath() . '` resulted in an object that did not implement the ResponseInterface.`');
        return new Response(Response::STATUS_INTERNAL_SERVER_ERROR);
    }

    public function processEndpoint(ServerRequestInterface $request): Response
    {
        $data = [];
        if ($params = $request->getQueryParams())
            if (isset($params['data']))
                $data = json_decode(urldecode($params['data']), true);
        $uri = $request->getUri();
        $path = $uri->getPath(); // We need the .ext too!
        $ext = pathinfo($uri->getQuery(), PATHINFO_EXTENSION);
        foreach ($this->handlers as $endpoint => $callback) {
            switch ($this->match_methods[$endpoint]) {
                case 'exact':
                    $method_func = function () use ($callback, $endpoint, $path): ?callable
                    {
                        if ($endpoint == $path) return $callback;
                        return null;
                    };
                    break;
                case 'str_contains':
                    $method_func = function () use ($callback, $endpoint, $path): ?callable
                    {
                        if (str_contains($endpoint, $path)) return $callback;
                        return null;
                    };
                    break;
                case 'str_ends_with':
                    $method_func = function () use ($callback, $endpoint, $path): ?callable
                    {
                        if (str_ends_with($endpoint, $path)) return $callback;
                        return null;
                    };
                    break;
                case 'str_starts_with':
                default:
                    $method_func = function () use ($callback, $endpoint, $path): ?callable
                    {
                        if (str_starts_with($endpoint, $path)) return $callback;
                        return null;
                    };
            }
            if ($callback = $method_func()) { // Command triggered
                $whitelisted = false;
                if (! $whitelisted = $this->__isWhitelisted($request->getServerParams()['REMOTE_ADDR'], $data))
                    if (($this->whitelisted[$endpoint] ?? false) !== false)
                        return $this->__throwError("You do not have permission to access this endpoint.");
                if (($response = $callback($request, $data, $whitelisted, $endpoint)) instanceof HttpResponse) return $response;
                else return $this->__throwError("Callback for the endpoint `$path` is disabled due to an invalid response.");
            }
        }
        return $this->__throwError("An endpoint for `$path` does not exist.");
    }

    public function generateHelp(): string
    {   
        $array = [];
        foreach (array_keys($this->handlers) as $command) $array[$command] = $this->whitelisted[$command] ? true : false;
        $public = '';
        $restricted = '';
        $webhooks = '';
        $restricted_webhooks = '';
        
        foreach ($array as $command => $whitelisted) {
            if (str_starts_with($command, '/webhook/')) {
                if ($whitelisted) $restricted_webhooks .= "`$command`, ";
                else $webhooks .= "`$command`, ";
            } else {
                if ($whitelisted) $restricted .= "`$command`, ";
                else $public .= "`$command`, ";
            }
        }
        if (!empty($public)) $public = "Public: " . rtrim($public, ', ') . PHP_EOL;
        if (!empty($restricted)) $restricted = "Whitelisted: " . rtrim($restricted, ', ') . PHP_EOL;
        if (!empty($webhooks)) $webhooks = "Webhooks: " . rtrim($webhooks, ', ') . PHP_EOL;
        if (!empty($restricted_webhooks)) $restricted_webhooks = "Whitelisted Webhooks: " . rtrim($restricted_webhooks, ', ') . PHP_EOL;
        $result = $public . $restricted . $webhooks . $restricted_webhooks;
        return $result;
    }

    public function whitelist(string $ip): bool
    {
        if (! $this->__isValidIpAddress($ip)) {
            $this->civ13->logger->debug("HTTP Server error: `$ip` is not a valid IP address.");
            return false;
        }
        if (in_array($ip, $this->whitelist)) {
            $this->civ13->logger->debug("HTTP Server error: `$ip` is already whitelisted.");
            return false;
        }
        $this->civ13->logger->info("HTTP Server: `$ip` has been whitelisted.");
        $this->whitelist[] = $ip;
        return true;
    }
    public function unwhitelist(string $ip): bool
    {
        if (! $this->__isValidIpAddress($ip)) {
            $this->civ13->logger->debug("HTTP Server error: `$ip` is not a valid IP address.");
            return false;
        }
        if (! (($key = array_search($ip, $this->whitelist)) !== false)) {
            $this->civ13->logger->debug("HTTP Server error: `$ip` is not already whitelisted.");
            return false;
        }
        unset($this->whitelist[$key]);
        $this->civ13->logger->info("HTTP Server: `$ip` has been unwhitelisted.");
        return true;
    }
    
    public function offsetSet(int|string $offset, callable $callback, ?bool $whitelisted = false,  ?string $method = 'exact', ?string $description = ''): HttpHandler
    {
        parent::offsetSet($offset, $callback);
        $this->whitelisted[$offset] = $whitelisted;
        $this->match_methods[$offset] = $method;
        $this->descriptions[$offset] = $description;
        return $this;
    }

    /**
     * Determines if an IP address is whitelisted.
     *
     * @param string $ip The IP address to check.
     * @return bool Returns true if the IP address is whitelisted, false otherwise.
     */
    public function __isWhitelisted(string $ip, array $data = []): bool
    {
        if ($this->key)
            if (isset($data['key']))
                if ($data['key'] === $this->key)
                    return true;
        return (in_array($ip, $this->whitelist) || $this->__isLocal($ip));
    }
    
    /**
     * Check if the given IP address is a local IP address.
     *
     * @param string $ip The IP address to check.
     * @return bool Returns true if the IP address is local, false otherwise.
     */
    public function __isLocal(string $ip): bool
    {
        if ($ip === $this->external_ip || $ip === '127.0.0.1' || $ip === '::1') return true;
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
    }

    /**
     * Checks if the given IP address is a valid IPv4 address.
     *
     * @param string $ip The IP address to check.
     * @return bool Returns true if the IP address is a valid IPv4 address, false otherwise.
     */
    public function __isIPv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Checks if the given IP address is a valid IPv6 address.
     *
     * @param string $ip The IP address to check.
     * @return bool Returns true if the IP address is a valid IPv6 address, false otherwise.
     */
    public function __isIPv6(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    function __isValidIpAddress(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public function __throwError(string $error): Response
    {
        $this->civ13->logger->info("HTTP Server error: `$error`");
        return Response::json(
            ['error' => $error]
        )->withStatus(Response::STATUS_INTERNAL_SERVER_ERROR);
    }
}