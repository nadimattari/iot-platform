<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Reads the TimescaleDB `telemetry` schema (written by the ingestion service).
 * The telemetry tables are outside the Doctrine ORM mapping, so reads go
 * through the shared DBAL connection as raw SQL.
 */
final class TelemetryReader
{
    /** Map of API resolution keys to TimescaleDB `time_bucket` intervals. */
    public const RESOLUTIONS = [
        '1s' => '1 second',
        '15s' => '15 seconds',
        '1m' => '1 minute',
        '5m' => '5 minutes',
        '15m' => '15 minutes',
        '1h' => '1 hour',
        '1d' => '1 day',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Bucketed series for a device.
     *
     * @return list<array{bucket: string, field: string, min: float, max: float, avg: float, count: int}>
     */
    public function series(string $deviceId, \DateTimeInterface $from, \DateTimeInterface $to, string $resolution): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT time_bucket(:bucket, time) AS bucket,
       field,
       MIN(value) AS min,
       MAX(value) AS max,
       AVG(value) AS avg,
       COUNT(*) AS count
FROM telemetry.telemetry_points
WHERE device_id = :device_id
  AND time >= :from
  AND time < :to
GROUP BY bucket, field
ORDER BY bucket, field
SQL,
            [
                'bucket' => self::RESOLUTIONS[$resolution],
                'device_id' => $deviceId,
                'from' => $from->format('Y-m-d\TH:i:s.uP'),
                'to' => $to->format('Y-m-d\TH:i:s.uP'),
            ],
        );

        return array_map(
            static fn (array $row): array => [
                'bucket' => self::iso($row['bucket']),
                'field' => (string) $row['field'],
                'min' => (float) $row['min'],
                'max' => (float) $row['max'],
                'avg' => (float) $row['avg'],
                'count' => (int) $row['count'],
            ],
            $rows,
        );
    }

    /**
     * Latest value per field (DISTINCT ON), ordered by time descending.
     *
     * @return array<string, array{value: float, time: string, type: string, quality: int}>
     */
    public function last(string $deviceId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT DISTINCT ON (field) field, value, time, type, quality
FROM telemetry.telemetry_points
WHERE device_id = :device_id
ORDER BY field, time DESC
SQL,
            ['device_id' => $deviceId],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['field']] = [
                'value' => (float) $row['value'],
                'time' => self::iso($row['time']),
                'type' => (string) $row['type'],
                'quality' => (int) $row['quality'],
            ];
        }

        return $result;
    }

    /**
     * Most recent sample time for a device, or null when it has no telemetry.
     */
    public function lastSeen(string $deviceId): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            'SELECT MAX(time) FROM telemetry.telemetry_points WHERE device_id = :device_id',
            ['device_id' => $deviceId],
        );
        if (null === $value) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    private static function iso(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        return (new \DateTimeImmutable((string) $value))->format('Y-m-d\TH:i:sP');
    }
}
