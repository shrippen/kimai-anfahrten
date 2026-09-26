<?php

namespace KimaiPlugin\MileageBundle\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every plugin translation key used in PHP or Twig must exist in all message catalogues.
 */
class TranslationKeysTest extends TestCase
{
    /** All plugin keys carry the plugin prefix (kimai-plugin-ui GUIDELINES 6). */
    private const PREFIXES = ['mileage'];

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
     * @dataProvider catalogues
     */
    public function testKeysHaveThePluginPrefix(string $catalogue): void
    {
        preg_match_all('/resname="([^"]+)"/', (string) file_get_contents($catalogue), $m);
        // "mileage" (role/section name) and "mileage_*" (user preference names) are the only keys without a dot
        $foreign = array_values(array_filter($m[1], static fn (string $key) => !preg_match('/^mileage(\.|_|$)/', $key)));

        self::assertSame([], $foreign, basename($catalogue) . ' has keys without the plugin prefix');
    }

    /**
     * Plural messages must cover every count from 0 on, Symfony throws otherwise (GUIDELINES 6).
     *
     * @dataProvider catalogues
     */
    public function testPluralsCoverZero(string $catalogue): void
    {
        $translator = new \Symfony\Component\Translation\Translator('de');
        $translator->addLoader('array', new \Symfony\Component\Translation\Loader\ArrayLoader());
        $xml = simplexml_load_file($catalogue);
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
        $checked = 0;
        foreach ($xml->xpath('//x:trans-unit') ?: [] as $unit) {
            $target = (string) $unit->target;
            if (!str_contains($target, '%count%') || !str_contains($target, '|')) {
                continue;
            }
            foreach ([0, 1, 2] as $count) {
                $translator->addResource('array', ['k' => $target], 'de');
                self::assertNotSame('', $translator->trans('k', ['%count%' => $count]), (string) $unit['resname'] . ' with ' . $count);
            }
            ++$checked;
        }
        self::assertGreaterThan(0, $checked);
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
