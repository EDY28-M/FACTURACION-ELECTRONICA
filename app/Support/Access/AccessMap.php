<?php

namespace App\Support\Access;

/**
 * Pure (framework free) decision logic for module/level access, driven by config/permissions.php.
 */
class AccessMap
{
    /** @var array */
    private $rules = [];

    /** @var string[] */
    private $menu;

    public function __construct(array $config)
    {
        $this->menu = $config['menu'] ?? [];

        foreach ($config['rules'] ?? [] as $rule) {
            $rule['segments'] = self::segments($rule['path']);
            $rule['level'] = $rule['level'] ?? null;
            $rule['exact'] = (bool) ($rule['exact'] ?? false);
            $rule['ajax'] = (bool) ($rule['ajax'] ?? false);
            $this->rules[] = $rule;
        }

        // Most specific first; "exact" wins over the prefix rule with the same depth.
        usort($this->rules, function ($a, $b) {
            return [count($b['segments']), (int) $b['exact']] <=> [count($a['segments']), (int) $a['exact']];
        });
    }

    public static function fromConfig(): self
    {
        static $instance = null;

        if ($instance === null) {
            $instance = new self((array) config('permissions'));
        }

        return $instance;
    }

    public static function segments(string $path): array
    {
        $path = trim(strtolower($path), '/');

        return $path === '' ? [] : explode('/', $path);
    }

    /**
     * First rule matching the path, or null when the path is not mapped.
     */
    public function resolve(string $path): ?array
    {
        $segments = self::segments($path);

        foreach ($this->rules as $rule) {
            $count = count($rule['segments']);

            if ($count > count($segments)) {
                continue;
            }
            if ($rule['exact'] && $count !== count($segments)) {
                continue;
            }
            if (array_slice($segments, 0, $count) === $rule['segments']) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param string[] $modules module values the user has
     * @param string[] $levels  level values the user has
     *
     * @return array{allowed: bool, rule: ?array, reason: string}
     */
    public function decide(string $path, bool $isOwner, array $modules, array $levels, bool $ajax): array
    {
        if ($isOwner) {
            return ['allowed' => true, 'rule' => null, 'reason' => 'owner'];
        }

        $rule = $this->resolve($path);

        if ($rule === null) {
            return ['allowed' => true, 'rule' => null, 'reason' => 'unmapped'];
        }
        if ($rule['module'] === null) {
            return ['allowed' => true, 'rule' => $rule, 'reason' => 'open'];
        }
        if ($ajax && !$rule['ajax']) {
            return ['allowed' => true, 'rule' => $rule, 'reason' => 'ajax-not-enforced'];
        }
        if (!in_array($rule['module'], $modules, true)) {
            return ['allowed' => false, 'rule' => $rule, 'reason' => 'module'];
        }
        if ($rule['level'] !== null && !in_array($rule['level'], $levels, true)) {
            return ['allowed' => false, 'rule' => $rule, 'reason' => 'level'];
        }

        return ['allowed' => true, 'rule' => $rule, 'reason' => 'granted'];
    }

    /**
     * First landing page (sidebar order) the user can open, as a path without leading slash.
     */
    public function firstAllowedPath(array $modules, array $levels): ?string
    {
        foreach ($this->menu as $path) {
            $decision = $this->decide($path, false, $modules, $levels, false);

            if ($decision['rule'] !== null && $decision['allowed']) {
                return $path;
            }
        }

        return null;
    }
}
