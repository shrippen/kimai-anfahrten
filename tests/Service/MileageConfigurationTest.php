<?php

namespace KimaiPlugin\MileageBundle\Tests\Service;

use App\Configuration\SystemConfiguration;
use App\Entity\User;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MileageConfigurationTest extends TestCase
{
    /**
     * @return array<string, array{?string, ?float}>
     */
    public static function commuteValues(): array
    {
        return [
            'not set' => [null, null],
            'empty' => ['', null],
            'zero' => ['0.0', null],
            'zero with comma' => ['0,0', null],
            'negative' => ['-3', null],
            'text' => ['abc', null],
            'decimal point' => ['12.5', 12.5],
            'decimal comma' => ['12,5', 12.5],
        ];
    }

    #[DataProvider('commuteValues')]
    public function testCommuteDistanceZeroIsNotConfigured(?string $value, ?float $expected): void
    {
        $user = new User(1);
        $user->setPreferenceValue(MileageConfiguration::PREF_COMMUTE_KM, $value);

        self::assertSame($expected, (new MileageConfiguration(new SystemConfiguration()))->getCommuteKm($user));
    }
}
