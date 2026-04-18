<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Contract;

use PHPUnit\Framework\TestCase;

class PrivateLiveValidationScriptTest extends TestCase
{
    private string $repoRoot;

    /**
     * @var list<string>
     */
    private array $temporaryPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoRoot = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            $this->removePath($path);
        }

        parent::tearDown();
    }

    public function test_missing_required_host_fails_closed_without_leaking_password(): void
    {
        $envFile = $this->writeTempEnvFile(<<<'ENV'
FREESWITCH_ESL_SMOKE_PORT=8021
FREESWITCH_ESL_SMOKE_PASSWORD=super-secret-password
FREESWITCH_ESL_SMOKE_READ_ONLY=true
ENV);

        $result = $this->runScript([
            '--env-file='.$envFile,
        ]);

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('FREESWITCH_ESL_SMOKE_HOST must be set.', $result['stderr']);
        $this->assertStringNotContainsString('super-secret-password', $result['stderr']);
        $this->assertSame('', $result['stdout']);
    }

    public function test_read_only_flag_must_remain_true(): void
    {
        $envFile = $this->writeTempEnvFile(<<<'ENV'
FREESWITCH_ESL_SMOKE_HOST=pbx.example.internal
FREESWITCH_ESL_SMOKE_PORT=8021
FREESWITCH_ESL_SMOKE_PASSWORD=super-secret-password
FREESWITCH_ESL_SMOKE_READ_ONLY=false
ENV);

        $result = $this->runScript([
            '--env-file='.$envFile,
        ]);

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString(
            'FREESWITCH_ESL_SMOKE_READ_ONLY must remain true for this wrapper.',
            $result['stderr'],
        );
        $this->assertStringNotContainsString('super-secret-password', $result['stderr']);
    }

    public function test_dry_run_reports_repo_relative_artifact_layout_and_helper_path(): void
    {
        $envFile = $this->writeTempEnvFile(<<<'ENV'
FREESWITCH_ESL_SMOKE_HOST=pbx.example.internal
FREESWITCH_ESL_SMOKE_PORT=8021
FREESWITCH_ESL_SMOKE_PASSWORD=super-secret-password
FREESWITCH_ESL_SMOKE_READ_ONLY=true
FREESWITCH_ESL_SMOKE_OUTPUT_DIR=build/test-live-smoke
ENV);
        $isolatedWorkingDirectory = $this->makeTempDirectory('private-live-validation-cwd-');

        $result = $this->runScript([
            '--env-file='.$envFile,
            '--dry-run',
        ], $isolatedWorkingDirectory);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('', $result['stderr']);

        $payload = json_decode($result['stdout'], true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame('build/test-live-smoke', $payload['output_dir']);
        $this->assertSame('build/test-live-smoke/captures', $payload['capture_dir']);
        $this->assertSame(
            'vendor/apntalk/esl-core/tools/smoke/live_freeswitch_readonly_validate.php',
            $payload['helper_script'],
        );
        $this->assertCount(4, $payload['checks']);
        $this->assertSame('auth.json', $payload['checks'][0]['artifact']);
        $this->assertStringNotContainsString('super-secret-password', $result['stdout']);
        $this->assertStringNotContainsString('pbx.example.internal', $result['stdout']);
    }

    public function test_dry_run_rejects_output_directory_when_target_is_a_file(): void
    {
        $outputFile = $this->makeTempFile('not-a-directory');
        $envFile = $this->writeTempEnvFile(sprintf(<<<'ENV'
FREESWITCH_ESL_SMOKE_HOST=pbx.example.internal
FREESWITCH_ESL_SMOKE_PORT=8021
FREESWITCH_ESL_SMOKE_PASSWORD=super-secret-password
FREESWITCH_ESL_SMOKE_READ_ONLY=true
FREESWITCH_ESL_SMOKE_OUTPUT_DIR=%s
ENV, $outputFile));

        $result = $this->runScript([
            '--env-file='.$envFile,
            '--dry-run',
        ]);

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('Output path [', $result['stderr']);
        $this->assertStringContainsString('is a file, not a directory.', $result['stderr']);
    }

    /**
     * @param  list<string>  $arguments
     * @return array{exit_code: int, stderr: string, stdout: string}
     */
    private function runScript(array $arguments, ?string $workingDirectory = null): array
    {
        $command = array_merge(
            [PHP_BINARY, $this->repoRoot.'/bin/freeswitch-private-live-validate.php'],
            $arguments,
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $workingDirectory ?? $this->repoRoot,
        );

        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertIsString($stdout);
        $this->assertIsString($stderr);

        return [
            'exit_code' => $exitCode,
            'stderr' => $stderr,
            'stdout' => $stdout,
        ];
    }

    private function writeTempEnvFile(string $contents): string
    {
        $directory = $this->makeTempDirectory('private-live-validation-env-');
        $path = $directory.'/validation.env';
        file_put_contents($path, $contents."\n");

        return $path;
    }

    private function makeTempDirectory(string $prefix): string
    {
        $base = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));
        mkdir($base, 0775, true);
        $this->temporaryPaths[] = $base;

        return $base;
    }

    private function makeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'private-live-validation-file-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $children = scandir($path);

        if ($children === false) {
            return;
        }

        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }

            $this->removePath($path.'/'.$child);
        }

        @rmdir($path);
    }
}
