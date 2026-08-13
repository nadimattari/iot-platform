<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Reads Insight rollups from the TimescaleDB continuous aggregates
 * (`telemetry_1m`/`telemetry_1h`/`telemetry_1d`) maintained by the ingestion
 * pipeline. These views live in the `telemetry` schema, outside the ORM, so
 * reads go through the shared DBAL connection as raw SQL.
 */
final class InsightsReader
{
    /** Map of API bucket keys to the continuous aggregate views. */
    public const BUCKETS = [
        '1m' => 'telemetry_1m',
        '1h' => 'telemetry_1h',
        '1d' => 'telemetry_1d',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Per-field summary across every device in a group, rolled up from the 1d
     * continuous aggregate.
     *
     * @return list<array{field: string, min: float, max: float, avg: float, count: int}>
     */
    public function groupSummary(string $groupId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT d.field,
       MIN(d.min)                                   AS min,
       MAX(d.max)                                   AS max,
       SUM(d.avg * d.count) / NULLIF(SUM(d.count), 0) AS avg,
       SUM(d.count)                                 AS count
FROM telemetry.telemetry_1d d
WHERE d.device_id IN (SELECT id FROM devices WHERE group_id = :group_id)
  AND d.bucket >= :from
  AND d.bucket < :to
GROUP BY d.field
ORDER BY d.field
SQL,
            [
                'group_id' => $groupId,
                'from' => $from->format('Y-m-d\TH:i:s.uP'),
                'to' => $to->format('Y-m-d\TH:i:s.uP'),
            ],
        );

        return array_map(
            static fn (array $row): array => [
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
     * Bucketed series for a device, read from the requested continuous aggregate.
     *
     * @return list<array{bucket: string, field: string, min: float, max: float, avg: float, count: int}>
     */
    public function timeseries(string $deviceId, \DateTimeInterface $from, \DateTimeInterface $to, string $bucket): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT bucket, field, min, max, avg, count
                 FROM telemetry.%s
                 WHERE device_id = :device_id
                   AND bucket >= :from
                   AND bucket < :to
                 ORDER BY bucket, field',
                self::BUCKETS[$bucket],
            ),
            [
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

    private static function iso(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        return (new \DateTimeImmutable((string) $value))->format('Y-m-d\TH:i:sP');
    }
}
