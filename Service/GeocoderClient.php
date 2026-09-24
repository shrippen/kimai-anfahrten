<?php

namespace KimaiPlugin\MileageBundle\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Optional reverse geocoding (coordinates → address) against a Nominatim- or Photon-compatible server.
 * Disabled unless a URL is configured; failures never break trip detection.
 */
class GeocoderClient
{
    /** @var array<string, ?string> */
    private array $cache = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MileageConfiguration $configuration,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->configuration->getGeocoderUrl() !== null;
    }

    public function reverse(float $latitude, float $longitude): ?string
    {
        $baseUrl = $this->configuration->getGeocoderUrl();
        if ($baseUrl === null) {
            return null;
        }

        // ~10 m grid, so points of the same parking spot share one request.
        $key = \sprintf('%.4f,%.4f', $latitude, $longitude);
        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            $response = $this->httpClient->request('GET', $baseUrl . '/reverse', [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Kimai-MileageBundle (+https://github.com/shrippen/kimai-anfahrten)',
                ],
                'query' => [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'format' => 'jsonv2',
                    'zoom' => 18,
                    'addressdetails' => 1,
                ],
                'timeout' => 10,
            ]);

            $label = $response->getStatusCode() < 400 ? self::parse($response->toArray(false)) : null;
        } catch (ExceptionInterface) {
            $label = null;
        }

        return $this->cache[$key] = $label;
    }

    /**
     * Understands Nominatim (jsonv2) and Photon (GeoJSON) responses.
     *
     * @param array<mixed> $data
     */
    public static function parse(array $data): ?string
    {
        $address = null;
        if (isset($data['address']) && \is_array($data['address'])) {
            $address = $data['address'];
        } elseif (isset($data['features'][0]['properties']) && \is_array($data['features'][0]['properties'])) {
            $address = $data['features'][0]['properties'];
        }

        if ($address !== null) {
            $street = trim(($address['road'] ?? $address['street'] ?? '') . ' ' . ($address['house_number'] ?? $address['housenumber'] ?? ''));
            $city = trim(($address['postcode'] ?? '') . ' ' . ($address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? ''));
            $label = implode(', ', array_filter([$street, $city], static fn (string $part) => $part !== ''));
            if ($label === '' && isset($address['name']) && \is_string($address['name'])) {
                $label = $address['name'];
            }
            if ($label !== '') {
                return $label;
            }
        }

        return isset($data['display_name']) && \is_string($data['display_name']) ? $data['display_name'] : null;
    }
}
