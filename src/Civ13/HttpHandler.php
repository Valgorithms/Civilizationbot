<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2023-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Civ13\Interfaces\HttpHandlerCallbackInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response as HttpResponse;

final class HttpHandlerCallback implements HttpHandlerCallbackInterface
{
    private \Closure $callback;

    /**
     * Constructs a new instance of the HttpHandler class.
     *
     * @param callable $callback The callback function to be executed.
     * @throws \InvalidArgumentException If the callback does not have the expected parameters or type hints.
     */
    public function __construct(callable $callback)
    {
        $expectedParameterTypes = [ServerRequestInterface::class, 'string', 'bool'];
        
        $parameters = (new \ReflectionFunction($callback))->getParameters();
        if (count($parameters) !== $count = count($expectedParameterTypes)) throw new \InvalidArgumentException("The callback must take exactly $count parameters: " . implode(', ', $expectedParameterTypes));
        foreach ($parameters as $index => $parameter) {
            if (! $parameter->hasType()) throw new \InvalidArgumentException("Parameter $index must have a type hint.");
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType) $type = $type->getName();
            if ($type !== $expectedParameterTypes[$index]) throw new \InvalidArgumentException("Parameter $index must be of type {$expectedParameterTypes[$index]}.");
        }

        $this->callback = $callback;
    }

    /**
     * Invokes the HTTP handler.
     *
     * @param ServerRequestInterface $request The server request.
     * @param bool $whitelisted Indicates if the request is whitelisted.
     * @param string $endpoint The endpoint string.
     * @return HttpResponse The HTTP response.
     */
    public function __invoke(ServerRequestInterface $request, string $endpoint = '', bool $whitelisted = false): HttpResponse
    {
        return call_user_func($this->callback, $request, $endpoint, $whitelisted);
    }

    public function reject(string $part, string $id): HttpResponse
    {
        // $this->logger->info("[WEBAPI] Failed: $part, $id"); // This should be logged by the handler, not the callback
        return new HttpResponse(($id ? 404 : 400), ['Content-Type' => 'text/plain'], ($id ? 'Invalid' : 'Missing').' '.$part);
    }
}

use Civ13\Interfaces\HttpHandlerInterface;
//use Discord\Helpers\Collection;

class HttpHandler extends Handler implements HttpHandlerInterface
{ // TODO
    public string $external_ip = '127.0.0.1';
    private string $key = '';
    protected array $whitelist = [];
    protected array $ratelimits = [];

    protected array $endpoints = [];
    
    protected array $whitelisted = [];
    /** 
     * @var array<string|callable>
     */
    protected array $match_methods = [];
    protected array $descriptions = [];

    public string $last_ip = '';

    /**
     * Constructor for the HttpHandler class.
     *
     * @param Civ13 &$civ13 The Civ13 object.
     * @param array $handlers An array of handlers.
     * @param array $whitelist An array of IP addresses to whitelist.
     * @param string $key The key for authentication.
     */
    public function __construct(Civ13 &$civ13, array $handlers = [], array $whitelist = [], string $key = '')
    {
        parent::__construct($civ13, $handlers);
        if ($external_ip = file_get_contents('http://ipecho.net/plain')) $this->external_ip = $external_ip;
        foreach ($whitelist as $ip) $this->whitelist($ip);
        $this->key = $key;
        $this->afterConstruct();
    }

    public function afterConstruct(): void
    {
        $this->__setDefaultRatelimits();
    }

