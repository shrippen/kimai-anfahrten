<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Entity\User;
use KimaiPlugin\MileageBundle\Entity\MonthLock;
use KimaiPlugin\MileageBundle\Enum\MonthStatus;
use KimaiPlugin\MileageBundle\Service\ApiInfo;
use PHPUnit\Framework\TestCase;

class ApiInfoTest extends TestCase
{
    public function testPingShape(): void
    {
        $permissions = ['view' => true, 'editOwn' => true, 'deleteOwn' => true, 'editLocked' => false, 'viewOther' => false, 'editOther' => false];
        $profile = ['commuteKm' => 12.5, 'defaultVehicle' => 'own_car', 'defaultVehicleId' => null, 'dawarichConfigured' => true];
        $ping = ApiInfo::ping($permissions, $profile, ['2026-01']);

        self::assertTrue($ping['installed']);
        self::assertSame(ApiInfo::PLUGIN_VERSION, $ping['pluginVersion']);
        self::assertSame(['v1'], $ping['apiVersions']);
        self::assertSame($permissions, $ping['permissions']);
        self::assertContains('dateRange', $ping['features']);
        self::assertContains('acceptFields', $ping['features']);
        self::assertSame($profile, $ping['profile']);
        self::assertSame(['2026-01'], $ping['lockedMonths']);
    }

    public function testPluginVersionMatchesComposer(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        self::assertSame($composer['version'], ApiInfo::PLUGIN_VERSION);
    }

    public function testLockedMonthsSkipsRejectedAndSorts(): void
    {
        $user = new User(1);
        $locks = [
            new MonthLock($user, 2026, 3, $user, MonthStatus::APPROVED),
            new MonthLock($user, 2025, 12, $user, MonthStatus::CLOSED),
            new MonthLock($user, 2026, 1, $user, MonthStatus::SUBMITTED),
            new MonthLock($user, 2026, 2, $user, MonthStatus::REJECTED),
        ];

        self::assertSame(['2025-12', '2026-01', '2026-03'], ApiInfo::lockedMonths($locks));
    }
}
