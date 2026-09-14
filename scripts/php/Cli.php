<?php

declare(strict_types=1);

namespace AiGf\Tools;

final class Cli
{
    public static function bootstrap(): void
    {
        require_once \dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (\PHP_OS_FAMILY === 'Windows') {
            @setlocale(\LC_ALL, 'C');
        }
    }

    public static function line(string $message = ''): void
    {
        fwrite(\STDOUT, $message . \PHP_EOL);
    }

    public static function title(string $message): void
    {
        self::line('');
        self::line('== ' . $message);
    }

    public static function ok(string $message): void
    {
        self::line('  [OK] ' . $message);
    }

    public static function info(string $message): void
    {
        self::line('  ' . $message);
    }

    public static function warn(string $message): void
    {
        self::line('  [WARN] ' . $message);
    }

    public static function error(string $message): void
    {
        fwrite(\STDERR, '  [ERROR] ' . $message . \PHP_EOL);
    }

    /**
     * Prints a validation result and returns true when it passed.
     */
    public static function report(array $result, bool $showWarnings = true): bool
    {
        foreach ($result['errors'] as $item) {
            self::error($item['where'] . ': ' . $item['message']);
        }
        if ($showWarnings) {
            foreach ($result['warnings'] as $item) {
                self::warn($item['where'] . ': ' . $item['message']);
            }
        }

        return $result['errors'] === [];
    }

    /**
     * @param string[] $argv
     *
     * @return array{0: string[], 1: array<string,string|bool>} positional args, options
     */
    public static function parse(array $argv): array
    {
        $args = [];
        $options = [];
        foreach (\array_slice($argv, 1) as $token) {
            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
                if (str_contains($token, '=')) {
                    [$k, $v] = explode('=', $token, 2);
                    $options[$k] = $v;
                } else {
                    $options[$token] = true;
                }
                continue;
            }
            $args[] = $token;
        }

        return [$args, $options];
    }

    /**
     * Resolves the GEO codes a command should act on.
     *
     * @param string[] $args
     *
     * @return string[]
     */
    public static function resolveGeos(array $args): array
    {
        if ($args === [] || $args === ['all']) {
            return Network::codes(true);
        }

        $codes = [];
        foreach ($args as $code) {
            $code = strtolower($code);
            if (!Network::exists($code)) {
                self::error(\sprintf('Unknown GEO "%s". Available: %s', $code, implode(', ', Network::codes(false))));
                exit(1);
            }
            $codes[] = $code;
        }

        return $codes;
    }
}
