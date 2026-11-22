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
            $rule['module'] = $rule['module'] ?? null;
            $rule['exact'] = (bool) ($rule['exact'] ?? false);
            $rule['ajax'] = (bool) ($rule['ajax'] ?? false);
            $rule['modules'] = array_values(array_map('strval', $rule['modules'] ?? []));
            $rule['methods'] = array_values(array_map('strtoupper', $rule['methods'] ?? []));
            $rule['module_levels'] = (array) ($rule['module_levels'] ?? []);
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
    public function resolve(string $path, ?string $method = null): ?array
    {
        $segments = self::segments($path);
        $method = $method === null ? null : strtoupper($method);

        foreach ($this->rules as $rule) {
            if ($method !== null && $rule['methods'] && !in_array($method, $rule['methods'], true)) {
                continue;
            }

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
    public function decide(string $path, bool $isOwner, array $modules, array $levels, bool $ajax, string $method = 'GET'): array
    {
        if ($isOwner) {
            return ['allowed' => true, 'rule' => null, 'reason' => 'owner'];
        }

        $rule = $this->resolve($path, $method);
        $safeMethod = in_array(strtoupper($method), ['GET', 'HEAD'], true);

        if ($rule === null) {
            // Legacy modules may have pages/forms without a rule, but an unknown
            // mutation must never be allowed just because it is not mapped.
            if (!$safeMethod) {
                return ['allowed' => false, 'rule' => null, 'reason' => 'unmapped-mutation'];
            }

            return ['allowed' => true, 'rule' => null, 'reason' => 'unmapped'];
        }
        if ($rule['module'] === null && !$rule['modules']) {
            return ['allowed' => true, 'rule' => $rule, 'reason' => 'open'];
        }
        $allowedModules = $rule['modules'] ?: [$rule['module']];
        $matchedModules = array_values(array_intersect($allowedModules, $modules));
        if (!$matchedModules) {
            return ['allowed' => false, 'rule' => $rule, 'reason' => 'module'];
        }

        if ($rule['module_levels']) {
            $levelSatisfied = false;
            foreach ($matchedModules as $matchedModule) {
                $requiredLevel = $rule['module_levels'][$matchedModule] ?? null;
                if ($requiredLevel === null || in_array($requiredLevel, $levels, true)) {
                    $levelSatisfied = true;
                    break;
                }
            }

            if (!$levelSatisfied) {
                return ['allowed' => false, 'rule' => $rule, 'reason' => 'level'];
            }
        } elseif ($rule['level'] !== null && !in_array($rule['level'], $levels, true)) {
            return ['allowed' => false, 'rule' => $rule, 'reason' => 'level'];
        }
        // AJAX only waives the page-level gate after the module/level check:
        // an unprivileged user must not reach a module endpoint by adding the
        // X-Requested-With header.
        if ($ajax && !$rule['ajax']) {
            return ['allowed' => true, 'rule' => $rule, 'reason' => 'ajax-not-enforced'];
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
