<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\User;
use KimaiPlugin\MileageBundle\Service\DawarichClient;
use KimaiPlugin\MileageBundle\Service\DawarichException;
use KimaiPlugin\MileageBundle\Service\DawarichKeyStore;
use KimaiPlugin\MileageBundle\Service\DistanceCalculator;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\TrackAnalyzer;
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
        return new DawarichClient($http, new MileageConfiguration(new SystemConfiguration(['mileage.dawarich_user_url' => true]), self::keys([1 => 'secret'])), new TrackAnalyzer(new DistanceCalculator(), new TransportModeFilter()));
    }

    public function testNotConfigured(): void
    {
        $this->expectException(DawarichException::class);
        $this->expectExceptionMessage('mileage.dawarich.error.not_configured');

        $from = new \DateTimeImmutable();
        $this->client(new MockHttpClient())->fetchTracks(new User(2), $from, $from->modify('+1 hour'));
    }

    public function testUnauthorized(): void
    {
        $this->expectException(DawarichException::class);
        $this->expectExceptionMessage('mileage.dawarich.error.unauthorized');

        $from = new \DateTimeImmutable();
        $this->client(new MockHttpClient(new MockResponse('{}', ['http_code' => 401])))
            ->fetchTracks($this->user(), $from, $from->modify('+1 hour'));
    }

    public function testRejectsInvertedWindow(): void
    {
        $this->expectException(DawarichException::class);

        $from = new \DateTimeImmutable();
        $this->client(new MockHttpClient())->fetchTracks($this->user(), $from, $from->modify('-1 hour'));
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

    public function testConnectionReportsTrackCount(): void
    {
        $url = null;
        $http = new MockHttpClient(function (string $method, string $u) use (&$url) {
            $url = $u;

            return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => [self::feature(1, 0, 60)]]), [
                'response_headers' => ['X-Total-Count' => '42', 'X-Total-Pages' => '42'],
            ]);
        });

        self::assertSame(42, $this->client($http)->testConnection($this->user()));
        self::assertStringStartsWith('https://dawarich.test/api/v1/tracks?', (string) $url);
        self::assertStringContainsString('per_page=1', (string) $url);
    }

    /**
     * A feature as Tracks::GeojsonSerializer renders it (the index has no "segments", the show action has them).
     *
     * @param list<array<string, mixed>>|null $segments
     * @return array<string, mixed>
     */
    private static function feature(int $id, int $start, int $end, ?array $segments = null): array
    {
        $properties = [
            'id' => $id,
            'color' => '#6366F1',
            'start_at' => gmdate('Y-m-d\\TH:i:s\\Z', $start),
            'end_at' => gmdate('Y-m-d\\TH:i:s\\Z', $end),
            'distance' => 12345,
            'avg_speed' => 40.5,
            'duration' => $end - $start,
            'revision' => 0,
            'dominant_mode' => 'driving',
            'dominant_mode_emoji' => '🚗',
            'mode_timeline' => [],
        ];
        if ($segments !== null) {
            $properties['segments'] = $segments;
        }

        return ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[13.0, 52.0], [13.2, 52.1]]], 'properties' => $properties];
    }

    public function testFetchesTracksWithSegments(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$urls) {
            $urls[] = [$url, $options['normalized_headers']['authorization'][0] ?? null];
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if ($path === '/api/v1/tracks') {
                // newest first, two pages
                $features = (int) $query['page'] === 1 ? [self::feature(9, 5000, 6000)] : [self::feature(7, 1000, 2000)];

                return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => $features]), ['response_headers' => ['X-Total-Pages' => '2']]);
            }
            if ($path === '/api/v1/tracks/9') {
                return new MockResponse('{"error":"not found"}', ['http_code' => 404]); // deleted meanwhile
            }

            return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => [self::feature(7, 1000, 2000, [
                ['id' => 2, 'mode' => 'walking', 'start_index' => null, 'end_index' => null, 'coordinates' => null, 'distance' => 300, 'duration' => 400, 'avg_speed' => 2.7, 'confidence' => 'high', 'start_time' => 1600, 'end_time' => 2000],
                ['id' => 1, 'mode' => 'driving', 'start_index' => null, 'end_index' => null, 'coordinates' => [[13.0, 52.0], [13.1, 52.05]], 'distance' => 12045, 'duration' => 600, 'avg_speed' => 72.3, 'confidence' => 'high', 'start_time' => 1000, 'end_time' => 1600],
                ['mode' => 'unknown'], // legacy segment without times
            ])]]));
        });

        $from = new \DateTimeImmutable('2026-01-01 00:00');
        $tracks = $this->client($http)->fetchTracks($this->user(), $from, $from->modify('+1 day'));

        self::assertCount(1, $tracks);
        $track = $tracks[0];
        self::assertSame(7, $track->id);
        self::assertSame(1000, $track->start);
        self::assertSame(12345, $track->distance);
        self::assertSame('driving', $track->dominantMode);
        self::assertCount(2, $track->path);
        self::assertSame(52.1, $track->path[1]->latitude);
        self::assertCount(2, $track->segments);
        self::assertSame('driving', $track->segments[0]->mode, 'sorted by time');
        self::assertSame(12045, $track->segments[0]->distance);
        self::assertSame(13.1, $track->segments[0]->path[1]->longitude);
        self::assertSame([], $track->segments[1]->path);
        self::assertStringContainsString('start_at=2026-01-01T00:00:00', $urls[0][0]);
        self::assertSame('Authorization: Bearer secret', $urls[0][1]);
        self::assertStringEndsWith('/api/v1/tracks/9', $urls[2][0], 'show without start_at/end_at (clipped tracks have no segments)');
    }

    public function testOlderDawarichWithoutTracksApi(): void
    {
        $http = new MockHttpClient(new MockResponse('Not Found', ['http_code' => 404]));
        $from = new \DateTimeImmutable('2026-01-01 00:00');

        $this->expectException(DawarichException::class);
        $this->expectExceptionMessage('mileage.dawarich.error.no_tracks_api');
        $this->client($http)->fetchTracks($this->user(), $from, $from->modify('+1 day'));
    }

    public function testMeasureWithoutTracks(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => []])));
        $from = new \DateTimeImmutable('2026-01-01 00:00');

        $this->expectException(DawarichException::class);
        $this->expectExceptionMessage('mileage.dawarich.error.no_tracks');
        $this->client($http)->measureDistance($this->user(), $from, $from->modify('+1 day'));
    }

    public function testMeasureUsesTheDrivenSegments(): void
    {
        $http = new MockHttpClient(function (string $method, string $url) {
            $segments = (string) parse_url($url, PHP_URL_PATH) === '/api/v1/tracks' ? null : [
                ['mode' => 'driving', 'distance' => 12045, 'coordinates' => [[13.0, 52.0], [13.1, 52.05]], 'start_time' => 1000, 'end_time' => 1600],
                ['mode' => 'walking', 'distance' => 300, 'coordinates' => null, 'start_time' => 1600, 'end_time' => 2000],
            ];

            return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => [self::feature(7, 1000, 2000, $segments)]]));
        });

        $result = $this->client($http)->measureDistance($this->user(), new \DateTimeImmutable('@0'), new \DateTimeImmutable('@3000'));

        self::assertSame(12.0, $result->distanceKm);
        self::assertSame(1, $result->segmentCount);
        self::assertSame(2, $result->pointCount);
    }

    public function testReverseGeocodingUsesDawarichNearbyPlaces(): void
    {
        $url = null;
        $http = new MockHttpClient(function (string $method, string $u) use (&$url) {
            $url = $u;

            // Places::PhotonResultFormatter
            return new MockResponse(json_encode(['places' => [[
                'id' => null, 'name' => 'Bäckerei Schmidt', 'latitude' => 52.52, 'longitude' => 13.405, 'osm_id' => 1,
                'city' => 'Berlin', 'country' => 'Germany', 'street' => 'Unter den Linden', 'housenumber' => '5', 'postcode' => '10117', 'source' => 'photon',
            ]]]));
        });

        self::assertSame('Unter den Linden 5, 10117 Berlin', $this->client($http)->reverseGeocode($this->user(), 52.52, 13.405));
        self::assertStringStartsWith('https://dawarich.test/api/v1/places/nearby?', (string) $url);
        self::assertStringContainsString('radius=0.1', (string) $url);

        // a POI without street, no geocoder in Dawarich, errors
        $poi = new MockHttpClient(new MockResponse(json_encode(['places' => [['name' => 'Stadtpark', 'city' => 'Hamburg']]])));
        self::assertSame('Stadtpark, Hamburg', $this->client($poi)->reverseGeocode($this->user(), 1, 2));
        self::assertNull($this->client(new MockHttpClient(new MockResponse('{"places":[]}')))->reverseGeocode($this->user(), 1, 2));
        self::assertNull($this->client(new MockHttpClient(new MockResponse('', ['http_code' => 500])))->reverseGeocode($this->user(), 1, 2));
    }

    public function testFetchesPlaces(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            ['id' => 3, 'name' => 'Kunde Nord', 'latitude' => 53.1, 'longitude' => 9.9, 'source' => 'manual', 'visits_count' => 4, 'tags' => []],
            ['id' => 4, 'name' => '', 'latitude' => 1, 'longitude' => 2],
        ])));

        self::assertSame([['id' => 3, 'name' => 'Kunde Nord', 'latitude' => 53.1, 'longitude' => 9.9]], $this->client($http)->fetchPlaces($this->user()));
        self::assertSame([], $this->client(new MockHttpClient(new MockResponse('', ['http_code' => 404])))->fetchPlaces($this->user()));
    }
}
