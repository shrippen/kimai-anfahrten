<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Enum\PlaceType;
use KimaiPlugin\MileageBundle\Repository\PlaceRepository;
use KimaiPlugin\MileageBundle\Service\DawarichClient;
use KimaiPlugin\MileageBundle\Service\DistanceCalculator;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\OvernightVisitChecker;
use KimaiPlugin\MileageBundle\Service\TrackAnalyzer;
use KimaiPlugin\MileageBundle\Service\TransportModeFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OvernightVisitCheckerTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $visits as Api::VisitSerializer renders them
     */
    private function check(array $visits): ?bool
    {
        $user = new User(1);
        $user->setPreferenceValue(MileageConfiguration::PREF_DAWARICH_URL, 'https://dawarich.test');
        $user->setPreferenceValue('timezone', 'Europe/Berlin');
        $config = new MileageConfiguration(new SystemConfiguration(['mileage.dawarich_user_url' => true]), DawarichClientTest::keys([1 => 'k']));
        $client = new DawarichClient(new MockHttpClient(new MockResponse(json_encode($visits))), $config, new TrackAnalyzer(new DistanceCalculator(), new TransportModeFilter()));
        $checker = new OvernightVisitChecker($client, $config, $this->createMock(PlaceRepository::class), new DistanceCalculator());

        $leg = (new Trip())->setDestination('Hamburg')->setEndCoordinates(53.54, 9.98);
        $home = (new Place())->setType(PlaceType::HOME)->setLatitude(52.52)->setLongitude(13.405);

        return $checker->check($user, $leg, new \DateTimeImmutable('2026-03-02 12:00'), new \DateTimeImmutable('2026-03-03 14:00'), [$home]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function visit(string $from, string $to, float $lat, float $lon, string $status = 'confirmed'): array
    {
        return ['id' => 1, 'area_id' => null, 'user_id' => 1, 'started_at' => $from, 'ended_at' => $to, 'duration' => 60, 'name' => 'x',
            'status' => $status, 'confidence' => 80, 'confidence_band' => 'high', 'place' => ['latitude' => $lat, 'longitude' => $lon, 'id' => 5]];
    }

    public function testVisitOverMidnight(): void
    {
        // hotel next to the customer, over night
        self::assertTrue($this->check([self::visit('2026-03-02T18:00:00.000+01:00', '2026-03-03T08:00:00.000+01:00', 53.5405, 9.98)]));
        // the night at home
        self::assertFalse($this->check([self::visit('2026-03-02T20:00:00.000+01:00', '2026-03-03T07:00:00.000+01:00', 52.52, 13.405)]));
        // only day visits, a declined one, nothing
        self::assertNull($this->check([self::visit('2026-03-02T13:00:00.000+01:00', '2026-03-02T17:00:00.000+01:00', 53.54, 9.98)]));
        self::assertNull($this->check([self::visit('2026-03-02T20:00:00.000+01:00', '2026-03-03T07:00:00.000+01:00', 52.52, 13.405, 'declined')]));
        self::assertNull($this->check([]));
    }
}
