<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Timesheet;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Enum\PlaceType;
use KimaiPlugin\MileageBundle\Service\DistanceCalculator;
use KimaiPlugin\MileageBundle\Service\GeocoderClient;
use KimaiPlugin\MileageBundle\Service\PlaceMatcher;
use KimaiPlugin\MileageBundle\Service\TimesheetMatcher;
use PHPUnit\Framework\TestCase;

class MatchersTest extends TestCase
{
    public function testPlaceMatcherPicksNearestWithinRadius(): void
    {
        $home = (new Place())->setName('Home')->setType(PlaceType::HOME)->setLatitude(52.5200)->setLongitude(13.4050)->setRadius(150);
        $cafe = (new Place())->setName('Cafe')->setLatitude(52.5205)->setLongitude(13.4050)->setRadius(150);
        $far = (new Place())->setName('Far')->setLatitude(48.0)->setLongitude(11.0)->setRadius(5000);

        $matcher = new PlaceMatcher(new DistanceCalculator());

        self::assertSame($home, $matcher->match([$far, $cafe, $home], 52.5199, 13.4050));
        self::assertSame($cafe, $matcher->match([$far, $cafe, $home], 52.5206, 13.4050));
        self::assertNull($matcher->match([$home], 52.53, 13.4050));
        self::assertNull($matcher->match([], 1, 1));
    }

    public function testTimesheetMatcher(): void
    {
        $project = new Project('Relaunch', new Customer('ACME'));
        $morning = new Timesheet(new \DateTime('2026-03-02 09:00'), new \DateTime('2026-03-02 12:00'), $project);
        $afternoon = new Timesheet(new \DateTime('2026-03-02 14:00'), new \DateTime('2026-03-02 17:30'));
        $matcher = new TimesheetMatcher();

        // arrives 08:40, work starts 09:00
        self::assertSame($morning, $matcher->match([$afternoon, $morning], new \DateTimeImmutable('2026-03-02 08:10'), new \DateTimeImmutable('2026-03-02 08:40')));
        // leaves 17:45 after the afternoon block
        self::assertSame($afternoon, $matcher->match([$afternoon, $morning], new \DateTimeImmutable('2026-03-02 17:45'), new \DateTimeImmutable('2026-03-02 18:20')));
        // lunch trip 12:10–13:40: closest is the end of the morning block
        self::assertSame($morning, $matcher->match([$afternoon, $morning], new \DateTimeImmutable('2026-03-02 12:05'), new \DateTimeImmutable('2026-03-02 13:10')));
        // evening, nothing near
        self::assertNull($matcher->match([$afternoon, $morning], new \DateTimeImmutable('2026-03-02 21:00'), new \DateTimeImmutable('2026-03-02 21:30')));
    }

    public function testGeocoderParsesNominatim(): void
    {
        $label = GeocoderClient::parse([
            'display_name' => 'long name',
            'address' => ['road' => 'Hauptstraße', 'house_number' => '5', 'postcode' => '10115', 'city' => 'Berlin'],
        ]);

        self::assertSame('Hauptstraße 5, 10115 Berlin', $label);
    }

    public function testGeocoderParsesPhoton(): void
    {
        $label = GeocoderClient::parse(['features' => [['properties' => ['street' => 'Marktplatz', 'postcode' => '14467', 'town' => 'Potsdam']]]]);

        self::assertSame('Marktplatz, 14467 Potsdam', $label);
    }

    public function testGeocoderFallsBackToDisplayName(): void
    {
        self::assertSame('Somewhere', GeocoderClient::parse(['display_name' => 'Somewhere']));
        self::assertNull(GeocoderClient::parse([]));
    }
}
