<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Tests;

use PHPUnit\Framework\TestCase;

final class BoundaryTest extends TestCase
{
    public function test_domain_and_application_layers_are_framework_free(): void
    {
        $root = dirname(__DIR__).'/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $forbidden = ['Illuminate\\', 'Filament\\', 'Laravel\\', 'WordPress', 'Shopify', 'Guzzle', 'PDO', 'Redis'];

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $source, $file->getPathname());
            }
        }
    }
}
