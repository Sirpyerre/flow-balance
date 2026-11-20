<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Infrastructure;

use FlowBalance\Identity\Domain\SessionPolicy;
use FlowBalance\Shared\Clock\Clock;
use Mezzio\Session\Session;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionPersistenceInterface;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SqlSessionPersistence implements SessionPersistenceInterface
{
    private const COOKIE = '__Host-flow_balance';

    public function __construct(private PDO $database, private Clock $clock, private SessionPolicy $policy) {}

    public function initializeSessionFromRequest(ServerRequestInterface $request): SessionInterface
    {
        $token = $request->getCookieParams()[self::COOKIE] ?? '';
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return new Session([]);
        }
        $query = $this->database->prepare(
            'SELECT user_id, payload, revoked_at, UNIX_TIMESTAMP(authenticated_at) AS authenticated_at,
                UNIX_TIMESTAMP(last_activity_at) AS last_activity_at, UNIX_TIMESTAMP(expires_at) AS expires_at
            FROM server_sessions WHERE token_hash = ? FOR UPDATE'
        );
        $query->execute([hash('sha256', $token)]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $now = $this->clock->now();
        if (!$row || $row['revoked_at'] !== null || (int) $row['expires_at'] <= $now) {
            return new Session([]);
        }
        if ($row['user_id'] !== null && !$this->policy->isActive(
            (int) $row['authenticated_at'], (int) $row['last_activity_at'], $now
        )) {
            return new Session([]);
        }
        return new Session(json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR), $token);
    }

    public function persistSession(SessionInterface $session, ResponseInterface $response): ResponseInterface
    {
        if (!$session->hasChanged() && !$session->isRegenerated()) {
            return $response;
        }
        $token = $session->getId();
        $data = $session->toArray();
        $now = $this->clock->now();
        $authenticatedAt = $data['authenticatedAt'] ?? null;
        $lastActivity = $data['lastActivityAt'] ?? $now;
        if ($token !== '' && $session->isRegenerated()) {
            $this->database->prepare('UPDATE server_sessions SET revoked_at = FROM_UNIXTIME(?) WHERE token_hash = ?')
                ->execute([$now, hash('sha256', $token)]);
            $token = '';
        }
        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        if ($token !== '') {
            $this->database->prepare(
                'UPDATE server_sessions SET payload = ?, last_activity_at = FROM_UNIXTIME(?) WHERE token_hash = ? AND revoked_at IS NULL'
            )->execute([$payload, $lastActivity, hash('sha256', $token)]);
            return $response;
        }
        $token = bin2hex(random_bytes(32));
        $lifetime = $authenticatedAt === null ? SessionPolicy::ANONYMOUS_SECONDS : SessionPolicy::ABSOLUTE_SECONDS;
        $this->database->prepare(
            'INSERT INTO server_sessions
            (token_hash, user_id, payload, created_at, authenticated_at, last_activity_at, expires_at)
            VALUES (?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?))'
        )->execute([hash('sha256', $token), $data['userId'] ?? null, $payload, $now, $authenticatedAt,
            $lastActivity, ($authenticatedAt ?? $now) + $lifetime]);
        return $response->withAddedHeader('Set-Cookie',
            self::COOKIE . "={$token}; Max-Age={$lifetime}; Path=/; Secure; HttpOnly; SameSite=Lax");
    }
}
