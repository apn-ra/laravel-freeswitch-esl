#!/usr/bin/env php
<?php

declare(strict_types=1);

const PRIVATE_VALIDATION_DEFAULT_ENV_FILE = '.env.private-validation';
const PRIVATE_VALIDATION_EXAMPLE_ENV_FILE = '.env.private-validation.example';
const PRIVATE_VALIDATION_DEFAULT_TIMEOUT = 8;
const PRIVATE_VALIDATION_DEFAULT_OUTPUT_DIR = 'build/live-smoke-review';
const PRIVATE_VALIDATION_PASSWORD_ENV = 'FREESWITCH_ESL_SMOKE_PASSWORD';

/** @var list<string> $argv */
main($argv);

/**
 * @param  list<string>  $argv
 */
function main(array $argv): void
{
    $options = parseOptions($argv);

    if ($options['help'] === true) {
        fwrite(STDOUT, usage());
        exit(0);
    }

    $repoRoot = dirname(__DIR__);
    $envFile = resolvePath($options['env_file'], $repoRoot);
    $helperScript = $repoRoot.'/vendor/apntalk/esl-core/tools/smoke/live_freeswitch_readonly_validate.php';
    $phpBinary = PHP_BINARY;

    try {
        $config = resolveConfig($envFile, $options);
        validateConfig($config, $helperScript, $envFile);

        $outputDir = resolvePath($config['output_dir'], $repoRoot);
        $captureDir = $config['capture_frames'] ? $outputDir.'/captures' : null;
        validateOutputTargets($outputDir, $captureDir);
        $checks = buildChecks(
            helperScript: $helperScript,
            host: $config['host'],
            port: $config['port'],
            timeoutSeconds: $config['timeout'],
            outputDir: $outputDir,
            captureDir: $captureDir,
            phpBinary: $phpBinary,
        );

        if ($options['dry_run'] === true) {
            fwrite(STDOUT, json_encode([
                'dry_run' => true,
                'env_file' => relativePath($envFile, $repoRoot),
                'output_dir' => relativePath($outputDir, $repoRoot),
                'capture_dir' => $captureDir !== null ? relativePath($captureDir, $repoRoot) : null,
                'timeout_seconds' => $config['timeout'],
                'read_only' => true,
                'helper_script' => relativePath($helperScript, $repoRoot),
                'checks' => array_map(static function (array $check): array {
                    return [
                        'mode' => $check['mode'],
                        'artifact' => basename($check['artifact']),
                        'stderr_log' => basename($check['stderr_log']),
                    ];
                }, $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
            exit(0);
        }

        ensureOutputDirectories($outputDir, $captureDir);
        clearPreviousRunArtifacts($checks, $captureDir, $outputDir);

        $summary = [
            'status' => 'passed',
            'read_only' => true,
            'env_file' => relativePath($envFile, $repoRoot),
            'executed_at_utc' => gmdate(DATE_ATOM),
            'output_dir' => relativePath($outputDir, $repoRoot),
            'capture_dir' => $captureDir !== null ? relativePath($captureDir, $repoRoot) : null,
            'timeout_seconds' => $config['timeout'],
            'git_head' => gitHeadSha($repoRoot),
            'helper_script' => relativePath($helperScript, $repoRoot),
            'checks' => [],
        ];

        foreach ($checks as $check) {
            $result = runCheck(
                check: $check,
                password: $config['password'],
            );

            $summary['checks'][] = $result;

            if (($result['status'] ?? 'failed') !== 'passed') {
                $summary['status'] = 'failed';
                appendSkippedChecks($summary['checks'], $checks, $check['mode']);
                break;
            }
        }

        writeSummaryArtifacts($summary, $outputDir);

        fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit($summary['status'] === 'passed' ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("Private validation setup failed: %s\n", $e->getMessage()));
        exit(1);
    }
}

function validateOutputTargets(string $outputDir, ?string $captureDir): void
{
    assertPathCreatableOrWritable($outputDir, 'Output');

    if ($captureDir === null) {
        return;
    }

    assertPathCreatableOrWritable($captureDir, 'Capture');
}

function assertPathCreatableOrWritable(string $directory, string $label): void
{
    if (is_file($directory)) {
        throw new RuntimeException(sprintf('%s path [%s] is a file, not a directory.', $label, $directory));
    }

    if (is_dir($directory)) {
        if (! is_writable($directory)) {
            throw new RuntimeException(sprintf('%s directory [%s] is not writable.', $label, $directory));
        }

        return;
    }

    $parent = nearestExistingDirectory(dirname($directory));

    if ($parent === null || ! is_writable($parent)) {
        throw new RuntimeException(sprintf(
            '%s directory [%s] is not creatable because parent [%s] is missing or not writable.',
            $label,
            $directory,
            $parent ?? dirname($directory),
        ));
    }
}

function nearestExistingDirectory(string $path): ?string
{
    $candidate = $path;

    while ($candidate !== '' && $candidate !== '.' && $candidate !== DIRECTORY_SEPARATOR) {
        if (is_dir($candidate)) {
            return $candidate;
        }

        $parent = dirname($candidate);

        if ($parent === $candidate) {
            break;
        }

        $candidate = $parent;
    }

    if ($candidate === DIRECTORY_SEPARATOR && is_dir($candidate)) {
        return $candidate;
    }

    return is_dir('.') ? getcwd() ?: null : null;
}

/**
 * @param  list<string>  $argv
 * @return array{env_file: string, dry_run: bool, help: bool, no_capture: bool, output_dir: string|null, timeout: int|null}
 */
function parseOptions(array $argv): array
{
    $options = [
        'env_file' => PRIVATE_VALIDATION_DEFAULT_ENV_FILE,
        'dry_run' => false,
        'help' => false,
        'no_capture' => false,
        'output_dir' => null,
        'timeout' => null,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;

            continue;
        }

        if ($argument === '--dry-run') {
            $options['dry_run'] = true;

            continue;
        }

        if ($argument === '--no-capture') {
            $options['no_capture'] = true;

            continue;
        }

        if (str_starts_with($argument, '--env-file=')) {
            $options['env_file'] = substr($argument, strlen('--env-file='));

            continue;
        }

        if (str_starts_with($argument, '--output-dir=')) {
            $options['output_dir'] = substr($argument, strlen('--output-dir='));

            continue;
        }

        if (str_starts_with($argument, '--timeout=')) {
            $value = substr($argument, strlen('--timeout='));
            $options['timeout'] = (int) $value;

            continue;
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown option "%s".%s',
            $argument,
            PHP_EOL.usage(),
        ));
    }

    return $options;
}

/**
 * @param  array{env_file: string, dry_run: bool, help: bool, no_capture: bool, output_dir: string|null, timeout: int|null}  $options
 * @return array{capture_frames: bool, host: string, output_dir: string, password: string, port: int, read_only: bool, timeout: int}
 */
function resolveConfig(string $envFile, array $options): array
{
    $fileValues = readEnvFile($envFile);
    $resolved = [];

    foreach ([
        'FREESWITCH_ESL_SMOKE_HOST',
        'FREESWITCH_ESL_SMOKE_PORT',
        'FREESWITCH_ESL_SMOKE_PASSWORD',
        'FREESWITCH_ESL_SMOKE_READ_ONLY',
        'FREESWITCH_ESL_SMOKE_TIMEOUT',
        'FREESWITCH_ESL_SMOKE_CAPTURE_FRAMES',
        'FREESWITCH_ESL_SMOKE_OUTPUT_DIR',
    ] as $name) {
        $envValue = getenv($name);

        if (is_string($envValue) && $envValue !== '') {
            $resolved[$name] = $envValue;

            continue;
        }

        if (array_key_exists($name, $fileValues)) {
            $resolved[$name] = $fileValues[$name];
        }
    }

    $timeout = $options['timeout']
        ?? parsePositiveInt($resolved['FREESWITCH_ESL_SMOKE_TIMEOUT'] ?? null, PRIVATE_VALIDATION_DEFAULT_TIMEOUT);

    $outputDir = $options['output_dir']
        ?? (($resolved['FREESWITCH_ESL_SMOKE_OUTPUT_DIR'] ?? '') !== ''
            ? (string) $resolved['FREESWITCH_ESL_SMOKE_OUTPUT_DIR']
            : PRIVATE_VALIDATION_DEFAULT_OUTPUT_DIR);

    $captureFrames = $options['no_capture']
        ? false
        : parseBool($resolved['FREESWITCH_ESL_SMOKE_CAPTURE_FRAMES'] ?? null, true);

    return [
        'capture_frames' => $captureFrames,
        'host' => trim((string) ($resolved['FREESWITCH_ESL_SMOKE_HOST'] ?? '')),
        'output_dir' => $outputDir,
        'password' => (string) ($resolved['FREESWITCH_ESL_SMOKE_PASSWORD'] ?? ''),
        'port' => parsePositiveInt($resolved['FREESWITCH_ESL_SMOKE_PORT'] ?? null, 0),
        'read_only' => parseBool($resolved['FREESWITCH_ESL_SMOKE_READ_ONLY'] ?? null, true),
        'timeout' => $timeout,
    ];
}

/**
 * @param  array{capture_frames: bool, host: string, output_dir: string, password: string, port: int, read_only: bool, timeout: int}  $config
 */
function validateConfig(array $config, string $helperScript, string $envFile): void
{
    if (! is_file($helperScript)) {
        throw new RuntimeException(sprintf(
            'Upstream read-only helper not found at [%s]. Run composer install first.',
            $helperScript,
        ));
    }

    if (! is_file($envFile)) {
        throw new RuntimeException(sprintf(
            'Environment file [%s] was not found. Copy [%s] and fill in private values first.',
            $envFile,
            PRIVATE_VALIDATION_EXAMPLE_ENV_FILE,
        ));
    }

    if ($config['host'] === '') {
        throw new RuntimeException('FREESWITCH_ESL_SMOKE_HOST must be set.');
    }

    if ($config['port'] < 1 || $config['port'] > 65535) {
        throw new RuntimeException('FREESWITCH_ESL_SMOKE_PORT must be a valid TCP port.');
    }

    if ($config['password'] === '') {
        throw new RuntimeException('FREESWITCH_ESL_SMOKE_PASSWORD must be set.');
    }

    if ($config['read_only'] !== true) {
        throw new RuntimeException('FREESWITCH_ESL_SMOKE_READ_ONLY must remain true for this wrapper.');
    }

    if ($config['timeout'] < 1) {
        throw new RuntimeException('FREESWITCH_ESL_SMOKE_TIMEOUT must be a positive integer.');
    }

    if (trim($config['output_dir']) === '') {
        throw new RuntimeException('FREESWITCH_ESL_SMOKE_OUTPUT_DIR must not be empty.');
    }
}

/**
 * @return list<array{artifact: string, command: list<string>, mode: string, stderr_log: string}>
 */
function buildChecks(
    string $helperScript,
    string $host,
    int $port,
    int $timeoutSeconds,
    string $outputDir,
    ?string $captureDir,
    string $phpBinary,
): array {
    $captureArg = $captureDir !== null ? [sprintf('--capture-dir=%s', $captureDir)] : [];

    return [
        [
            'artifact' => $outputDir.'/auth.json',
            'command' => array_merge([
                $phpBinary,
                $helperScript,
                'auth',
                $host,
                (string) $port,
                '--password-env='.PRIVATE_VALIDATION_PASSWORD_ENV,
            ], $captureArg),
            'mode' => 'auth',
            'stderr_log' => $outputDir.'/auth.stderr.log',
        ],
        [
            'artifact' => $outputDir.'/api-status.json',
            'command' => array_merge([
                $phpBinary,
                $helperScript,
                'api',
                $host,
                (string) $port,
                '--password-env='.PRIVATE_VALIDATION_PASSWORD_ENV,
            ], $captureArg),
            'mode' => 'api status',
            'stderr_log' => $outputDir.'/api-status.stderr.log',
        ],
        [
            'artifact' => $outputDir.'/event-plain.json',
            'command' => array_merge([
                $phpBinary,
                $helperScript,
                'event-plain',
                $host,
                (string) $port,
                '--password-env='.PRIVATE_VALIDATION_PASSWORD_ENV,
                sprintf('--timeout=%d', $timeoutSeconds),
            ], $captureArg),
            'mode' => 'event-plain',
            'stderr_log' => $outputDir.'/event-plain.stderr.log',
        ],
        [
            'artifact' => $outputDir.'/event-json.json',
            'command' => array_merge([
                $phpBinary,
                $helperScript,
                'event-json',
                $host,
                (string) $port,
                '--password-env='.PRIVATE_VALIDATION_PASSWORD_ENV,
                sprintf('--timeout=%d', $timeoutSeconds),
            ], $captureArg),
            'mode' => 'event-json',
            'stderr_log' => $outputDir.'/event-json.stderr.log',
        ],
    ];
}

function ensureOutputDirectories(string $outputDir, ?string $captureDir): void
{
    if (is_file($outputDir)) {
        throw new RuntimeException(sprintf('Output path [%s] is a file, not a directory.', $outputDir));
    }

    if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
        throw new RuntimeException(sprintf('Unable to create output directory [%s].', $outputDir));
    }

    if (! is_writable($outputDir)) {
        throw new RuntimeException(sprintf('Output directory [%s] is not writable.', $outputDir));
    }

    if ($captureDir === null) {
        return;
    }

    if (is_file($captureDir)) {
        throw new RuntimeException(sprintf('Capture path [%s] is a file, not a directory.', $captureDir));
    }

    if (! is_dir($captureDir) && ! mkdir($captureDir, 0775, true) && ! is_dir($captureDir)) {
        throw new RuntimeException(sprintf('Unable to create capture directory [%s].', $captureDir));
    }
}

/**
 * @param  list<array{artifact: string, command: list<string>, mode: string, stderr_log: string}>  $checks
 */
function clearPreviousRunArtifacts(array $checks, ?string $captureDir, string $outputDir): void
{
    foreach ($checks as $check) {
        @unlink($check['artifact']);
        @unlink($check['stderr_log']);
    }

    foreach ([
        $outputDir.'/summary.json',
        $outputDir.'/release-review.md',
    ] as $artifact) {
        @unlink($artifact);
    }

    if ($captureDir === null || ! is_dir($captureDir)) {
        return;
    }

    foreach (scandir($captureDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $captureDir.'/'.$entry;

        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * @param  array{artifact: string, command: list<string>, mode: string, stderr_log: string}  $check
 * @return array{artifact: string, exit_code: int|null, mode: string, status: string, stderr_log: string|null}
 */
function runCheck(array $check, string $password): array
{
    $stderr = '';
    $exitCode = null;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $check['artifact'], 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        $check['command'],
        $descriptors,
        $pipes,
        null,
        [PRIVATE_VALIDATION_PASSWORD_ENV => $password],
    );

    if (! is_resource($process)) {
        throw new RuntimeException(sprintf('Failed to start [%s] validation.', $check['mode']));
    }

    fclose($pipes[0]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($stderr !== '') {
        file_put_contents($check['stderr_log'], $stderr);
    }

    if ($exitCode !== 0) {
        return [
            'artifact' => basename($check['artifact']),
            'exit_code' => $exitCode,
            'mode' => $check['mode'],
            'status' => 'failed',
            'stderr_log' => basename($check['stderr_log']),
        ];
    }

    $payload = file_get_contents($check['artifact']);

    if (! is_string($payload) || $payload === '') {
        return [
            'artifact' => basename($check['artifact']),
            'exit_code' => $exitCode,
            'mode' => $check['mode'],
            'status' => 'failed',
            'stderr_log' => $stderr !== '' ? basename($check['stderr_log']) : null,
        ];
    }

    $decoded = json_decode($payload, true);

    if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        return [
            'artifact' => basename($check['artifact']),
            'exit_code' => $exitCode,
            'mode' => $check['mode'],
            'status' => 'failed',
            'stderr_log' => $stderr !== '' ? basename($check['stderr_log']) : null,
        ];
    }

    if (is_file($check['stderr_log']) && filesize($check['stderr_log']) === 0) {
        @unlink($check['stderr_log']);
    }

    return [
        'artifact' => basename($check['artifact']),
        'exit_code' => $exitCode,
        'mode' => $check['mode'],
        'status' => 'passed',
        'stderr_log' => null,
    ];
}

/**
 * @param  list<array{artifact: string, command: list<string>, mode: string, stderr_log: string}>  $checks
 * @param  list<array{artifact: string, exit_code: int|null, mode: string, status: string, stderr_log: string|null}>  $executed
 */
function appendSkippedChecks(array &$executed, array $checks, string $failedMode): void
{
    $failedIndex = null;

    foreach ($checks as $index => $check) {
        if ($check['mode'] === $failedMode) {
            $failedIndex = $index;
            break;
        }
    }

    if ($failedIndex === null) {
        return;
    }

    foreach (array_slice($checks, $failedIndex + 1) as $check) {
        $executed[] = [
            'artifact' => basename($check['artifact']),
            'exit_code' => null,
            'mode' => $check['mode'],
            'status' => 'skipped',
            'stderr_log' => null,
        ];
    }
}

/**
 * @param  array<string, mixed>  $summary
 */
function writeSummaryArtifacts(array $summary, string $outputDir): void
{
    $summaryJson = $outputDir.'/summary.json';
    $summaryMd = $outputDir.'/release-review.md';

    file_put_contents(
        $summaryJson,
        json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
    );

    $lines = [
        '# Private Live Validation Summary',
        '',
        sprintf('- Status: `%s`', $summary['status']),
        sprintf('- Executed at: `%s`', $summary['executed_at_utc']),
        sprintf('- Git head: `%s`', $summary['git_head'] ?? 'unknown'),
        sprintf('- Output dir: `%s`', $summary['output_dir']),
        sprintf('- Capture dir: `%s`', $summary['capture_dir'] ?? 'disabled'),
        '',
        '## Checks',
        '',
    ];

    foreach (($summary['checks'] ?? []) as $check) {
        $lines[] = sprintf(
            '- `%s`: %s (`%s`)%s',
            $check['mode'],
            $check['status'],
            $check['artifact'],
            $check['stderr_log'] !== null ? sprintf(' stderr `%s`', $check['stderr_log']) : '',
        );
    }

    $lines[] = '';
    $lines[] = '## Scope confirmation';
    $lines[] = '';
    $lines[] = '- read-only validation only';
    $lines[] = '- no dialing or destructive actions performed by this wrapper';

    file_put_contents($summaryMd, implode(PHP_EOL, $lines).PHP_EOL);
}

/**
 * @return array<string, string>
 */
function readEnvFile(string $path): array
{
    $contents = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($contents === false) {
        return [];
    }

    $values = [];

    foreach ($contents as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (str_starts_with($trimmed, 'export ')) {
            $trimmed = substr($trimmed, 7);
        }

        $parts = explode('=', $trimmed, 2);

        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $values[$key] = $value;
    }

    return $values;
}

function parsePositiveInt(mixed $value, int $default): int
{
    if ($value === null || $value === '') {
        return $default;
    }

    $int = (int) $value;

    return $int > 0 ? $int : $default;
}

function parseBool(mixed $value, bool $default): bool
{
    if ($value === null || $value === '') {
        return $default;
    }

    return match (strtolower(trim((string) $value))) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => $default,
    };
}

function resolvePath(string $path, string $baseDirectory): string
{
    if ($path === '') {
        return $baseDirectory;
    }

    if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
        return $path;
    }

    return rtrim($baseDirectory, '/').'/'.$path;
}

function relativePath(string $path, string $baseDirectory): string
{
    $normalizedBase = rtrim($baseDirectory, '/').'/';

    if (str_starts_with($path, $normalizedBase)) {
        return substr($path, strlen($normalizedBase));
    }

    return $path;
}

function gitHeadSha(string $repoRoot): ?string
{
    $command = sprintf(
        'git -C %s rev-parse HEAD 2>/dev/null',
        escapeshellarg($repoRoot),
    );

    $output = shell_exec($command);

    if (! is_string($output)) {
        return null;
    }

    $trimmed = trim($output);

    return $trimmed !== '' ? $trimmed : null;
}

function usage(): string
{
    return <<<'TEXT'
Usage: php bin/freeswitch-private-live-validate.php [options]

Options:
  --env-file=PATH    Path to the private validation env file (default: .env.private-validation)
  --output-dir=PATH  Override the configured artifact directory
  --timeout=SECONDS  Override the configured event validation timeout
  --no-capture       Disable raw frame capture
  --dry-run          Validate configuration and print the planned artifact layout without connecting
  --help             Show this help text

Expected environment source:
  Copy .env.private-validation.example to .env.private-validation and fill in
  the private host, port, and password on a private-network machine.
TEXT;
}
