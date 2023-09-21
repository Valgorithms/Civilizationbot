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
    public function __invoke(ServerRequestInterface $request, array $data, string $endpoint): HttpResponse;
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

        $expectedParameterTypes = [ServerRequestInterface::class, 'array', 'string'];

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

    public function __invoke(ServerRequestInterface $request, array $data = [], string $endpoint = ''): HttpResponse
    {
        return call_user_func($this->callback, $request, $data, $endpoint);
    }
}

use Civ13\Interfaces\HttpHandlerInterface;
use React\Http\Message\Response;

class HttpHandler extends Handler implements HttpHandlerInterface
{ // TODO
    protected string $external_ip = '127.0.0.1';
    protected array $whitelist = [];

    protected array $endpoints = [];
    
    protected array $whitelisted = [];
    protected array $match_methods = [];
    protected array $descriptions = [];


    public function __construct(Civ13 &$civ13, array $handlers = [], array $whitelist = [])
    {
        parent::__construct($civ13, $handlers);
        if ($external_ip = file_get_contents('http://ipecho.net/plain')) $this->external_ip = $external_ip;
        $this->whitelist = $whitelist;
    }

    public function handle(ServerRequestInterface $request): Response
    {
        $scheme = $request->getUri()->getScheme();
        $host = $request->getUri()->getHost();
        $port = $request->getUri()->getPort();
        $path = $request->getUri()->getPath();
        if ($path === '' || $path[0] !== '/' || $path === '/') $path = '/index';
        $query = $request->getUri()->getQuery();
        $fragment = $request->getUri()->getFragment(); // Only used on the client side, ignored by the server

        $url = "$scheme://$host:$port$path". ($query ? "?$query" : '') . ($fragment ? "#$fragment" : '');
        $this->civ13->logger->info("[WEBAPI URL] $url");

        $ext = pathinfo($query, PATHINFO_EXTENSION);
        $data = $request->getQueryParams();

        return $this->processEndpoint($request);
    }

    public function processEndpoint(ServerRequestInterface $request): Response
    { // TODO
        $data = $request->getQueryParams();
        $path = $request->getUri()->getPath();
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
                if (($this->whitelisted[$endpoint] ?? false) !== false)
                    if (! $this->__isWhitelisted($request->getServerParams()['REMOTE_ADDR']))
                        return $this->__throwError("You do not have permission to access this endpoint.");
                if (($response = $callback($request, $data, $endpoint)) instanceof HttpResponse) return $response;
                else return $this->__throwError("Callback for the endpoint `$path` is disabled due to an invalid response.");
            }
        }
        return $this->__throwError("An endpoint for `$path` does not exist.");
    }

    public function __throwError(string $error): Response
    {
        $this->civ13->logger->info("HTTP Server error: `$error`");
        return Response::json(
            ['error' => $error]
        )->withStatus(Response::STATUS_INTERNAL_SERVER_ERROR);
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
    public function __isWhitelisted(string $ip): bool
    {
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
        if ($ip === $this->external_ip) return true;
        if ($ip === '127.0.0.1') return true;
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
}