<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthIsPublic(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/health');

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","service":"device-mgmt"}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
