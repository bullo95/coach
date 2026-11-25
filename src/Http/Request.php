<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @param array<string, string> $params */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        private readonly string $body = '',
        private readonly array $headers = [],
        public array $params = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            rtrim($path, '/') ?: '/',
            $_GET,
            (string) file_get_contents('php://input'),
            $headers,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isJson(): bool
    {
        return str_starts_with((string) $this->header('content-type'), 'application/json');
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        $data = json_decode($this->body, true);
        if (!\is_array($data)) {
            throw new HttpError(400, 'Corps JSON invalide');
        }

        return $data;
    }
}
