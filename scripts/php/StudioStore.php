<?php
declare(strict_types=1);
namespace AiGf\Tools;

/** Small-team transactional store. State and locks live outside the public web root. */
final class StudioStore
{
    public static function dir(): string { return getenv('STUDIO_DATA') ?: Network::root() . '/.studio'; }
    public static function transaction(callable $action): mixed
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new \RuntimeException('Cannot create Studio data directory.'); }
        $lock = fopen($dir . '/state.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new \RuntimeException('Cannot lock Studio state.'); }
        try {
            $file = $dir . '/state.json';
            $state = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : ['users' => [], 'documents' => [], 'jobs' => [], 'audit' => [], 'attempts' => []];
            $result = $action($state);
            $tmp = $dir . '/state.' . bin2hex(random_bytes(8)) . '.tmp';
            if (file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) { throw new \RuntimeException('Cannot write Studio state.'); }
            chmod($tmp, 0600);
            if (!rename($tmp, $file)) { throw new \RuntimeException('Cannot commit Studio state.'); }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function audit(array &$state, string $actor, string $action, array $details = []): void
    {
        $state['audit'][] = ['at' => gmdate('c'), 'actor' => $actor, 'action' => $action] + $details;
    }
}
