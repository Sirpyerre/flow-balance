<?php

declare(strict_types=1);

namespace FlowBalance\Shared\Configuration;

final readonly class AppSettings
{
    private const DEVELOPMENT_ORIGIN = 'https://flow-balance.test';
    private const MINIMUM_SECRET_LENGTH = 32;

    private function __construct(
        public string $environment,
        public string $origin,
        private string $secret,
        public array $trustedProxyPeers,
        public string $proxyToken,
    ) {}

    public static function fromEnvironment(array $values): self
    {
        $environment = $values['APP_ENV'] ?? 'development';
        if (!in_array($environment, ['development', 'testing', 'production'], true)) {
            throw new InvalidConfiguration('Unknown application environment');
        }
        $origin = is_string($values['APP_ORIGIN'] ?? null) ? $values['APP_ORIGIN'] : '';
        if ($environment === 'production') {
            // Never fall back to the development origin: it would be accepted as the site's own.
            if ($origin === '') {
                throw new InvalidConfiguration('Missing application setting: APP_ORIGIN');
            }
            if (!preg_match('#\Ahttps://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?\z#', $origin)) {
                throw new InvalidConfiguration('Invalid application setting: APP_ORIGIN');
            }
        }
        $secretKey = $environment === 'testing' ? 'TEST_APP_SECRET' : 'APP_SECRET';
        return new self(
            $environment,
            $origin === '' ? self::DEVELOPMENT_ORIGIN : $origin,
            is_string($values[$secretKey] ?? null) ? $values[$secretKey] : '',
            array_map('trim', explode(',', is_string($values['TRUSTED_API_PROXY_PEERS'] ?? null) ? $values['TRUSTED_API_PROXY_PEERS'] : '')),
            is_string($values['CLIENT_IP_PROXY_TOKEN'] ?? null) ? $values['CLIENT_IP_PROXY_TOKEN'] : '',
        );
    }

    /** The signing secret, or null when it is missing or too short to be safe. */
    public function secret(): ?string
    {
        return strlen($this->secret) < self::MINIMUM_SECRET_LENGTH ? null : $this->secret;
    }
}
