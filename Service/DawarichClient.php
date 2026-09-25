<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal client for the Dawarich REST API (https://dawarich.app).
 *
 * Uses GET /api/v1/points?start_at=…&end_at=… (paginated) with the user's API key
 * (Dawarich → Account → API key).
 */
class DawarichClient
{
    private const PER_PAGE = 1000;
    private const MAX_PAGES = 50;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MileageConfiguration $configuration,
        private readonly DistanceCalculator $distanceCalculator,
        private readonly TransportModeFilter $modeFilter,
    ) {
    }

    /**
     * @throws DawarichException
     */
    public function measureDistance(User $user, \DateTimeInterface $from, \DateTimeInterface $to): DistanceResult
    {
        $points = $this->fetchPoints($user, $from, $to);
        $excluded = $this->configuration->getExcludedTransportModes();
        if ($excluded === []) {
            return $this->distanceCalculator->calculate($points, $this->configuration->getMaxAccuracy());
        }

        // Walks (e.g. from the car park) are not part of the driven distance.
        $chunks = $this->modeFilter->split($points, $this->fetchTransportSegments($user, $from, $to), $excluded);
        $km = 0.0;
        $used = 0;
        $first = null;
        $last = null;
        foreach ($chunks as $chunk) {
            $result = $this->distanceCalculator->calculate($chunk, $this->configuration->getMaxAccuracy());
            $km += $result->distanceKm;
            $used += $result->usedPointCount;
            $first ??= $result->first;
            $last = $result->last ?? $last;
        }

        return new DistanceResult(round($km, 1), \count($points), $used, $first, $last);
    }

    /**
     * Checks URL and API key with a minimal request.
     *
     * @return int number of GPS points Dawarich knows for the last 30 days
     * @throws DawarichException
     */
    public function testConnection(User $user): int
    {
        $to = new \DateTimeImmutable();
        [, $headers, $count] = $this->request($user, $to->modify('-30 days'), $to, 1, 1);

        return max($count, (int) ($headers['x-total-pages'][0] ?? $count));
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
     * Transportation-mode segments of all tracks touching the window.
     * Returns an empty list when the Dawarich version has no tracks API (older than the
     * transportation-mode feature) or has not computed tracks yet.
     *
     * @return TransportSegment[]
     * @throws DawarichException
     */
    public function fetchTransportSegments(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        try {
            [$index] = $this->get($user, '/api/v1/tracks', [
                'start_at' => $from->format(\DateTimeInterface::ATOM),
                'end_at' => $to->format(\DateTimeInterface::ATOM),
                'per_page' => 100,
            ]);
        } catch (DawarichException $e) {
            if ($e->getMessage() === 'dawarich.error.http' && ($e->getParameters()['%status%'] ?? null) === 404) {
                return [];
            }
            throw $e;
        }

        $segments = [];
        foreach ($index['features'] ?? [] as $feature) {
            $id = $feature['properties']['id'] ?? null;
            if (!is_numeric($id)) {
                continue;
            }
            [$track] = $this->get($user, '/api/v1/tracks/' . (int) $id, []);
            foreach ($track['features'][0]['properties']['segments'] ?? [] as $segment) {
                if (!\is_array($segment) || !isset($segment['mode'], $segment['start_time'], $segment['end_time'])) {
                    continue;
                }
                $segments[] = new TransportSegment((int) $segment['start_time'], (int) $segment['end_time'], (string) $segment['mode']);
            }
        }

        return $segments;
    }

    /**
     * @return GpsPoint[]
     * @throws DawarichException
     */
    public function fetchPoints(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if ($to <= $from) {
            throw new DawarichException('trip.error.arrival_before_departure');
        }

        $points = [];
        $page = 1;

        do {
            [$data, $headers] = $this->request($user, $from, $to, $page, self::PER_PAGE);

            foreach ($data as $row) {
                $point = \is_array($row) ? $this->parsePoint($row) : null;
                if ($point !== null) {
                    $points[] = $point;
                }
            }

            $totalPages = (int) ($headers['x-total-pages'][0] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= self::MAX_PAGES && \count($data) > 0);

        return $points;
    }

    /**
     * @return array{0: array<mixed>, 1: array<string, list<string>>, 2: int}
     * @throws DawarichException
     */
    private function request(User $user, \DateTimeInterface $from, \DateTimeInterface $to, int $page, int $perPage): array
    {
        return $this->get($user, '/api/v1/points', [
            'start_at' => $from->format(\DateTimeInterface::ATOM),
            'end_at' => $to->format(\DateTimeInterface::ATOM),
            'page' => $page,
            'per_page' => $perPage,
            'order' => 'asc',
        ]);
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
            throw new DawarichException('dawarich.error.not_configured');
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
                throw new DawarichException('dawarich.error.unauthorized');
            }
            if ($status >= 400) {
                throw new DawarichException('dawarich.error.http', ['%status%' => $status]);
            }

            $data = $response->toArray(false);
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface $e) {
            throw new DawarichException('dawarich.error.connection', ['%message%' => $e->getMessage()], $e);
        }

        return [$data, $headers, \count($data)];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function parsePoint(array $row): ?GpsPoint
    {
        $lat = $row['latitude'] ?? null;
        $lon = $row['longitude'] ?? null;

        // Newer Dawarich versions may only expose PostGIS "lonlat" (e.g. "POINT (13.4 52.5)").
        if (($lat === null || $lon === null) && isset($row['lonlat']) && \is_string($row['lonlat'])
            && preg_match('/POINT\s*\(\s*(-?[\d.]+)\s+(-?[\d.]+)\s*\)/i', $row['lonlat'], $m)) {
            $lon = $m[1];
            $lat = $m[2];
        }

        $timestamp = $row['timestamp'] ?? null;
        if (\is_string($timestamp) && !is_numeric($timestamp)) {
            $timestamp = strtotime($timestamp) ?: null;
        }

        if (!is_numeric($lat) || !is_numeric($lon) || !is_numeric($timestamp)) {
            return null;
        }

        $accuracy = $row['accuracy'] ?? null;

        return new GpsPoint(
            (float) $lat,
            (float) $lon,
            (int) $timestamp,
            is_numeric($accuracy) ? (float) $accuracy : null,
        );
    }
}
