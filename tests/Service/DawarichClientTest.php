<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\User;
use KimaiPlugin\MileageBundle\Service\DawarichClient;
use KimaiPlugin\MileageBundle\Service\DawarichException;
use KimaiPlugin\MileageBundle\Service\DawarichKeyStore;
use KimaiPlugin\MileageBundle\Service\DistanceCalculator;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\TransportModeFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DawarichClientTest extends TestCase
{
    private function user(): User
    {
        $user = new User(1);
        $user->setPreferenceValue(MileageConfiguration::PREF_DAWARICH_URL, 'https://dawarich.test/');

        return $user;
    }

    /**
     * @param array<int, string> $keys
     */
    public static function keys(array $keys): DawarichKeyStore
    {
        return new class($keys) implements DawarichKeyStore {
            /** @param array<int, string> $keys */
            public function __construct(public array $keys)
            {
            }

            public function getDawarichApiKey(User $user): ?string
            {
                return $this->keys[(int) $user->getId()] ?? null;
            }

            public function setDawarichApiKey(User $user, ?string $key): void
            {
                $this->keys[(int) $user->getId()] = (string) $key;
            }
        };
    }

    private function client(MockHttpClient $http): DawarichClient
    {
        return new DawarichClient($http, new MileageConfiguration(new SystemConfiguration(['mileage.dawarich_user_url' => true]), self::keys([1 => 'secret'])), new DistanceCalculator(), new TransportModeFilter());
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
        $this->expectExceptionMessage('mileage.dawarich.error.not_configured');

        $from = new \DateTimeImmutable();
        $this->client(new MockHttpClient())->fetchPoints(new User(2), $from, $from->modify('+1 hour'));
    }

    public function testUnauthorized(): void
    {
        $this->expectException(DawarichException::class);
        $this->expectExceptionMessage('mileage.dawarich.error.unauthorized');

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
        $config = new MileageConfiguration(new SystemConfiguration(['mileage.dawarich_url' => 'https://system.test']), self::keys([3 => 'k']));

        self::assertSame('https://system.test', $config->getDawarichUrl($user));
        self::assertTrue($config->isDawarichConfigured($user));

        // the key is only read from the key store, never from the user preferences (they are public via the user API)
        $user->setPreferenceValue(MileageConfiguration::PREF_DAWARICH_API_KEY, 'old');
        $withoutKey = new MileageConfiguration(new SystemConfiguration(['mileage.dawarich_url' => 'https://system.test']), self::keys([]));
        self::assertNull($withoutKey->getDawarichApiKey($user));
        self::assertFalse($withoutKey->isDawarichConfigured($user));
    }

    public function testPersonalUrlNeedsTheSystemSetting(): void
    {
        $user = $this->user();
        $user->setPreferenceValue(MileageConfiguration::PREF_DAWARICH_URL, 'http://db:3306');

        // SSRF: without the setting the personal URL is ignored and the system URL is used
        $config = new MileageConfiguration(new SystemConfiguration(['mileage.dawarich_url' => 'https://system.test']));
        self::assertFalse($config->isUserDawarichUrlAllowed());
        self::assertSame('https://system.test', $config->getDawarichUrl($user));
        self::assertNull((new MileageConfiguration(new SystemConfiguration()))->getDawarichUrl($user));

        $allowed = new MileageConfiguration(new SystemConfiguration(['mileage.dawarich_user_url' => '1']));
        self::assertSame('http://db:3306', $allowed->getDawarichUrl($user));
    }

    public function testOnlyHttpUrlsAreUsed(): void
    {
        self::assertSame('https://d.test/sub', MileageConfiguration::httpUrl('https://d.test/sub/'));
        self::assertNull(MileageConfiguration::httpUrl('file:///etc/passwd'));
        self::assertNull(MileageConfiguration::httpUrl('gopher://d.test'));
        self::assertNull(MileageConfiguration::httpUrl('d.test'));
        self::assertNull(MileageConfiguration::httpUrl('https://user:pw@d.test'));
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $options = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $o) use (&$options) {
            $options = $o;

            return new MockResponse('[]');
        });
        $this->client($http)->testConnection($this->user());

        self::assertSame(0, $options['max_redirects'] ?? null);
    }

    public function testConnectionReportsPointCount(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([['latitude' => 1, 'longitude' => 2, 'timestamp' => 3]]), [
            'response_headers' => ['X-Total-Pages' => '4711'],
        ]));

        self::assertSame(4711, $this->client($http)->testConnection($this->user()));
    }

    public function testFetchesTransportSegmentsOfAllTracks(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls) {
            $urls[] = $url;
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path === '/api/v1/tracks') {
                return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => [
                    ['properties' => ['id' => 7, 'dominant_mode' => 'driving']],
                    ['properties' => ['id' => 9, 'dominant_mode' => 'walking']],
                ]]));
            }
            $id = (int) basename($path);

            return new MockResponse(json_encode(['features' => [['properties' => ['segments' => [
                ['mode' => $id === 7 ? 'driving' : 'walking', 'start_time' => $id * 100, 'end_time' => $id * 100 + 50],
                ['mode' => 'unknown'], // legacy segment without times
            ]]]]]));
        });

        $from = new \DateTimeImmutable('2026-01-01 00:00');
        $segments = $this->client($http)->fetchTransportSegments($this->user(), $from, $from->modify('+1 day'));

        self::assertCount(2, $segments);
        self::assertSame('driving', $segments[0]->mode);
        self::assertSame(900, $segments[1]->start);
        self::assertStringContainsString('/api/v1/tracks/9', $urls[2]);
    }

    public function testOlderDawarichWithoutTracksApi(): void
    {
        $http = new MockHttpClient(new MockResponse('Not Found', ['http_code' => 404]));
        $from = new \DateTimeImmutable('2026-01-01 00:00');

        self::assertSame([], $this->client($http)->fetchTransportSegments($this->user(), $from, $from->modify('+1 day')));
    }
}
