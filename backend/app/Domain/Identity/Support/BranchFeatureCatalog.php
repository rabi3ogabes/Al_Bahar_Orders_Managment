<?php

namespace App\Domain\Identity\Support;

/**
 * The plain-words feature checklist for branch employees, derived from PermissionRegistry::BRANCH_FEATURES.
 * A feature is a named bundle of real permission keys ("Accept and prepare orders" = orders.edit). Nothing here grants anything:
 * BranchEmployeeService turns the chosen features into permissions on the employee's personal branch role.
 */
final class BranchFeatureCatalog
{
    /** @return array<string, list<string>> group id => feature ids, in display order */
    public static function groups(): array
    {
        $out = [];
        foreach (PermissionRegistry::BRANCH_FEATURES as $group => $features) {
            $out[$group] = array_keys($features);
        }

        return $out;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    public static function exists(string $feature): bool
    {
        return in_array($feature, self::ids(), true);
    }

    /** @return array<string, list<string>> feature id => permission keys */
    public static function keyMap(): array
    {
        $out = [];
        foreach (PermissionRegistry::BRANCH_FEATURES as $features) {
            foreach ($features as $id => $def) {
                $out[$id] = $def['keys'];
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> feature id => the features it needs */
    public static function needsMap(): array
    {
        $out = [];
        foreach (PermissionRegistry::BRANCH_FEATURES as $features) {
            foreach ($features as $id => $def) {
                $out[$id] = $def['needs'] ?? [];
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> preset id => feature ids */
    public static function presets(): array
    {
        return PermissionRegistry::BRANCH_PRESETS;
    }

    /** Every permission key the checklist can ever switch on. @return list<string> */
    public static function offeredKeys(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::keyMap()))));
    }

    /**
     * The chosen features plus every feature they cannot work without (ticking "Create orders" also ticks "See orders").
     * Unknown ids are dropped. The result is in catalog order and has no duplicates.
     *
     * @param  array<int, mixed>  $ids
     * @return list<string>
     */
    public static function withNeeds(array $ids): array
    {
        $needs = self::needsMap();
        $wanted = [];
        $queue = array_values(array_filter($ids, 'is_string'));
        while ($queue !== []) {
            $id = array_shift($queue);
            if (! isset($needs[$id]) || isset($wanted[$id])) {
                continue;
            }
            $wanted[$id] = true;
            array_push($queue, ...$needs[$id]);
        }

        return array_values(array_filter(self::ids(), fn (string $id) => isset($wanted[$id])));
    }

    /**
     * Permission keys the features switch on.
     *
     * @param  list<string>  $features
     * @return list<string>
     */
    public static function permissionKeys(array $features): array
    {
        $map = self::keyMap();
        $keys = [];
        foreach ($features as $id) {
            foreach ($map[$id] ?? [] as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * The features that are fully on for a set of permission keys (every key of the feature is held).
     *
     * @param  array<int, string>  $permissionKeys
     * @return list<string>
     */
    public static function featuresFor(array $permissionKeys): array
    {
        $held = array_flip($permissionKeys);

        return array_values(array_filter(self::ids(), function (string $id) use ($held) {
            foreach (self::keyMap()[$id] as $key) {
                if (! isset($held[$key])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** The preset whose features are EXACTLY these (order does not matter), or null for a custom mix. @param array<int, string> $features */
    public static function presetFor(array $features): ?string
    {
        $have = array_values(array_unique($features));
        sort($have);
        foreach (self::presets() as $id => $set) {
            $want = array_values(array_unique($set));
            sort($want);
            if ($want === $have) {
                return $id;
            }
        }

        return null;
    }

    public static function groupLabel(string $group): string
    {
        return __('branch_access.group_'.$group);
    }

    public static function label(string $feature): string
    {
        return __('branch_access.'.$feature);
    }

    public static function hint(string $feature): string
    {
        return __('branch_access.'.$feature.'_hint');
    }

    public static function presetLabel(string $preset): string
    {
        return __('branch_access.preset_'.$preset);
    }
}
