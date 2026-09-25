<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\TripSuggestion;
use KimaiPlugin\MileageBundle\Enum\PlaceType;
use KimaiPlugin\MileageBundle\Enum\SuggestionStatus;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\VehicleType;
use KimaiPlugin\MileageBundle\Repository\PlaceRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Repository\TripSuggestionRepository;
use KimaiPlugin\MileageBundle\Service\DawarichClient;
use KimaiPlugin\MileageBundle\Service\DetectedTrip;
use KimaiPlugin\MileageBundle\Service\DistanceCalculator;
use KimaiPlugin\MileageBundle\Service\GeocoderClient;
use KimaiPlugin\MileageBundle\Service\GpsPoint;
use KimaiPlugin\MileageBundle\Service\InvalidInputException;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\PlaceMatcher;
use KimaiPlugin\MileageBundle\Service\SuggestionService;
use KimaiPlugin\MileageBundle\Service\TimesheetMatcher;
use KimaiPlugin\MileageBundle\Service\TrackAnalyzer;
use KimaiPlugin\MileageBundle\Service\TransportModeFilter;
use KimaiPlugin\MileageBundle\Service\TripService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

class SuggestionServiceTest extends TestCase
{
    private function service(?TripService $tripService = null, ?TripRepository $tripRepository = null, ?EntityManagerInterface $entityManager = null): SuggestionService
    {
        $config = new MileageConfiguration(new SystemConfiguration());
        $distance = new DistanceCalculator();
        $http = new MockHttpClient();

        return new SuggestionService(
            new DawarichClient($http, $config, new TrackAnalyzer($distance, new TransportModeFilter())),
            new TrackAnalyzer($distance, new TransportModeFilter()),
            new PlaceMatcher($distance),
            new TimesheetMatcher(),
            new GeocoderClient($http, $config),
            $config,
            $this->createMock(PlaceRepository::class),
            $this->createMock(TripSuggestionRepository::class),
            $tripRepository ?? $this->createMock(TripRepository::class),
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $tripService ?? $this->createMock(TripService::class),
        );
    }

    /**
     * @return array<string, array{?PlaceType, ?PlaceType, bool, TripPurpose}>
     */
    public static function purposes(): array
    {
        return [
            'home to work' => [PlaceType::HOME, PlaceType::WORK, false, TripPurpose::COMMUTE],
            'work to home with timesheet' => [PlaceType::WORK, PlaceType::HOME, true, TripPurpose::COMMUTE],
            'home to customer' => [PlaceType::HOME, PlaceType::CUSTOMER, false, TripPurpose::BUSINESS],
            'work to unknown' => [PlaceType::WORK, null, false, TripPurpose::BUSINESS],
            'unknown with timesheet' => [null, null, true, TripPurpose::BUSINESS],
            'home to supermarket' => [PlaceType::HOME, PlaceType::OTHER, false, TripPurpose::PRIVATE],
            'nothing known' => [null, null, false, TripPurpose::PRIVATE],
        ];
    }

    /**
     * @dataProvider purposes
     */
    public function testGuessPurpose(?PlaceType $start, ?PlaceType $end, bool $timesheet, TripPurpose $expected): void
    {
        $place = static fn (?PlaceType $type) => $type === null ? null : (new Place())->setType($type);

        self::assertSame($expected, SuggestionService::guessPurpose($place($start), $place($end), $timesheet));
    }

    public function testGuessPlaceType(): void
    {
        self::assertSame(PlaceType::HOME, SuggestionService::guessPlaceType('Zuhause'));
        self::assertSame(PlaceType::WORK, SuggestionService::guessPlaceType('Büro Mitte'));
        self::assertSame(PlaceType::OTHER, SuggestionService::guessPlaceType('Fitnessstudio'));
    }

