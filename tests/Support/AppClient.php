<?php

declare(strict_types=1);

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Mezzio\Application;
use Mezzio\MiddlewareFactory;

/** Drives the real Mezzio application in process, the way the integration tests do. */
final class AppClient
{
    public const ORIGIN = 'https://flow-balance.test';

    private function __construct(private Application $app) {}

    public static function boot(): self
    {
        $root = dirname(__DIR__, 2);
        $container = require $root . '/config/container.php';
        $app = $container->get(Application::class);
        (require $root . '/config/pipeline.php')($app, $container->get(MiddlewareFactory::class));
        (require $root . '/config/routes.php')($app);
        return new self($app);
    }

    /** @return array{0: int, 1: mixed, 2: string, 3: string} status, body, session cookie, Cache-Control */
    public function register(string $name, string $email, string $password): array
    {
        return $this->write('/api/auth/register', [
            'name' => $name, 'email' => $email, 'password' => $password, 'passwordConfirmation' => $password,
        ]);
    }

    /** @return array{0: int, 1: mixed, 2: string, 3: string} */
    public function write(string $path, array $data, string $cookie = '', string $method = 'POST', string $key = '', string $version = ''): array
    {
        [$status, $challenge, $cookie] = $this->request('GET', '/api/auth/csrf', $cookie);
        if ($status !== 200) {
            throw new RuntimeException('CSRF challenge failed: ' . json_encode($challenge));
        }
        return $this->request($method, $path, $cookie, $data, $challenge['csrfToken'], $key, $version);
    }

    /** @return array{0: int, 1: mixed, 2: string, 3: string} */
    public function request(string $method, string $path, string $cookie, ?array $data = null, string $csrf = '', string $key = '', string $version = '', array $headers = []): array
    {
        $body = new Stream('php://temp', 'wb+');
        if ($data !== null) {
            $body->write(json_encode((object) $data, JSON_THROW_ON_ERROR));
            $body->rewind();
        }
        $request = new ServerRequest(['REMOTE_ADDR' => '127.0.0.1'], [], self::ORIGIN . $path, $method, $body);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($cookie !== '') {
            $request = $request->withCookieParams(['__Host-flow_balance' => $cookie]);
        }
        if ($data !== null) {
            $request = $request->withHeader('Origin', self::ORIGIN)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-CSRF-Token', $csrf);
        }
        if ($version !== '') {
            $request = $request->withHeader('If-Match', $version);
        }
        if ($key !== '') {
            $request = $request->withHeader('Idempotency-Key', $key);
        }
        $response = $this->app->handle($request);
        if (preg_match('/^__Host-flow_balance=([a-f0-9]{64});/', $response->getHeaderLine('Set-Cookie'), $match)) {
            $cookie = $match[1];
        }
        $content = (string) $response->getBody();
        $result = str_starts_with($response->getHeaderLine('Content-Type'), 'text/csv') ? $content : json_decode($content, true);
        return [$response->getStatusCode(), $result, $cookie, $response->getHeaderLine('Cache-Control')];
    }
}
