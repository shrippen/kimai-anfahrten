<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\User;
use KimaiPlugin\MileageBundle\Service\DawarichClient;
use KimaiPlugin\MileageBundle\Service\DawarichException;
use KimaiPlugin\MileageBundle\Service\DistanceCalculator;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DawarichClientTest extends TestCase
{
    private function user(): User
    {
        $user = new User(1);
        $user->setPreferenceValue(MileageConfiguration::PREF_DAWARICH_URL, 'https://dawarich.test/');
        $user->setPreferenceValue(MileageConfiguration::PREF_DAWARICH_API_KEY, 'secret');

        return $user;
    }

    private function client(MockHttpClient $http): DawarichClient
    {
        return new DawarichClient($http, new MileageConfiguration(new SystemConfiguration()), new DistanceCalculator());
    }

    public function testFetchesAllPagesWithBearerToken(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [$url, $options['normalized_headers']['authorization'][0] ?? null];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $page = (int) $query['page'];
            $body = $page === 1
                ? [['latitude' => '52.0', 'longitude' => '13.00', 'timestamp' => 1000, 'accuracy' => 5]]
                : [['lonlat' => 'POINT (13.01 52.0)', 'timestamp' => 1600]];

            return new MockResponse(json_encode($body), ['response_headers' => ['X-Total-Pages' => '2']]);
        });

        $from = new \DateTimeImmutable('2026-01-01 08:00:00+01:00');
        $points = $this->client($http)->fetchPoints($this->user(), $from, $from->modify('+1 hour'));

        self::assertCount(2, $points);
        self::assertCount(2, $requests);
        self::assertStringStartsWith('https://dawarich.test/api/v1/points?', $requests[0][0]);
        self::assertStringContainsString('start_at=2026-01-01T08:00:00%2B01:00', $requests[0][0]);
        self::assertSame('Authorization: Bearer secret', $requests[0][1]);
        self::assertSame(13.01, $points[1]->longitude);
        self::assertSame(5.0, $points[0]->accuracy);
    }

    public function testSkipsMalformedPoints(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            ['latitude' => 'abc', 'longitude' => '13', 'timestamp' => 1],
            ['latitude' => '52', 'longitude' => '13', 'timestamp' => '2026-01-01T08:00:00Z'],
            'garbage',
        ])));

        $from = new \DateTimeImmutable('2026-01-01 08:00');
        $points = $this->client($http)->fetchPoints($this->user(), $from, $from->modify('+1 hour'));

        self::assertCount(1, $points);
        self::assertSame(strtotime('2026-01-01T08:00:00Z'), $points[0]->timestamp);
    }

    public function testNotConfigured(): void
    {
        $this->expectException(DawarichException::class);
        $this->expectExceptionMessage('dawarich.error.not_configured');

        $from = new \DateTimeImmutable();
        $this->client(new MockHttpClient())->fetchPoints(new User(2), $from, $from->modify('+1 hour'));
    }

    public function testUnauthorized(): void
    {
        $this->expectException(DawarichException::class);
        $this->expectExceptionMessage('dawarich.error.unauthorized');

        $from = new \DateTimeImmutable();
        $this->client(new MockHttpClient(new MockResponse('{}', ['http_code' => 401])))
            ->fetchPoints($this->user(), $from, $from->modify('+1 hour'));
    }

    public function testRejectsInvertedWindow(): void
    {
        $this->expectException(DawarichException::class);

        $from = new \DateTimeImmutable();
        $this->client(new MockHttpClient())->fetchPoints($this->user(), $from, $from->modify('-1 hour'));
    }

    public function testSystemUrlIsFallback(): void
    {
        $user = new User(3);
        $user->setPreferenceValue(MileageConfiguration::PREF_DAWARICH_API_KEY, 'k');
        $config = new MileageConfiguration(new SystemConfiguration(['mileage.dawarich_url' => 'https://system.test']));

        self::assertSame('https://system.test', $config->getDawarichUrl($user));
        self::assertTrue($config->isDawarichConfigured($user));
    }

    public function testConnectionReportsPointCount(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([['latitude' => 1, 'longitude' => 2, 'timestamp' => 3]]), [
            'response_headers' => ['X-Total-Pages' => '4711'],
        ]));

        self::assertSame(4711, $this->client($http)->testConnection($this->user()));
    }
}
