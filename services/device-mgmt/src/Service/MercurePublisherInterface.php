<?php

declare(strict_types=1);

namespace App\Service;

interface MercurePublisherInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function publish(string $topic, array $data): void;
}
