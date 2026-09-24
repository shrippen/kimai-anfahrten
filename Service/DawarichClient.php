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
    ) {
    }

    /**
     * @throws DawarichException
     */
    public function measureDistance(User $user, \DateTimeInterface $from, \DateTimeInterface $to): DistanceResult
    {
        return $this->distanceCalculator->calculate(
            $this->fetchPoints($user, $from, $to),
            $this->configuration->getMaxAccuracy()
        );
    }

    /**
     * @return GpsPoint[]
     * @throws DawarichException
     */
    public function fetchPoints(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $baseUrl = $this->configuration->getDawarichUrl($user);
        $apiKey = $this->configuration->getDawarichApiKey($user);

        if ($baseUrl === null || $apiKey === null) {
            throw new DawarichException('dawarich.error.not_configured');
        }

        if ($to <= $from) {
            throw new DawarichException('trip.error.arrival_before_departure');
        }

        $points = [];
        $page = 1;

        do {
            try {
                $response = $this->httpClient->request('GET', $baseUrl . '/api/v1/points', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Accept' => 'application/json',
                    ],
                    'query' => [
                        'start_at' => $from->format(\DateTimeInterface::ATOM),
                        'end_at' => $to->format(\DateTimeInterface::ATOM),
                        'page' => $page,
                        'per_page' => self::PER_PAGE,
                        'order' => 'asc',
                    ],
                    'timeout' => 20,
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
