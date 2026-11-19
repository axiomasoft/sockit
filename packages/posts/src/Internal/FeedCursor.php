<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Posts\Exceptions\InvalidFeedCursorException;
use SocKit\Support\CanonicalJson;

/** Encrypt-then-MAC: even a denied candidate's ID/time stays opaque. Keys are server managed, base64 32-byte secrets. */
final readonly class FeedCursor
{
    /** @param array<string, string> $keys */
    public function __construct(private array $keys, private string $active, private int $ttl)
    {
        if ($ttl < 1 || $ttl > 86400 || ! isset($keys[$active]) || count($keys) > 8) {
            throw new PostException(ProblemCode::ConfigurationInvalid);
        }
        foreach ($keys as $id => $key) {
            if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/D', $id) || strlen(base64_decode($key, true) ?: '') !== 32) {
                throw new PostException(ProblemCode::ConfigurationInvalid);
            }
        }
    }

    /** @param array<string, mixed> $context
     * @param array{time:string, id:string} $position */
    public function encode(array $context, array $position, ?int $now = null): string
    {
        $now ??= time();
        $payload = CanonicalJson::encode(['context' => hash('sha256', CanonicalJson::encode($context)), 'expires' => $now + $this->ttl, 'position' => $position]);
        $iv = random_bytes(12);
        $tag = '';
        $header = '1.'.$this->active;
        $cipher = openssl_encrypt($payload, 'aes-256-gcm', $this->key($this->active, 'encrypt'), OPENSSL_RAW_DATA, $iv, $tag, $header);
        if ($cipher === false) {
            throw new PostException(ProblemCode::ConfigurationInvalid);
        }
        $signed = $header.'.'.$this->base64($iv.$tag.$cipher);

        return $signed.'.'.$this->base64(hash_hmac('sha256', $signed, $this->key($this->active, 'mac'), true));
    }

    /** @param array<string, mixed> $context
     * @return array{time:string, id:string} */
    public function decode(string $token, array $context, ?int $now = null): array
    {
        try {
            if (strlen($token) > 4096 || ! preg_match('/^1\.([a-zA-Z][a-zA-Z0-9_-]{0,31})\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})$/D', $token, $parts) || ! isset($this->keys[$parts[1]])) {
                throw new InvalidFeedCursorException;
            }
            $signed = '1.'.$parts[1].'.'.$parts[2];
            // Authenticate all bytes before decrypting or trusting context, expiry or position.
            if (! hash_equals(hash_hmac('sha256', $signed, $this->key($parts[1], 'mac'), true), $this->unbase64($parts[3]))) {
                throw new InvalidFeedCursorException;
            }
            $bytes = $this->unbase64($parts[2]);
            if (strlen($bytes) < 29) {
                throw new InvalidFeedCursorException;
            }
            $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->key($parts[1], 'encrypt'), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), '1.'.$parts[1]);
            $payload = $plain === false ? null : json_decode($plain, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ($payload['context'] ?? null) !== hash('sha256', CanonicalJson::encode($context))
                || ! is_int($payload['expires'] ?? null) || $payload['expires'] <= ($now ?? time())) {
                throw new InvalidFeedCursorException;
            }
            $position = $payload['position'] ?? null;
            if (! is_array($position) || ! is_string($position['time'] ?? null) || ! is_string($position['id'] ?? null)
                || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $position['id'])) {
                throw new InvalidFeedCursorException;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $position['time'], new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d H:i:s.u') !== $position['time']) {
                throw new InvalidFeedCursorException;
            }

            return ['time' => $position['time'], 'id' => $position['id']];
        } catch (\Throwable) {
            throw new InvalidFeedCursorException;
        }
    }

    private function key(string $id, string $purpose): string
    {
        return hash_hkdf('sha256', base64_decode($this->keys[$id], true) ?: throw new PostException(ProblemCode::ConfigurationInvalid), 32, 'sockit.posts.feed.'.$purpose);
    }

    private function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function unbase64(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bytes === false || $this->base64($bytes) !== $text) {
            throw new InvalidFeedCursorException;
        }

        return $bytes;
    }
}
