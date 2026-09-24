<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Enum\PlaceType;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Repository\PlaceRepository;
use KimaiPlugin\MileageBundle\Repository\TripRepository;
use KimaiPlugin\MileageBundle\Repository\TripSuggestionRepository;
use KimaiPlugin\MileageBundle\Service\DawarichClient;
use KimaiPlugin\MileageBundle\Service\DetectedTrip;
use KimaiPlugin\MileageBundle\Service\DistanceCalculator;
use KimaiPlugin\MileageBundle\Service\GeocoderClient;
use KimaiPlugin\MileageBundle\Service\GpsPoint;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use KimaiPlugin\MileageBundle\Service\PlaceMatcher;
use KimaiPlugin\MileageBundle\Service\SuggestionService;
use KimaiPlugin\MileageBundle\Service\TimesheetMatcher;
use KimaiPlugin\MileageBundle\Service\TripDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

class SuggestionServiceTest extends TestCase
{
    private function service(): SuggestionService
    {
        $config = new MileageConfiguration(new SystemConfiguration());
        $distance = new DistanceCalculator();
        $http = new MockHttpClient();

        return new SuggestionService(
            new DawarichClient($http, $config, $distance),
            new TripDetector($distance),
            new PlaceMatcher($distance),
            new TimesheetMatcher(),
            new GeocoderClient($http, $config),
            $config,
            $this->createMock(PlaceRepository::class),
            $this->createMock(TripSuggestionRepository::class),
            $this->createMock(TripRepository::class),
            $this->createMock(EntityManagerInterface::class),
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

        $suggestion = $this->service()->createSuggestion(new User(1), new DetectedTrip($start, $end, 27.4, 120), [$home], [$timesheet], $tz);

        self::assertSame('Zuhause', $suggestion->getStartLabel());
        self::assertSame('52.40000, 13.06000', $suggestion->getEndLabel());
        self::assertSame($home, $suggestion->getStartPlace());
        self::assertSame($timesheet, $suggestion->getTimesheet());
        self::assertSame($project, $suggestion->getProject());
        self::assertSame(TripPurpose::BUSINESS, $suggestion->getPurpose());
        self::assertSame('2026-03-02 09:05', $suggestion->getStartAt()->format('Y-m-d H:i'));
        self::assertSame(27.4, $suggestion->getDistanceKm());
    }

    public function testSuggestionDateUsesUserTimezone(): void
    {
        $user = new User(1);
        $user->setPreferenceValue('timezone', 'Europe/Berlin');
        // 23:30 UTC on the 1st is 01:30 on the 2nd in Berlin
        $start = new \DateTimeImmutable('2026-03-01 23:30', new \DateTimeZone('UTC'));
        $suggestion = (new \KimaiPlugin\MileageBundle\Entity\TripSuggestion($start, $start->modify('+30 minutes')))->setUser($user);

        self::assertSame('2026-03-02', $suggestion->getDate()->format('Y-m-d'));
    }
}
