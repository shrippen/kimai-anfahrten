<?php

namespace KimaiPlugin\MileageBundle\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every plugin translation key used in PHP or Twig must exist in all message catalogues.
 */
class TranslationKeysTest extends TestCase
{
    private const PREFIXES = ['trip', 'tax', 'commute', 'dawarich', 'mileage', 'menu.mileage', 'vehicle', 'rental', 'suggestion', 'logbook', 'plausibility', 'approval', 'import', 'meal', 'overview', 'attachment', 'place', 'odometer', 'api'];

    /**
     * @return array<string, array{string}>
     */
    public static function catalogues(): array
    {
        $result = [];
        foreach (glob(__DIR__ . '/../Resources/translations/messages.*.xlf') ?: [] as $file) {
            $result[basename($file)] = [$file];
        }

        return $result;
    }

    /**
     * @dataProvider catalogues
     */
    public function testAllUsedKeysAreTranslated(string $catalogue): void
    {
        $xml = (string) file_get_contents($catalogue);
        preg_match_all('/resname="([^"]+)"/', $xml, $m);
        $known = array_flip($m[1]);

        $missing = array_values(array_filter(self::usedKeys(), static fn (string $key) => !isset($known[$key])));

        self::assertSame([], $missing, basename($catalogue) . ' is missing keys');
    }

    /**
     * @return string[]
     */
    private static function usedKeys(): array
    {
        $root = \dirname(__DIR__);
        $files = [];
        foreach (['Controller', 'Service', 'Form', 'EventSubscriber', 'Enum', 'Entity', 'API', 'Command', 'Resources/views'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($it as $file) {
                if ($file->isFile() && preg_match('/\.(php|twig)$/', $file->getFilename())) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $prefix = implode('|', array_map('preg_quote', self::PREFIXES));
        $keys = [];
        foreach ($files as $file) {
            preg_match_all("/'((?:$prefix)(?:\.[a-z0-9_]+)+)'/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }
}
