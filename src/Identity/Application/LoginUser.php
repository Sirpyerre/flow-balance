<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Application;

use FlowBalance\Identity\Infrastructure\NativePasswordHasher;
use PDO;

final readonly class LoginUser
{
    public function __construct(private PDO $database, private NativePasswordHasher $hasher, private string $secret) {}

    public function login(string $email, string $password, string $ip, int $now): array
    {
        $normalize = $this->database->prepare(
            'SELECT lower(btrim(?, chr(32) || chr(9) || chr(10) || chr(13) || chr(12) || chr(11)))'
        );
        $normalize->execute([$email]);
        $normalized = $normalize->fetchColumn();
        $packedIp = inet_pton($ip);
        $key = hash_hmac('sha256', $normalized . "\0" . ($packedIp === false ? $ip : $packedIp), $this->secret);
        $this->database->prepare('INSERT INTO login_throttles (key_hash, updated_at)
            VALUES (?, to_timestamp(?)) ON CONFLICT DO NOTHING')->execute([$key, $now]);
        $query = $this->database->prepare(
            'SELECT array_to_json(failed_at) AS failures, extract(epoch FROM blocked_until)::bigint AS "blockedUntil"
            FROM login_throttles WHERE key_hash = ? FOR UPDATE'
        );
        $query->execute([$key]);
        $state = $query->fetch(PDO::FETCH_ASSOC);
        if ((int) $state['blockedUntil'] > $now) {
            return ['retryAfter' => (int) $state['blockedUntil'] - $now];
        }
        $userQuery = $this->database->prepare(
            'SELECT id::text, name, email, password_hash FROM users WHERE email_normalized = ? FOR UPDATE'
        );
        $userQuery->execute([$normalized]);
        $user = $userQuery->fetch(PDO::FETCH_ASSOC);
        $hash = $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$SGVsbG9Xb3JsZDEyMzQ1Ng$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        if ($this->hasher->verify($password, $hash) && $user) {
            if ($this->hasher->needsRehash($hash)) {
                $this->database->prepare('UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$this->hasher->hash($password), $user['id']]);
            }
            $this->database->prepare('UPDATE login_throttles SET failed_at = ARRAY[]::timestamptz[], blocked_until = NULL,
                updated_at = to_timestamp(?) WHERE key_hash = ?')->execute([$now, $key]);
            unset($user['password_hash']);
            return ['user' => $user];
        }
        $failures = $state['blockedUntil'] === null ? json_decode($state['failures'], true, flags: JSON_THROW_ON_ERROR) : [];
        $failures = array_values(array_filter($failures, static fn (string $date): bool => strtotime($date) > $now - 900));
        $failures[] = gmdate(DATE_ATOM, $now);
        $blockedUntil = count($failures) >= 5 ? $now + 900 : null;
        $this->database->prepare('UPDATE login_throttles SET
            failed_at = ARRAY(SELECT value::timestamptz FROM json_array_elements_text(CAST(? AS json))),
            blocked_until = to_timestamp(?), updated_at = to_timestamp(?) WHERE key_hash = ?')
            ->execute([json_encode($failures, JSON_THROW_ON_ERROR), $blockedUntil, $now, $key]);
        return $blockedUntil === null ? [] : ['retryAfter' => 900];
    }
}
