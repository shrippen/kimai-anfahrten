<?php

/*
 * Seeds the end-to-end test data into a Kimai installation: users, preferences,
 * a team, API tokens, a customer/project and two timesheets.
 *
 * Usage: php tests/e2e/seed.php /path/to/kimai
 */

use App\Entity\AccessToken;
use App\Entity\Activity;
use App\Entity\Configuration;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Entity\UserPreference;

$kimai = $argv[1] ?? '';
require $kimai . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($kimai . '/.env');
$kernel = new App\Kernel('prod', false);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$users = $em->getRepository(User::class);

$pref = static function (User $user, string $name, string $value) use ($em): void {
    $preference = $user->getPreference($name);
    if ($preference === null) {
        $preference = new UserPreference($name, $value);
        $user->addPreference($preference);
    }
    $preference->setValue($value);
    $em->persist($preference);
};

$approval = new Configuration();
$approval->setName('mileage.approval_enabled');
$approval->setValue('1');
$em->persist($approval);

// the admin uses a personal Dawarich URL (the simulated instance)
$userUrl = new Configuration();
$userUrl->setName('mileage.dawarich_user_url');
$userUrl->setValue('1');
$em->persist($userUrl);

$team = new Team('Außendienst');
$tz = new DateTimeZone('Europe/Berlin');

foreach (['admin' => ['http://127.0.0.1:8002', 'test-key', '8'], 'hans' => [null, null, null], 'tina' => [null, null, null]] as $name => [$url, $key, $km]) {
    $user = $users->findOneBy(['username' => $name]);
    $pref($user, '__wizards__', 'intro,profile');
    $pref($user, 'language', 'de');
    $pref($user, 'locale', 'de');
    $pref($user, 'timezone', 'Europe/Berlin');
    if ($url !== null) {
        $pref($user, 'mileage_dawarich_url', $url);
        // moved into kimai2_ext_mileage_user_secret by the DawarichKeyListener on flush
        $pref($user, 'mileage_dawarich_api_key', $key);
        $pref($user, 'mileage_commute_km', $km);
        $pref($user, 'mileage_license_plate', 'B-AB 123');
    }
    $token = new AccessToken($user, 'e2e-token-' . $name . '-0123456789');
    $token->setName('e2e');
    $em->persist($token);

    if ($name === 'tina') {
        $team->addTeamlead($user);
    } elseif ($name === 'hans') {
        $team->addUser($user);
    }
}
$em->persist($team);

$customer = new Customer('ACME GmbH');
$customer->setCountry('DE');
$customer->setCurrency('EUR');
$customer->setTimezone('Europe/Berlin');
$em->persist($customer);
$project = new Project();
$project->setName('Relaunch');
$project->setCustomer($customer);
$em->persist($project);
$activity = new Activity();
$activity->setName('Beratung');
$em->persist($activity);
$em->flush();

$admin = $users->findOneBy(['username' => 'admin']);
foreach ([['08:00', '12:00'], ['12:45', '16:00']] as [$begin, $end]) {
    $timesheet = new Timesheet();
    $timesheet->setUser($admin);
    $timesheet->setProject($project);
    $timesheet->setActivity($activity);
    $timesheet->setBegin(new DateTime('2026-09-21 ' . $begin, $tz));
    $timesheet->setEnd(new DateTime('2026-09-21 ' . $end, $tz));
    $timesheet->setDuration($timesheet->getEnd()->getTimestamp() - $timesheet->getBegin()->getTimestamp());
    $em->persist($timesheet);
}
$em->flush();

echo "seeded\n";
