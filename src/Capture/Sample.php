<?php

namespace Foodics\DbHound\Capture;

use Illuminate\Contracts\Encryption\Encrypter;
use Throwable;

/**
 * One concrete example of a Fingerprint's query with its real bindings.
 * Only ever persisted encrypted; decrypted in memory to run EXPLAIN.
 */
final readonly class Sample
{
    /**
     * @param  array<int|string, mixed>  $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
    ) {}

    public function encrypt(Encrypter $encrypter): string
    {
        return $encrypter->encrypt(['sql' => $this->sql, 'bindings' => $this->bindings]);
    }

    public static function decrypt(Encrypter $encrypter, string $payload): ?self
    {
        try {
            $data = $encrypter->decrypt($payload);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['sql'] ?? null) || ! is_array($data['bindings'] ?? null)) {
            return null;
        }

        return new self($data['sql'], $data['bindings']);
    }
}
