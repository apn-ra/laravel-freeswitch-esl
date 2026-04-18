<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Contract;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PackageBoundaryEnforcementTest extends TestCase
{
    public function test_no_local_upstream_stub_namespace_remains(): void
    {
        $this->assertDirectoryDoesNotExist(__DIR__.'/../../src/Contracts/Upstream');
    }

    public function test_no_source_file_shadows_upstream_package_namespaces(): void
    {
        $violations = [];

        foreach ($this->sourceFiles() as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);

            foreach ([
                'namespace Apntalk\\EslCore',
                'namespace Apntalk\\EslReact',
                'namespace Apntalk\\EslReplay',
            ] as $forbiddenNamespace) {
                if (str_contains($contents, $forbiddenNamespace)) {
                    $violations[] = sprintf('%s declares %s', $file, $forbiddenNamespace);
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../src')
        );

        $files = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }
}