    /**
     * Sets the default rate limits for different types of requests.
     */
    private function __setDefaultRatelimits(): void
    {
        $this->setRateLimit('global10minutes', 10000, 600); // 10,000 requests per 10 minutes
        $this->setRateLimit('invalid', 10, 300); // 10 invalid requests per 5 minutes
        $this->setRateLimit('abuse', 100, 86400); // 100 invalid requests per day
    }

    
    /**
     * Handles the HTTP request and returns an HTTP response.
     *
     * @param ServerRequestInterface $request The HTTP request object.
     * @return HttpResponse The HTTP response object.
     */
    public function handle(ServerRequestInterface $request): HttpResponse
    {
        $this->last_ip = $request->getServerParams()['REMOTE_ADDR'];
        if ($retry_after = $this->isGlobalRateLimited($this->last_ip) ?? $this->isInvalidLimited($this->last_ip)) return $this->__throwError("You are being rate limited. Retry after $retry_after seconds.", HttpResponse::STATUS_TOO_MANY_REQUESTS);
        //$scheme = $request->getUri()->getScheme();
        //$host = $request->getUri()->getHost();
        //$port = $request->getUri()->getPort();        
        if (! $path = $request->getUri()->getPath()) $path = '/';
        //$query = $request->getUri()->getQuery();
        //$ext = pathinfo($query, PATHINFO_EXTENSION);
        //$fragment = $request->getUri()->getFragment(); // Only used on the client side, ignored by the server
        //$url = "$scheme://$host:$port$path". ($query ? "?$query" : '') . ($fragment ? "#$fragment" : '');
        if (str_starts_with($path, '/webhook/')) $this->logger->debug("[WEBAPI URL] $path");
        else $this->logger->info("[WEBAPI URL] $path");
        try {
            if (! $array = $this->__getCallback($request)) return $this->__throwError("An endpoint for `$path` does not exist.", HttpResponse::STATUS_NOT_FOUND);
            return $this->__processCallback($request, $array['callback'], $array['endpoint']);
        } catch (\Throwable $e) {
            $this->logger->error("HTTP Server error: An endpoint for `$path` failed with error `{$e->getMessage()}`");
            return new HttpResponse(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Retrieves the callback and endpoint based on the request path.
     *
     * @param ServerRequestInterface $request The server request object.
     * @return array|null An array containing the callback and endpoint if found, or null if not found.
     */
    private function __getCallback(ServerRequestInterface $request): ?array
    {
        //$ext = pathinfo($request->getUri()->getQuery(), PATHINFO_EXTENSION); // We need the .ext too!
        $matchMethod = $this->match_methods[$path = $request->getUri()->getPath()] ?? 'str_starts_with';
        if (isset($this->handlers[$path]) && $matchMethod === 'exact')
            return ['callback' => $this->handlers[$path], 'endpoint' => $path];
        foreach ($this->handlers as $endpoint => $callback) {
            if ( is_callable($matchMethod) && call_user_func($matchMethod, $endpoint, $path))
                return ['callback' => $callback, 'endpoint' => $endpoint];
            if ($matchMethod !== 'exact' && ! is_callable($matchMethod) && str_starts_with($endpoint, $path)) // Default to str_starts_with if no valid match method is provided
                return ['callback' => $callback, 'endpoint' => $endpoint];
        }
        return null;
    }
    
    /**
     * Executes the HTTP handler.
     *
     * @param ServerRequestInterface $request The HTTP request object.
     * @param callable $callback The callback function to be executed.
     * @param string $endpoint The endpoint being accessed.
     * @return HttpResponse The HTTP response object.
     */
    private function __processCallback(ServerRequestInterface $request, callable $callback, string $endpoint): HttpResponse
    {
        // Check if the endpoint and IP address are whitelisted
        if (! $whitelisted = $this->__isWhitelisted($request, $this->last_ip))
            if (($this->whitelisted[$endpoint] ?? false) !== false)
                return $this->__throwError("You do not have permission to access this endpoint.", HttpResponse::STATUS_FORBIDDEN);

        // Check if the endpoint is rate limited
        if ($this->isRateLimited($endpoint, $this->last_ip)) // This is called before the callback is executed so it will be rate limited even if the callback fails and to save processing time
            return $this->__throwError("The resource is being rate limited.", HttpResponse::STATUS_TOO_MANY_REQUESTS);

        // Execute the callback and validate the response
        if (!($response = $callback($request, $endpoint, $whitelisted)) instanceof HttpResponse)
            return $this->__throwError("Callback for the endpoint `{$request->getUri()->getPath()}` is disabled due to an invalid HttpResponse.", HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

        // Update the rate limit requests
        if (isset($this->ratelimits[$endpoint]['requests']) && $requests = $this->ratelimits[$endpoint]['requests']) {
            $lastRequest = end($requests);
            if ($lastRequest['status'] !== $status = $response->getStatusCode()) // Status code could be null or otherwise different if the callback changed it
                $lastRequest['status'] = $status;
            if (in_array($status, [HttpResponse::STATUS_UNAUTHORIZED, HttpResponse::STATUS_FORBIDDEN, HttpResponse::STATUS_NOT_FOUND, HttpResponse::STATUS_TOO_MANY_REQUESTS, HttpResponse::STATUS_INTERNAL_SERVER_ERROR]))
                $this->addRequestToRateLimit('invalid', $this->last_ip, $status);
        }

        return $response;
    }
    
    /**
     * Generates a help message containing information about the available commands and their whitelisting status.
     *
     * @return string The generated help message.
     */
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

    /**
     * Whitelists an IP address.
     *
     * @param string $ip The IP address to whitelist.
     * @return bool Returns true if the IP address was successfully whitelisted, false otherwise.
     */
    public function whitelist(string $ip): bool
    {
        if (! $this->__isValidIpAddress($ip)) {
            $this->logger->debug("HTTP Server error: `$ip` is not a valid IP address.");
            return false;
        }
        if (in_array($ip, $this->whitelist)) {
            $this->logger->debug("HTTP Server error: `$ip` is already whitelisted.");
            return false;
        }
        $this->logger->info("HTTP Server: `$ip` has been whitelisted.");
        $this->whitelist[] = $ip;
        return true;
    }
    /**
     * Removes an IP address from the whitelist.
     *
     * @param string $ip The IP address to be removed from the whitelist.
     * @return bool Returns true if the IP address was successfully removed, false otherwise.
     */
    public function unwhitelist(string $ip): bool
    {
        if (! $this->__isValidIpAddress($ip)) {
            $this->logger->debug("HTTP Server error: `$ip` is not a valid IP address.");
            return false;
        }
        if (! (($key = array_search($ip, $this->whitelist)) !== false)) {
            $this->logger->debug("HTTP Server error: `$ip` is not already whitelisted.");
            return false;
        }
        unset($this->whitelist[$key]);
        $this->logger->info("HTTP Server: `$ip` has been unwhitelisted.");
        return true;
    }
    
    /**
     * Sets the rate limit for a specific endpoint.
     *
     * @param string $endpoint The endpoint to set the rate limit for.
     * @param int $limit The maximum number of requests allowed within the time window.
     * @param int $window The time window in seconds.
     * @return HttpHandler Returns the HttpHandler instance.
     */
    public function setRateLimit(string $endpoint, int $limit, int $window): HttpHandler
    {
        $this->ratelimits[$endpoint] = [
            'limit' => $limit,
            'window' => $window,
            'requests' => [],
        ];
        return $this;
    }

    /**
     * Checks if the given IP address is globally rate limited.
     *
     * @param string $ip The IP address to check.
     * @return int|null The maximum expiration time in seconds if the IP is rate limited, or null if it is not rate limited.
     */
    public function isGlobalRateLimited(string $ip): ?int
    {
        $globalEndpoints = ['global10minutes'];
        $expirations = [];
        foreach ($globalEndpoints as $endpoint)
            if ($retry_after = $this->isRateLimited($endpoint, $ip))
                $expirations[] = $retry_after;
        return (empty($expirations) ? null : max($expirations));
    }

    /**
     * Checks if the given IP address has any invalid limited endpoints and returns the maximum expiration time.
     *
     * @param string $ip The IP address to check.
     * @return int|null The maximum expiration time in seconds, or null if there are no invalid limited endpoints.
     */
    public function isInvalidLimited(string $ip): ?int
    {
        $invalidEndpoints = ['invalid', 'abuse'];
        $expirations = [];
        foreach ($invalidEndpoints as $endpoint)
            if ($retry_after = $this->__getRateLimitExpiration($endpoint, $ip))
                $expirations[] = $retry_after;
        return (empty($expirations) ? null : max($expirations));
    }

    /**
     * Retrieves the expiration time of the rate limit for a specific endpoint and IP address.
     *
     * @param string $endpoint The endpoint to check.
     * @param string $ip The IP address of the request.
     * @return int|null The number of seconds until the rate limit expires, or null if not rate limited.
     */
    public function isRateLimited(string $endpoint, string $ip): ?int
    {
        if (isset($this->ratelimits[$endpoint])) $this->addRequestToRateLimit($endpoint, $ip);
        return $this->__getRateLimitExpiration($endpoint, $ip);
    }
    public function __getRateLimitExpiration(string $endpoint, string $ip): ?int
    {
        if (! isset($this->ratelimits[$endpoint])) {
            //$this->logger->info("`$endpoint` has no rate limit defined.");
            return null;
        }

        $rateLimit = $this->ratelimits[$endpoint];
        $currentTime = time();

        // Remove expired requests from the rate limit tracking
        $rateLimit['requests'] = array_filter($rateLimit['requests'], function ($request) use ($currentTime, $rateLimit) {
            return ($currentTime - $request['time']) <= $rateLimit['window'];
        });

        // Check if the number of requests exceeds the limit for the given IP address
        $requestsFromIp = array_filter($rateLimit['requests'], function ($request) use ($ip) {
            return $request['ip'] === $ip;
        });
        if (count($requestsFromIp) > $rateLimit['limit']) {
            $earliestRequest = min(array_column($requestsFromIp, 'time'));
            $expirationTime = $earliestRequest + $rateLimit['window'];
            $retry_after = $expirationTime - $currentTime; // Return the number of seconds until the rate limit expires
            $this->logger->info("HTTP Server: `$ip` is being rate limited for `$endpoint` for `$retry_after` seconds.");
            return $retry_after;
        }

        return null;
    }

    /**
     * Adds a request to the rate limit for a specific endpoint.
     *
     * @param string $endpoint The endpoint to add the request to.
     * @param string $ip The IP address of the request.
     * @param int|null $status The status code of the request (optional).
     * @param int|null $currentTime The current time (optional).
     * @return void
     */
    private function addRequestToRateLimit(string $endpoint, string $ip, ?int $status = null, ?int $currentTime = null): void
    {
        if (! $currentTime) $currentTime = time();
        $rateLimit = $this->ratelimits[$endpoint] ?? [];
        $rateLimit['requests'][] = [
            'ip' => $ip,
            'time' => $currentTime,
            'status' => $status,
        ];
        $this->ratelimits[$endpoint] = $rateLimit;
    }

    /**
     * Sets the value at the specified offset and associates it with the provided callback.
     *
     * @param int|string $offset The offset to set the value at.
     * @param callable $callback The callback to associate with the value.
     * @param bool|null $whitelisted (optional) Whether the offset is whitelisted. Default is false.
     * @param string|null $method (optional) The matching method. Default is 'exact'.
     * @param string|null $description (optional) The description for the offset. Default is an empty string.
     * @return HttpHandler Returns the updated HttpHandler instance.
     */
    public function offsetSet(int|string $offset, callable $callback, ?bool $whitelisted = false,  ?string $method = 'exact', ?string $description = ''): HttpHandler
    {
        parent::offsetSet($offset, $callback);
        $this->whitelisted[$offset] = $whitelisted;
        $this->match_methods[$offset] = $method;
        $this->descriptions[$offset] = $description;
        if ($method === 'exact') $this->__reorderHandlers();
        return $this;
    }

    /**
     * Reorders the handlers based on the match methods.
     *
     * This method separates the handlers into two arrays: $exactHandlers and $otherHandlers.
     * Handlers with a match method of 'exact' are stored in $exactHandlers, while the rest are stored in $otherHandlers.
     * The two arrays are then merged and assigned back to the $handlers property, ensuring that exact matches are checked last.
     *
     * @return void
     */
    private function __reorderHandlers(): void
    {
        $exactHandlers = [];
        $otherHandlers = [];
        foreach ($this->handlers as $command => $handler) {
            if ($this->match_methods[$command] === 'exact') {
                $exactHandlers[$command] = $handler;
            } else {
                $otherHandlers[$command] = $handler;
            }
        }
        $this->handlers = $otherHandlers + $exactHandlers;
    }

    /**
     * Determines if an IP address is whitelisted.
     *
     * @param string $ip The IP address to check.
     * @return bool Returns true if the IP address is whitelisted, false otherwise.
     */
    public function __isWhitelisted(ServerRequestInterface $request, string $ip): bool
    {
        if ($this->key) {
            $data = [];
            if ($params = $request->getQueryParams()) if (isset($params['data'])) $data = @json_decode(urldecode($params['data']), true);
            if (isset($data['key']))
                if ($data['key'] === $this->key)
                    return true;
        }
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

    /**
     * Checks if the given IP address is valid.
     *
     * @param string $ip The IP address to validate.
     * @return bool Returns true if the IP address is valid, false otherwise.
     */
    function __isValidIpAddress(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Throws an error response with the specified error message and status code.
     *
     * @param string $error The error message.
     * @param int $status The status code of the error response. Defaults to 500 (Internal Server Error).
     * @return HttpResponse The error response.
     */
    public function __throwError(string $error, int $status = HttpResponse::STATUS_INTERNAL_SERVER_ERROR): HttpResponse
    {
        if ($status === HttpResponse::STATUS_INTERNAL_SERVER_ERROR) $this->logger->info("HTTP error for IP: `$this->last_ip`: `$error`");
        //if (in_array($status, [HttpResponse::STATUS_UNAUTHORIZED, HttpResponse::STATUS_FORBIDDEN, HttpResponse::STATUS_NOT_FOUND, HttpResponse::STATUS_TOO_MANY_REQUESTS, HttpResponse::STATUS_INTERNAL_SERVER_ERROR])) {
        if (strval($status)[0] === '4' || strval($status)[0] === '5') { // 4xx or 5xx (client or server error)
            $time = time();
            $this->addRequestToRateLimit('invalid', $this->last_ip, $status, $time);
            $this->addRequestToRateLimit('abuse', $this->last_ip, $status, $time);
        }
        return HttpResponse::json(
            ['error' => $error]
        )->withStatus($status);
    }
}
