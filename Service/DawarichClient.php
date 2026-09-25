<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal client for the Dawarich REST API (https://dawarich.app) with the user's API key
 * (Dawarich → Account → API key).
 *
 * Trips and distances come from the tracks Dawarich computes (GET /api/v1/tracks, GET /api/v1/tracks/{id} with the
 * transportation-mode segments); Dawarich versions without tracks are not supported.
 */
class DawarichClient
{
    private const PER_PAGE = 100;
    private const MAX_PAGES = 50;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MileageConfiguration $configuration,
        private readonly TrackAnalyzer $trackAnalyzer,
    ) {
    }

    /**
     * Driven distance in the window (walks, bike rides and parking left out).
     *
     * @throws DawarichException also when Dawarich has no tracks for the window
     */
    public function measureDistance(User $user, \DateTimeInterface $from, \DateTimeInterface $to): DistanceResult
    {
        return $this->trackAnalyzer->measure(
            $this->fetchTracksOrFail($user, $from, $to),
            $from->getTimestamp(),
            $to->getTimestamp(),
            $this->configuration->getExcludedTransportModes(),
            $this->configuration->getDetectStopMinutes() * 60,
        );
    }

    /**
     * Lines of the driven segments in the window, for the map preview.
     *
     * @return list<list<GpsPoint>>
     * @throws DawarichException
     */
    public function fetchLines(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->trackAnalyzer->lines(
            $this->fetchTracks($user, $from, $to),
            $from->getTimestamp(),
            $to->getTimestamp(),
            $this->configuration->getExcludedTransportModes(),
            $this->configuration->getDetectStopMinutes() * 60,
        );
    }

    /**
     * Checks URL, API key and the tracks API with a minimal request.
     *
     * @return int number of tracks Dawarich has for the last 30 days
     * @throws DawarichException
     */
    public function testConnection(User $user): int
    {
        $to = new \DateTimeImmutable();
        [$data, $headers] = $this->getTracksPage($user, $to->modify('-30 days'), $to, 1, 1);

        return (int) ($headers['x-total-count'][0] ?? max(\count($data['features'] ?? []), (int) ($headers['x-total-pages'][0] ?? 0)));
    }

    /**
     * Named areas the user drew in Dawarich.
     *
     * @return array<int, array{id: int, name: string, latitude: float, longitude: float, radius: int}>
     * @throws DawarichException
     */
    public function fetchAreas(User $user): array
    {
        $areas = [];
        foreach ($this->get($user, '/api/v1/areas', [])[0] as $row) {
            if (!\is_array($row) || !isset($row['id'], $row['name']) || !is_numeric($row['latitude'] ?? null) || !is_numeric($row['longitude'] ?? null)) {
                continue;
            }
            $areas[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'radius' => is_numeric($row['radius'] ?? null) ? (int) $row['radius'] : 100,
            ];
        }

        return $areas;
    }

    /**
     * Tracks touching the window, oldest first, each with its transportation-mode segments.
     *
     * @return list<DawarichTrack>
     * @throws DawarichException "no tracks API" when the Dawarich version is too old
     */
    public function fetchTracks(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if ($to <= $from) {
            throw new DawarichException('mileage.trip.error.arrival_before_departure');
        }

        $ids = [];
        $page = 1;
        do {
            [$data, $headers] = $this->getTracksPage($user, $from, $to, $page, self::PER_PAGE);
            $features = \is_array($data['features'] ?? null) ? $data['features'] : [];
            foreach ($features as $feature) {
                $id = $feature['properties']['id'] ?? null;
                if (is_numeric($id)) {
                    $ids[(int) $id] = true;
                }
            }
            $totalPages = (int) ($headers['x-total-pages'][0] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= self::MAX_PAGES && $features !== []);

        $tracks = [];
        foreach (array_keys($ids) as $id) {
            try {
                // without start_at/end_at: a clipped track comes without segments
                [$data] = $this->get($user, '/api/v1/tracks/' . $id, []);
            } catch (DawarichException $e) {
                if (self::isNotFound($e)) {
                    continue; // deleted or merged in the meantime
                }
                throw $e;
            }
            $track = \is_array($data['features'][0] ?? null) ? $this->parseTrack($data['features'][0]) : null;
            if ($track !== null) {
                $tracks[] = $track;
            }
        }
        usort($tracks, static fn (DawarichTrack $a, DawarichTrack $b) => $a->start <=> $b->start);

        return $tracks;
    }

    /**
     * @return list<DawarichTrack>
     * @throws DawarichException
     */
    public function fetchTracksOrFail(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $tracks = $this->fetchTracks($user, $from, $to);
        if ($tracks === []) {
            throw new DawarichException('mileage.dawarich.error.no_tracks');
        }

        return $tracks;
    }

    /**
     * @return array{0: array<mixed>, 1: array<string, list<string>>, 2: int}
     * @throws DawarichException
     */
    private function getTracksPage(User $user, \DateTimeInterface $from, \DateTimeInterface $to, int $page, int $perPage): array
    {
        try {
            return $this->get($user, '/api/v1/tracks', [
                'start_at' => $from->format(\DateTimeInterface::ATOM),
                'end_at' => $to->format(\DateTimeInterface::ATOM),
                'page' => $page,
                'per_page' => $perPage,
            ]);
        } catch (DawarichException $e) {
            if (self::isNotFound($e)) {
                throw new DawarichException('mileage.dawarich.error.no_tracks_api', [], $e);
            }
            throw $e;
        }
    }

    private static function isNotFound(DawarichException $e): bool
    {
        return $e->getMessage() === 'mileage.dawarich.error.http' && ($e->getParameters()['%status%'] ?? null) === 404;
    }

    /**
     * @param array<string, string|int> $query
     * @return array{0: array<mixed>, 1: array<string, list<string>>, 2: int}
     * @throws DawarichException
     */
    private function get(User $user, string $path, array $query): array
    {
        $baseUrl = $this->configuration->getDawarichUrl($user);
        $apiKey = $this->configuration->getDawarichApiKey($user);

        if ($baseUrl === null || $apiKey === null) {
            throw new DawarichException('mileage.dawarich.error.not_configured');
        }

        try {
            $response = $this->httpClient->request('GET', $baseUrl . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                ],
                'query' => $query,
                'timeout' => 20,
                // never follow a redirect to another (internal) address
                'max_redirects' => 0,
            ]);

            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                throw new DawarichException('mileage.dawarich.error.unauthorized');
            }
            if ($status >= 400) {
                throw new DawarichException('mileage.dawarich.error.http', ['%status%' => $status]);
            }

            $data = $response->toArray(false);
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface $e) {
            throw new DawarichException('mileage.dawarich.error.connection', ['%message%' => $e->getMessage()], $e);
        }

        return [$data, $headers, \count($data)];
    }

    /**
     * GeoJSON feature of GET /api/v1/tracks/{id} (Tracks::GeojsonSerializer): times of the track as ISO 8601,
     * distances in metres, segment times as Unix timestamps, coordinates as [longitude, latitude].
     *
     * @param array<mixed> $feature
     */
    private function parseTrack(array $feature): ?DawarichTrack
    {
        $properties = \is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $start = self::timestamp($properties['start_at'] ?? null);
        $end = self::timestamp($properties['end_at'] ?? null);
        if (!is_numeric($properties['id'] ?? null) || $start === null || $end === null) {
            return null;
        }

        $segments = [];
        foreach (\is_array($properties['segments'] ?? null) ? $properties['segments'] : [] as $row) {
            if (!\is_array($row) || !\is_string($row['mode'] ?? null) || !is_numeric($row['start_time'] ?? null) || !is_numeric($row['end_time'] ?? null)) {
                continue;
            }
            $segments[] = new TransportSegment(
                (int) $row['start_time'],
                (int) $row['end_time'],
                $row['mode'],
                is_numeric($row['distance'] ?? null) ? (int) round((float) $row['distance']) : null,
                self::line($row['coordinates'] ?? null),
            );
        }
        usort($segments, static fn (TransportSegment $a, TransportSegment $b) => $a->start <=> $b->start);

        $geometry = \is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];

        return new DawarichTrack(
            (int) $properties['id'],
            $start,
            $end,
            is_numeric($properties['distance'] ?? null) ? (int) round((float) $properties['distance']) : 0,
            \is_string($properties['dominant_mode'] ?? null) ? $properties['dominant_mode'] : null,
            $segments,
            ($geometry['type'] ?? null) === 'LineString' ? self::line($geometry['coordinates'] ?? null) : [],
        );
    }

    /**
     * @return list<GpsPoint>
     */
    private static function line(mixed $coordinates): array
    {
        $line = [];
        foreach (\is_array($coordinates) ? $coordinates : [] as $pair) {
            if (\is_array($pair) && is_numeric($pair[0] ?? null) && is_numeric($pair[1] ?? null)) {
                $line[] = new GpsPoint((float) $pair[1], (float) $pair[0]);
            }
        }

        return $line;
    }

    private static function timestamp(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return \is_string($value) && ($time = strtotime($value)) !== false ? $time : null;
    }
}
