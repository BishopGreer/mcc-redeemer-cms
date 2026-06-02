<?php
class Router {
    private array $routes = [];

    public function get(string $pattern, callable $handler): void {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function any(string $pattern, callable $handler): void {
        $this->routes[] = ['ANY', $pattern, $handler];
    }

    public function dispatch(string $method, string $uri): void {
        $uri = strtok($uri, '?');
        $uri = '/' . trim($uri, '/');

        foreach ($this->routes as [$routeMethod, $pattern, $handler]) {
            if ($routeMethod !== 'ANY' && $routeMethod !== $method) continue;

            $regex = preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
            $regex = '#^' . $regex . '$#';

            if (preg_match($regex, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler($params);
                return;
            }
        }

        http_response_code(404);
        include BASE_PATH . '/templates/404.php';
    }
}
