<?php
declare(strict_types=1);

namespace App;

use Closure;

final class Router
{
    /** @var array<int,array{method:string,regex:string,params:array<int,string>,handler:Closure}> */
    private array $routes = [];

    public function add(string $method, string $pattern, Closure $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => strtoupper($method), 'regex' => $regex, 'handler' => $handler];
    }

    public function get(string $p, Closure $h): void    { $this->add('GET', $p, $h); }
    public function post(string $p, Closure $h): void   { $this->add('POST', $p, $h); }
    public function patch(string $p, Closure $h): void  { $this->add('PATCH', $p, $h); }
    public function put(string $p, Closure $h): void    { $this->add('PUT', $p, $h); }
    public function delete(string $p, Closure $h): void { $this->add('DELETE', $p, $h); }

    public function dispatch(string $method, string $path): void
    {
        // Tolerate duplicated leading slashes (e.g. a base URL with a trailing "/").
        $path = preg_replace('#^/+#', '/', $path) ?? $path;
        $path = rtrim($path, '/') ?: '/';
        $matchedPath = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $matchedPath = true;
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $route['handler']($params);
            return;
        }
        Http::error($matchedPath ? 'Method not allowed for this endpoint.' : 'Unknown endpoint.', $matchedPath ? 405 : 404);
    }
}
