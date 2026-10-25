<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

/**
 * Groups a class histogram of the bridge (GET /histogram, docs/API.md at wing commit 49f32b3) by mod: every
 * class goes to the owner of its element type, so [Lnet.minecraft…StateHolder; counts for Minecraft and
 * [B (byte arrays) for the JVM. Classes beyond the bridge's limit only come as a sum (omitted).
 *
 * Plain PHP, tested without Laravel.
 *
 * @phpstan-import-type Owner from ModPackageIndex
 */
final class HistogramAnalyzer
{
    /** Classes listed per owner, the largest first. */
    public const CLASSES_PER_OWNER = 100;

    /**
     * @param  array<string, mixed>  $histogram  answer of GET /histogram
     * @return array<string, mixed>
     */
    public function analyze(array $histogram, ModPackageIndex $index): array
    {
        $totalBytes = (int) ($histogram['total']['bytes'] ?? 0);
        $groups = [];
        $ownerCache = [];

        foreach ($histogram['classes'] ?? [] as $class) {
            if (!is_array($class)) {
                continue;
            }

            $name = (string) ($class['name'] ?? '');
            $elementType = (string) ($class['element_type'] ?? $name);
            $key = $ownerCache[$elementType] ??= $index->ownerOfClass($elementType);

            $groups[$key] ??= [...$index->owner($key), 'bytes' => 0, 'instances' => 0, 'class_count' => 0, 'classes' => []];
            $groups[$key]['bytes'] += (int) ($class['bytes'] ?? 0);
            $groups[$key]['instances'] += (int) ($class['instances'] ?? 0);
            $groups[$key]['class_count']++;
            $groups[$key]['classes'][] = [
                'name' => $name,
                'readable' => self::readableName($elementType, (int) ($class['array_depth'] ?? 0)),
                'module' => is_string($class['module'] ?? null) ? $class['module'] : null,
                'instances' => (int) ($class['instances'] ?? 0),
                'bytes' => (int) ($class['bytes'] ?? 0),
            ];
        }

        $owners = array_values($groups);

        foreach ($owners as &$owner) {
            usort($owner['classes'], fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);
            $owner['classes'] = array_slice($owner['classes'], 0, self::CLASSES_PER_OWNER);
            $owner['percent'] = $totalBytes > 0 ? round($owner['bytes'] / $totalBytes * 100, 2) : 0.0;
        }
        unset($owner);

        usort($owners, fn (array $a, array $b) => [$b['bytes'], $a['name']] <=> [$a['bytes'], $b['name']]);

        $omitted = [
            'classes' => (int) ($histogram['omitted']['classes'] ?? 0),
            'instances' => (int) ($histogram['omitted']['instances'] ?? 0),
            'bytes' => (int) ($histogram['omitted']['bytes'] ?? 0),
        ];

        return [
            'sampled_at' => (int) ($histogram['sampled_at'] ?? 0),
            'duration_ms' => (float) ($histogram['duration_ms'] ?? 0),
            'all' => (bool) ($histogram['all'] ?? true),
            'limit' => (int) ($histogram['limit'] ?? 0),
            'total' => [
                'classes' => (int) ($histogram['total']['classes'] ?? 0),
                'instances' => (int) ($histogram['total']['instances'] ?? 0),
                'bytes' => $totalBytes,
            ],
            'omitted' => [...$omitted, 'percent' => $totalBytes > 0 ? round($omitted['bytes'] / $totalBytes * 100, 2) : 0.0],
            'owners' => $owners,
        ];
    }

    /**
     * byte[], net.minecraft…StateHolder[][] instead of [B and [[Lnet.minecraft…StateHolder;.
     */
    public static function readableName(string $elementType, int $arrayDepth): string
    {
        return $elementType.str_repeat('[]', max(0, $arrayDepth));
    }
}
