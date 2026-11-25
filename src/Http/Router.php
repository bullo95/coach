<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Routeur minimal : chemins exacts ou avec paramètres « {nom} ».
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable, public: bool}> */
    private array $routes = [];

    public function get(string $path, callable $handler, bool $public = false): void
    {
        $this->add('GET', $path, $handler, $public);
    }

    public function post(string $path, callable $handler, bool $public = false): void
    {
        $this->add('POST', $path, $handler, $public);
    }

    public function add(string $method, string $path, callable $handler, bool $public = false): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'public' => $public];
    }

    /**
     * @param callable(Request): void $guard appelé avant chaque route non publique
     */
    public function dispatch(Request $request, callable $guard): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            if (!$route['public']) {
                $guard($request);
            }

            return ($route['handler'])($request);
        }

        throw $pathMatched ? new HttpError(405, 'Méthode non autorisée') : new HttpError(404, 'Introuvable');
    }
}
