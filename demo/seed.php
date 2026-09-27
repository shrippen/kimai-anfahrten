<?php

/*
 * Demo data of the Studio Weber world (shrippen demo): places, the studio van and
 * cargo bike, trips of Mara, Jonas and Lena, a rental car, month approvals and a
 * personal Dawarich (demo/fake-dawarich, started by demo/after-seed.sh).
 * Needs the core data first (shrippen.github.io/demo/kimai/seed-core.php);
 * demo/start.sh runs both.
 */

use App\Entity\Configuration;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\UserPreference;
use App\Kernel;
use KimaiPlugin\MileageBundle\Entity\MonthLock;
use KimaiPlugin\MileageBundle\Entity\Place;
use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Enum\MonthStatus;
use KimaiPlugin\MileageBundle\Enum\PlaceType;
use KimaiPlugin\MileageBundle\Enum\PrivateUseMethod;
use KimaiPlugin\MileageBundle\Enum\TripPurpose;
use KimaiPlugin\MileageBundle\Enum\TripSource;
use KimaiPlugin\MileageBundle\Enum\VehicleType;

require '/opt/kimai/vendor/autoload.php';
require __DIR__ . '/DemoWorld.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/opt/kimai/.env');

$kernel = new Kernel('prod', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();

$world = new DemoWorld(getenv('DEMO_LANG') ?: 'de', null, 'today');
$w = $world->data;
$users = [];
foreach ($w['people'] as $person) {
    $users[$person['id']] = $em->getRepository(User::class)->findOneBy(['email' => $person['email']])
        ?? throw new RuntimeException('Core demo data missing (seed-core.php first).');
}
if ($em->getRepository(Vehicle::class)->findOneBy([]) !== null) {
    echo "Already seeded.\n";
    exit(0);
}
$projects = [];
foreach ($w['projects'] as $p) {
    $projects[$p['id']] = $em->getRepository(Project::class)->findOneBy(['name' => $world->t($p['name'])]);
}
$customers = [];
foreach ($w['customers'] as $c) {
    $customers[$c['id']] = $em->getRepository(Customer::class)->findOneBy(['name' => $c['name']]);
}

$config = static function (string $name, string $value) use ($em): void {
    $entry = new Configuration();
    $entry->setName($name);
    $entry->setValue($value);
    $em->persist($entry);
};
$config('mileage.approval_enabled', '1');
$config('mileage.dawarich_user_url', '1');

$pref = static function (User $user, string $name, string $value) use ($em): void {
    $preference = $user->getPreference($name) ?? new UserPreference($name, $value);
    $user->addPreference($preference);
    $preference->setValue($value);
    $em->persist($preference);
};
// Mara's phone reports to the demo Dawarich (key moved into the secret store on flush)
$pref($users['mara'], 'mileage_dawarich_url', 'http://127.0.0.1:8002');
$pref($users['mara'], 'mileage_dawarich_api_key', 'demo-key');
$pref($users['mara'], 'mileage_commute_km', '3.2');
$pref($users['mara'], 'mileage_license_plate', 'HH-SW 204');

// Places and vehicles belong to a user: Mara's
$types = ['office' => PlaceType::WORK, 'home' => PlaceType::HOME, 'customer' => PlaceType::CUSTOMER];
$places = [];
foreach ($w['places'] as $p) {
    $place = new Place();
    $place->setName($world->t($p['name']));
    $place->setType($types[$p['kind']] ?? PlaceType::OTHER);
    $place->setAddress($p['address']);
    $place->setLatitude($p['lat']);
    $place->setLongitude($p['lon']);
    $place->setRadius(150);
    $place->setUser($users['mara']);
    if (isset($p['customer'])) {
        $place->setCustomer($customers[$p['customer']]);
    }
    $em->persist($place);
    $places[$p['id']] = $place;
}

$vehicles = [];
foreach ($w['vehicles'] as $v) {
    $vehicle = new Vehicle();
    $vehicle->setUser($users['mara']);
    $vehicle->setName($world->t($v['name']));
    $vehicle->setType($v['fuel'] === 'none' ? VehicleType::BICYCLE : VehicleType::COMPANY_CAR);
    $vehicle->setLicensePlate($v['plate'] ?: null);
    $vehicle->setHolder($w['studio']['name']);
    $vehicle->setValidFrom(new DateTimeImmutable('2024-01-01'));
    $vehicle->setInitialOdometer($v['odometer_start'] ?: null);
    $vehicle->setBusinessAsset(true);
    $vehicle->setPrivateUse($v['fuel'] === 'none' ? PrivateUseMethod::NONE : PrivateUseMethod::LOGBOOK);
    $vehicle->setActive(true);
    $em->persist($vehicle);
    $vehicles[$v['id']] = $vehicle;
}

$now = new DateTimeImmutable('now', $world->today->getTimezone());
$trip = static function (string $user, int $day, string $from, string $to, float $km, VehicleType $type, ?Vehicle $vehicle, ?string $plate, string $project, string $comment, string $time) use ($world, $users, $places, $projects, $em, $now): ?Trip {
    $departure = $world->date($day, $time);
    $arrival = $departure->modify('+' . max(10, (int) round($km * 2.5)) . ' minutes');
    if ($arrival > $now) {
        return null;
    }
    $t = new Trip();
    $t->setUser($users[$user]);
    $t->setDate($departure->setTime(0, 0));
    $t->setDepartureAt($departure);
    $t->setArrivalAt($arrival);
    $t->setPurpose(TripPurpose::BUSINESS);
    $t->setVehicle($type);
    $t->setAssignedVehicle($user === 'mara' ? $vehicle : null);
    $t->setLicensePlate($plate);
    if ($user === 'mara') {
        $t->setStartPlace($places[$from]);
        $t->setEndPlace($places[$to]);
    }
    $t->setStartLocation($places[$from]->getAddress());
    $t->setDestination($places[$to]->getAddress());
    $t->setStartCoordinates($places[$from]->getLatitude(), $places[$from]->getLongitude());
    $t->setEndCoordinates($places[$to]->getLatitude(), $places[$to]->getLongitude());
    $t->setDistanceKm($km);
    $t->setProject($projects[$project]);
    $t->setSource(TripSource::MANUAL);
    $t->setComment($comment);
    $em->persist($t);
    return $t;
};

$seen = [];
$count = 0;
foreach ($w['trips'] as $t) {
    $vehicle = $vehicles[$t['vehicle']];
    $isBike = $t['vehicle'] === 'bike';
    $key = $t['user'] . $t['day'];
    $time = isset($seen[$key]) ? '17:30' : '08:15';     // out in the morning, back in the evening
    $seen[$key] = true;
    $made = $trip($t['user'], $t['day'], $t['from'], $t['to'], $t['km'], $isBike ? VehicleType::BICYCLE : VehicleType::COMPANY_CAR,
        $vehicle, $isBike ? null : $vehicle->getLicensePlate(), $t['project'], $world->t($t['purpose']), $time);
    $count += $made ? 1 : 0;
}

foreach ($w['rentals'] as $r) {
    $rental = new Rental();
    $rental->setUser($users[$r['user']]);
    $rental->setProvider($r['provider']);
    $rental->setLicensePlate($r['plate']);
    $rental->setStartDate($world->date($r['from']));
    $rental->setEndDate($world->date($r['to']));
    $rental->setRentalCosts(round($r['cost'] * 0.8, 2));
    $rental->setFuelCosts(round($r['cost'] * 0.2, 2));
    $rental->setComment($world->t($r['car']));
    $em->persist($rental);
    $half = $r['km'] / 2;
    foreach ([[$r['from'], $r['pickup'], 'speiche-workshop'], [$r['to'], 'speiche-workshop', 'studio']] as [$day, $from, $to]) {
        $made = $trip($r['user'], $day, $from, $to, $half, VehicleType::RENTAL_CAR, null, $r['plate'], $r['project'], $world->t($r['car']), '09:00');
        if ($made) {
            $made->setRental($rental);
            $count++;
        }
    }
}

// Month approvals: last month approved by Lena, this month submitted by Jonas and Lena
$last = $world->today->modify('first day of previous month');
foreach (['mara', 'jonas', 'lena'] as $id) {
    $lock = new MonthLock($users[$id], (int) $last->format('Y'), (int) $last->format('n'), $users['lena'], MonthStatus::APPROVED);
    $em->persist($lock);
}
foreach (['jonas'] as $id) {
    $lock = new MonthLock($users[$id], (int) $world->today->format('Y'), (int) $world->today->format('n'), $users[$id], MonthStatus::SUBMITTED);
    $em->persist($lock);
}
$em->flush();

echo "Seeded $count trips, " . count($places) . ' places, ' . count($vehicles) . " vehicles, 1 rental.\n";