    public function testCreateSuggestionUsesPlacesAndTimesheet(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $home = (new Place())->setName('Zuhause')->setType(PlaceType::HOME)->setLatitude(52.52)->setLongitude(13.405);
        $project = new Project('Relaunch', new Customer('ACME'));
        $timesheet = new Timesheet(new \DateTime('2026-03-02 10:00', $tz), new \DateTime('2026-03-02 15:00', $tz), $project);

        $start = new GpsPoint(52.52, 13.405, (new \DateTimeImmutable('2026-03-02 09:05', $tz))->getTimestamp());
        $end = new GpsPoint(52.40, 13.06, (new \DateTimeImmutable('2026-03-02 09:45', $tz))->getTimestamp());

        $places = [$home];
        $suggestion = $this->service()->createSuggestion(new User(1), new DetectedTrip($start, $end, 27.4, 120), $places, [$timesheet], $tz);

        self::assertSame('Zuhause', $suggestion->getStartLabel());
        self::assertSame('52.40000, 13.06000', $suggestion->getEndLabel());
        self::assertSame($home, $suggestion->getStartPlace());
        self::assertSame($timesheet, $suggestion->getTimesheet());
        self::assertSame($project, $suggestion->getProject());
        self::assertSame(TripPurpose::BUSINESS, $suggestion->getPurpose());
        self::assertSame('2026-03-02 09:05', $suggestion->getStartAt()->format('Y-m-d H:i'));
        self::assertSame(27.4, $suggestion->getDistanceKm());

        // outside all places: a temporary place (no geocoder configured: named by its coordinates)
        self::assertCount(2, $places);
        self::assertSame($places[1], $suggestion->getEndPlace());
        self::assertTrue($places[1]->isTemporary());
        self::assertSame(200, $places[1]->getRadius());
    }

    public function testNextTripStartsAtTheTemporaryPlace(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $service = $this->service();
        $places = [];
        $t = static fn (string $time) => (new \DateTimeImmutable('2026-03-02 ' . $time, $tz))->getTimestamp();

        $there = $service->createSuggestion(new User(1), new DetectedTrip(new GpsPoint(52.52, 13.405, $t('08:00')), new GpsPoint(52.40, 13.06, $t('08:40')), 27.4, 120), $places, [], $tz);
        // parked 150 m away from where the first trip ended
        $back = $service->createSuggestion(new User(1), new DetectedTrip(new GpsPoint(52.4013, 13.06, $t('16:00')), new GpsPoint(52.52, 13.405, $t('16:40')), 27.4, 120), $places, [], $tz);

        self::assertCount(2, $places);
        self::assertSame($there->getEndPlace(), $back->getStartPlace());
        self::assertSame($there->getStartPlace(), $back->getEndPlace());
        self::assertSame($there->getEndLabel(), $back->getStartLabel());
    }

    public function testAcceptKeepsCoordinatesAndPlaces(): void
    {
        $saved = [];
        $user = new User(1);
        $place = (new Place())->setName('Kunde')->setLatitude(52.4)->setLongitude(13.06);
        $suggestion = $this->commuteSuggestion($user)->setPurpose(TripPurpose::BUSINESS)->setStart(52.52, 13.405)->setEnd(52.4, 13.06)->setEndPlace($place)->setEndLabel('Kunde');

        $trip = $this->acceptingService($saved)->accept($suggestion, TripPurpose::BUSINESS, VehicleType::OWN_CAR);

        self::assertSame([52.52, 13.405], $trip->getStartCoordinates());
        self::assertSame([52.4, 13.06], $trip->getEndCoordinates());
        self::assertSame($place, $trip->getEndPlace());

        // renaming the destination by hand detaches it from the place
        $trip->setDestination('Kunde');
        self::assertSame($place, $trip->getEndPlace());
        $trip->setDestination('Anderer Kunde');
        self::assertNull($trip->getEndPlace());
        self::assertNull($trip->getEndCoordinates());
    }

