<?php
declare(strict_types=1);
namespace AiGf\Tools;

final class StudioAuth
{
    public static function create(string $login, string $password, string $role, array $sites, bool $bootstrap = false): void
    {
        Network::assertId($login);
        if (!in_array($role, ['author', 'editor', 'admin'], true)) { throw new \InvalidArgumentException('Роль: author, editor или admin.'); }
        if (strlen($password) < 12 || strlen($password) > 72) { throw new \InvalidArgumentException('Пароль должен содержать от 12 до 72 байт.'); }
        foreach ($sites as $site) { if ($site !== '*' && !Network::exists($site)) { throw new \InvalidArgumentException('Неизвестный сайт: ' . $site); } }
        StudioStore::transaction(function (&$state) use ($login, $password, $role, $sites, $bootstrap) {
            if ($bootstrap && $state['users'] !== []) { throw new \RuntimeException('Первый администратор уже создан.', 409); }
            if (isset($state['users'][$login])) { throw new \RuntimeException('Пользователь уже существует.'); }
            $state['users'][$login] = ['login' => $login, 'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role, 'sites' => $sites, 'enabled' => true];
            StudioStore::audit($state, 'cli', 'user.created', ['login' => $login, 'role' => $role]);
        });
    }

    public static function update(array $actor, string $login, string $role, array $sites, bool $enabled): void
    {
        self::requireAdmin($actor);
        if (!in_array($role, ['author', 'editor', 'admin'], true)) { throw new \InvalidArgumentException('Неизвестная роль.'); }
        foreach ($sites as $site) { if ($site !== '*' && !Network::exists($site)) { throw new \InvalidArgumentException('Неизвестный сайт.'); } }
        StudioStore::transaction(function (&$state) use ($actor, $login, $role, $sites, $enabled) {
            if (!isset($state['users'][$login])) { throw new \InvalidArgumentException('Пользователь не найден.'); }
            $state['users'][$login] = array_replace($state['users'][$login], ['role' => $role, 'sites' => $sites, 'enabled' => $enabled]);
            if (!array_filter($state['users'], fn ($u) => $u['enabled'] && $u['role'] === 'admin')) { throw new \RuntimeException('Должен остаться хотя бы один активный администратор.'); }
            StudioStore::audit($state, $actor['login'], 'user.updated', ['login' => $login, 'role' => $role, 'sites' => $sites, 'enabled' => $enabled]);
        });
    }

    public static function login(string $login, string $password, string $ip): ?array
    {
        // Persist failed attempts even when authentication fails. No exception rollback.
        return StudioStore::transaction(function (&$state) use ($login, $password, $ip) {
            $key = hash('sha256', $ip);
            $attempt = $state['attempts'][$key] ?? ['count' => 0, 'start' => time()];
            if (time() - $attempt['start'] > 900) { $attempt = ['count' => 0, 'start' => time()]; }
            if ($attempt['count'] >= 15) { return null; }
            $user = $state['users'][$login] ?? null;
            $valid = password_verify($password, $user['password'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            if (!$user || !$valid || !$user['enabled']) {
                $attempt['count']++; $state['attempts'][$key] = $attempt; return null;
            }
            unset($state['attempts'][$key]);
            StudioStore::audit($state, $login, 'login');
            unset($user['password']); return $user;
        });
    }

    public static function user(string $login): ?array
    {
        return StudioStore::transaction(function (&$state) use ($login) {
            $user = $state['users'][$login] ?? null;
            if (!$user || !$user['enabled']) { return null; }
            unset($user['password']); return $user;
        });
    }

    public static function requireSite(array $user, string $site, bool $editor = false): void
    {
        if (!Network::exists($site)) { throw new \InvalidArgumentException('Сайт не найден.'); }
        if (!in_array('*', $user['sites'], true) && !in_array($site, $user['sites'], true)) { throw new \RuntimeException('Нет доступа к этому сайту.', 403); }
        if ($editor && !in_array($user['role'], ['editor', 'admin'], true)) { throw new \RuntimeException('Действие доступно выпускающему редактору.', 403); }
    }

    public static function requireAdmin(array $user): void
    {
        if ($user['role'] !== 'admin') { throw new \RuntimeException('Действие доступно администратору.', 403); }
    }
}
