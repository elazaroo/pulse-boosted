<?php

namespace Elazaroo\PulseBoosted\Queues;

use Carbon\CarbonImmutable;

/**
 * A job read straight off the queue backend, as opposed to one read back out
 * of our own records.
 *
 * Everything here comes from `json_decode` of the stored payload. The
 * serialized `data.command` inside that payload is deliberately left alone:
 * unserializing it would mean instantiating arbitrary classes from whatever
 * is on the queue.
 */
class PendingJob
{
    public function __construct(
        public ?string $uuid,
        public ?string $id,
        public string $name,
        public ?string $class,
        public int $attempts,
        public ?int $maxTries,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $availableAt,
        public ?CarbonImmutable $reservedAt,
        public ?string $batchId = null,
    ) {
        //
    }

    /**
     * Build one from a decoded queue payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(
        array $payload,
        ?string $id = null,
        int $attempts = 0,
        ?int $availableAt = null,
        ?int $reservedAt = null,
    ): self {
        $createdAt = $payload['createdAt'] ?? $payload['pushedAt'] ?? null;

        return new self(
            uuid: isset($payload['uuid']) ? (string) $payload['uuid'] : null,
            id: $id,
            name: (string) ($payload['displayName'] ?? $payload['job'] ?? 'Unknown'),
            class: isset($payload['data']['commandName']) ? (string) $payload['data']['commandName'] : null,
            attempts: $attempts,
            maxTries: isset($payload['maxTries']) ? (int) $payload['maxTries'] : null,
            createdAt: $createdAt === null ? null : CarbonImmutable::createFromTimestamp((int) $createdAt),
            availableAt: $availableAt === null ? null : CarbonImmutable::createFromTimestamp($availableAt),
            reservedAt: $reservedAt === null ? null : CarbonImmutable::createFromTimestamp($reservedAt),
            batchId: isset($payload['data']['batchId']) ? (string) $payload['data']['batchId'] : null,
        );
    }

    /**
     * Decode a raw payload string, tolerating anything that is not valid JSON.
     *
     * @return array<string, mixed>
     */
    public static function decode(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
