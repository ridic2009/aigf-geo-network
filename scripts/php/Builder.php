<?php

declare(strict_types=1);

namespace AiGf\Tools;

/**
 * Runs one GEO build: validate -> Cecil -> verify output.
 * Used by scripts/build, scripts/build-all and the CI workflow.
 */
final class Builder
{
    /**
     * @param array<string,string|bool> $options
     *
     * @return array{ok: bool, geo: string, output: string, pages: int, warnings: int}
     */
    public static function build(string $geoCode, array $options = []): array
    {
        $host = Network::host($geoCode);
        $output = (string) ($options['output'] ?? ('dist' . \DIRECTORY_SEPARATOR . $host));
        $warnings = 0;

        /* 1. data + content validation -------------------------------- */
        if (empty($options['skip-validation'])) {
            foreach ([(new DataValidator())->validate(), (new ContentValidator())->validate($geoCode)] as $result) {
                $warnings += \count($result['warnings']);
                if (!Cli::report($result, empty($options['quiet']))) {
                    return ['ok' => false, 'geo' => $geoCode, 'output' => $output, 'pages' => 0, 'warnings' => $warnings];
                }
            }
        }

        /* 2. Cecil build ---------------------------------------------- */
        $configs = [
            Network::buildConfig($geoCode),
        ];
        $generated = Network::path(...explode('/', Prepare::OUTPUT));
        if (is_file($generated)) {
            $configs[] = Prepare::OUTPUT;
        }

        $command = [
            \PHP_BINARY,
            Network::codeRoot() . '/vendor/cecil/cecil/bin/cecil',
            'build',
            '--config=' . implode(',', $configs),
            '--output=' . $output,
            '--quiet',
        ];
        if (!empty($options['drafts'])) {
            $command[] = '--drafts';
        }
        if (!empty($options['optimize'])) {
            $command[] = '--optimize';
        }

        self::removeDirectory(Network::path(...explode(\DIRECTORY_SEPARATOR, $output)));

        [$exitCode, $stdout, $stderr] = self::run($command);
        $buildLog = trim($stdout . "\n" . $stderr);

        // Cecil reports per-page failures on stderr without failing the process:
        // treat any error line as a build failure so production is never updated.
        $hasErrors = $exitCode !== 0 || preg_match('/\b(Unable to|error|Error:)\b/', $buildLog) === 1;
        if ($hasErrors) {
            foreach (explode("\n", $buildLog) as $line) {
                if (trim($line) !== '') {
                    Cli::error(trim($line));
                }
            }

            return ['ok' => false, 'geo' => $geoCode, 'output' => $output, 'pages' => 0, 'warnings' => $warnings];
        }

        /* 3. output verification -------------------------------------- */
        $absolute = Network::path(...explode(\DIRECTORY_SEPARATOR, $output));
        Redirects::write($geoCode, $absolute);
        if (empty($options['skip-validation'])) {
            $result = (new OutputValidator())->validate($geoCode, $absolute, !empty($options['drafts']));
            $warnings += \count($result['warnings']);
            if (!Cli::report($result, empty($options['quiet']))) {
                return ['ok' => false, 'geo' => $geoCode, 'output' => $output, 'pages' => 0, 'warnings' => $warnings];
            }
        }

        return [
            'ok'       => true,
            'geo'      => $geoCode,
            'output'   => $output,
            'pages'    => self::countFiles($absolute, 'html'),
            'warnings' => $warnings,
        ];
    }

    /**
     * A temporary stream for a child process to write into.
     *
     * `tmpfile()` returns false when the system temp directory is not writable
     * — which happened on the publisher host and turned every build into a
     * fatal ValueError from proc_open(), because the descriptor array then
     * contained `false`. The repository's own var/ directory is writable by
     * definition: the process that builds there also writes dist/.
     *
     * @return resource
     */
    private static function capture()
    {
        $stream = @tmpfile();
        if (\is_resource($stream)) {
            return $stream;
        }

        $dir = Network::root() . '/var/tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('No writable temporary directory: neither the system one nor ' . $dir . '.');
        }
        $stream = @fopen(tempnam($dir, 'build-'), 'w+b');
        if (!\is_resource($stream)) {
            throw new \RuntimeException('Cannot open a temporary file in ' . $dir . '.');
        }

        return $stream;
    }

    /**
     * @param string[] $command
     *
     * @return array{0: int, 1: string, 2: string}
     */
    public static function run(array $command, ?string $cwd = null): array
    {
        // File streams avoid a stdout/stderr pipe deadlock on large build errors.
        $out = self::capture();
        $err = self::capture();
        $descriptors = [1 => $out, 2 => $err];
        $process = proc_open($command, $descriptors, $pipes, $cwd ?? Network::root());
        if (!\is_resource($process)) {
            return [1, '', 'Unable to start: ' . implode(' ', $command)];
        }
        $exit = proc_close($process);
        rewind($out); rewind($err);
        $stdout = (string) stream_get_contents($out);
        $stderr = (string) stream_get_contents($err);
        fclose($out); fclose($err);
        return [$exit, $stdout, $stderr];
    }

    public static function countFiles(string $dir, string $extension): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && strtolower($file->getExtension()) === $extension) {
                $count++;
            }
        }

        return $count;
    }

    public static function removeDirectory(string $dir): void
    {
        $resolved = realpath($dir);
        $root = realpath(Network::root());
        if ($resolved !== false && ($root === false || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $root) . '/dist/'))) {
            throw new \RuntimeException('Refusing to remove a directory outside workspace dist/: ' . $dir);
        }
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
