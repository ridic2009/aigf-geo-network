<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use AiGf\Tools\{Cli, StudioAuth, StudioWorker};
[$args, $options] = Cli::parse($argv);
try {
    switch ($args[0] ?? '') {
        case 'user-add':
            $password = getenv('STUDIO_PASSWORD');
            if ($password === false) { fwrite(STDOUT, "Пароль (12–72 байт; ввод видим в обычном терминале): "); $password = rtrim((string) fgets(STDIN), "\r\n"); }
            StudioAuth::create($args[1] ?? '', $password, (string) ($options['role'] ?? 'author'), explode(',', (string) ($options['sites'] ?? '*')));
            Cli::ok('Пользователь создан.'); break;
        case 'worker':
            $job = StudioWorker::once(!empty($options['deploy']));
            Cli::info($job ? $job['id'] . ': ' . $job['state'] : 'Очередь пуста или worker уже работает.');
            exit(($job['state'] ?? '') === 'publication_failed' ? 1 : 0);
        case 'serve':
            $port = (int) ($options['port'] ?? 8787);
            if ($port < 1024 || $port > 65535) { throw new RuntimeException('Недопустимый порт.'); }
            $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/studio/public', dirname(__DIR__) . '/studio/public/index.php'], [STDIN, STDOUT, STDERR], $pipes);
            exit(is_resource($process) ? proc_close($process) : 1);
        default:
            Cli::line("php scripts/studio.php user-add LOGIN --role=admin --sites=*\nphp scripts/studio.php serve --port=8787\nphp scripts/studio.php worker             # build and archive only\nphp scripts/studio.php worker --deploy    # deploy to configured VPSs");
    }
} catch (Throwable $e) { Cli::error($e->getMessage()); exit(1); }
