<?php
declare(strict_types=1);
namespace AiGf\Tools;
use Symfony\Component\Yaml\Yaml;

final class Theme
{
    public static function css(string $site): string
    {
        $config = Network::geo($site);
        $name = $config['site_identity']['theme'];
        Network::assertId($name);
        $theme = Yaml::parseFile(Network::path('config', 'themes', $name . '.yml'));
        $tokens = array_replace($theme['tokens'] ?? [], $config['appearance']['tokens'] ?? []);
        $css = ':root{';
        foreach ($tokens as $key => $value) {
            $color = preg_match('/^c-[a-z-]+$/D', $key) && preg_match('/^#[a-fA-F0-9]{6}$/D', (string) $value);
            $size = in_array($key, ['radius', 'container', 'container-narrow'], true) && preg_match('/^\d{1,4}(px|rem)$/D', (string) $value);
            if (!$color && !$size) { throw new \InvalidArgumentException('Недопустимый параметр темы: ' . $key); }
            $css .= '--' . $key . ':' . $value . ';';
        }
        return $css . '}';
    }
    public static function asset(string $site): string { return 'theme.' . substr(hash('sha256', self::css($site)), 0, 16) . '.css'; }
}