    public function testSuggestionDateUsesUserTimezone(): void
    {
        $user = new User(1);
        $user->setPreferenceValue('timezone', 'Europe/Berlin');
        // 23:30 UTC on the 1st is 01:30 on the 2nd in Berlin
        $start = new \DateTimeImmutable('2026-03-01 23:30', new \DateTimeZone('UTC'));
        $suggestion = (new TripSuggestion($start, $start->modify('+30 minutes')))->setUser($user);

        self::assertSame('2026-03-02', $suggestion->getDate()->format('Y-m-d'));
    }

    /**
     * Service whose TripService hands out plain trips and whose repository has no trips yet.
     *
     * @param list<Trip> $saved receives the saved trips
     */
    private function acceptingService(array &$saved): SuggestionService
    {
        $tripService = $this->createMock(TripService::class);
        $tripService->method('createTrip')->willReturnCallback(static fn (User $user, \DateTimeImmutable $date) => (new Trip())->setUser($user)->setDate($date));
        $repository = $this->createMock(TripRepository::class);
        $repository->method('findByUserBetween')->willReturn([]);
        $repository->method('save')->willReturnCallback(static function (Trip $trip) use (&$saved): void {
            $saved[] = $trip;
        });

        return $this->service($tripService, $repository);
    }

    private function commuteSuggestion(User $user): TripSuggestion
    {
        $start = new \DateTimeImmutable('2026-03-02 07:30', new \DateTimeZone('Europe/Berlin'));

        return (new TripSuggestion($start, $start->modify('+25 minutes')))
            ->setUser($user)
            ->setDistanceKm(17.3)
            ->setPurpose(TripPurpose::COMMUTE);
    }

    public function testCommuteUsesProfileDistance(): void
    {
        $user = new User(1);
        $user->setPreferenceValue(MileageConfiguration::PREF_COMMUTE_KM, '15');
        $saved = [];

        $trip = $this->acceptingService($saved)->accept($this->commuteSuggestion($user), TripPurpose::COMMUTE, VehicleType::OWN_CAR);

        self::assertSame(15.0, $trip->getDistanceKm());
        self::assertSame([$trip], $saved);
    }

    public function testCommuteDistanceZeroInProfileIsNotUsed(): void
    {
        $user = new User(1);
        $user->setPreferenceValue(MileageConfiguration::PREF_COMMUTE_KM, '0.0');
        $saved = [];

        $trip = $this->acceptingService($saved)->accept($this->commuteSuggestion($user), TripPurpose::COMMUTE, VehicleType::OWN_CAR);

        // falls back to the driven distance instead of a 0 km trip
        self::assertSame(17.3, $trip->getDistanceKm());
    }

    public function testConfigureChangesTheNewTrip(): void
    {
        $user = new User(1);
        $user->setPreferenceValue(MileageConfiguration::PREF_COMMUTE_KM, '15');
        $saved = [];
        $suggestion = $this->commuteSuggestion($user);

        $result = $this->acceptingService($saved)->acceptTracked($suggestion, TripPurpose::COMMUTE, VehicleType::OWN_CAR, static function (Trip $trip): void {
            $trip->setDistanceKm(9.5)->setComment('Umweg');
        });

        self::assertTrue($result['created']);
        self::assertSame(9.5, $result['trip']->getDistanceKm());
        self::assertSame('Umweg', $result['trip']->getComment());
        self::assertSame(SuggestionStatus::ACCEPTED, $suggestion->getStatus());
    }

    public function testFailingConfigureSavesNothing(): void
    {
        $saved = [];
        $suggestion = $this->commuteSuggestion(new User(1));

        try {
            $this->acceptingService($saved)->acceptTracked($suggestion, TripPurpose::BUSINESS, VehicleType::OWN_CAR, static function (): void {
                throw new InvalidInputException(['distanceKm' => 'too far']);
            });
            self::fail('expected an exception');
        } catch (InvalidInputException) {
        }

        self::assertSame([], $saved);
        self::assertSame(SuggestionStatus::OPEN, $suggestion->getStatus());
    }
}
