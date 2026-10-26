<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Support;

use FreshDoktor\MinecraftPerformanceAnalyser\Services\ProfileAnalyzer;

/**
 * Readable names for the frames of a profile: Fabric runs with net/minecraft/class_3218.method_18765,
 * Forge with m_8793_ or func_72835_b; this turns them into ServerLevel.tick and the like. Built by
 * MappingFiles, one per mapping and Minecraft version, kept by MethodNameService.
 *
 * Only what is shown changes (the top methods and the flamegraph); the analysis itself (idle time,
 * owners) works on the names the server runs with, so the original name stays next to the readable one.
 *
 * Plain PHP, tested without Laravel.
 */
final class MethodNames
{
    /** Version of the stored form, a new one makes MethodNameService build the names again. */
    public const FORMAT = 1;

    /** Ids in a frame per mapping. A mod overriding a method of Minecraft has its id too. */
    protected const METHOD_PATTERNS = [
        ProfileAnalyzer::MAPPING_INTERMEDIARY => '/(?<![A-Za-z0-9_])(?:method|comp)_\d+(?![A-Za-z0-9_])/',
        ProfileAnalyzer::MAPPING_SRG => '/(?<![A-Za-z0-9_])m_\d+_(?![A-Za-z0-9_])/',
        ProfileAnalyzer::MAPPING_MCP => '/(?<![A-Za-z0-9_])func_\d+_[a-zA-Z]+_?(?![A-Za-z0-9_])/',
    ];

    /**
     * Classes of Minecraft, with ids only in intermediary: net/minecraft/class_7159$class_7161, also inner
     * classes of named ones (net/minecraft/server/MinecraftServer$class_6414) and anonymous ones ($1).
     * Not the lambda classes of the JVM ($$Lambda), they keep the name of the class they are in.
     */
    protected const CLASS_PATTERN = '#net/minecraft/(?:\w+/)*\w+(?:\$\w+)*#';

    /**
     * @param  array<string, string>  $classes  id → name with slashes, intermediary only
     * @param  array<string, string>  $methods  id → name
     */
    public function __construct(
        public readonly string $mapping,
        protected array $classes,
        protected array $methods,
    ) {}

    public static function supports(?string $mapping): bool
    {
        return $mapping !== null && isset(self::METHOD_PATTERNS[$mapping]);
    }

    public function translate(string $frame): string
    {
        if ($this->classes !== [] && str_contains($frame, 'class_')) {
            $frame = (string) preg_replace_callback(self::CLASS_PATTERN, fn (array $m) => $this->className($m[0]), $frame);
        }

        return (string) preg_replace_callback(self::METHOD_PATTERNS[$this->mapping], fn (array $m) => $this->methods[$m[0]] ?? $m[0], $frame);
    }

    /**
     * The analysis of ProfileAnalyzer with readable names in the top methods (frame, the original in
     * raw_frame) and the flamegraph (frames [name, owner, original]). Frames without an id stay as they are.
     *
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    public function apply(array $analysis): array
    {
        foreach ($analysis['top_methods'] ?? [] as $i => $method) {
            $readable = $this->translate($method['frame']);

            if ($readable !== $method['frame']) {
                $analysis['top_methods'][$i]['raw_frame'] = $method['frame'];
                $analysis['top_methods'][$i]['frame'] = $readable;
            }
        }

        foreach ($analysis['flame']['frames'] ?? [] as $i => $frame) {
            $readable = $this->translate($frame[0]);

            if ($readable !== $frame[0]) {
                $analysis['flame']['frames'][$i] = [$readable, $frame[1], $frame[0]];
            }
        }

        return $analysis;
    }

    public function count(): int
    {
        return count($this->methods);
    }

    /**
     * @return array{format: int, mapping: string, classes: array<string, string>, methods: array<string, string>}
     */
    public function toArray(): array
    {
        return ['format' => self::FORMAT, 'mapping' => $this->mapping, 'classes' => $this->classes, 'methods' => $this->methods];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $mapping = $data['mapping'] ?? null;

        if (($data['format'] ?? null) !== self::FORMAT || !is_string($mapping) || !self::supports($mapping) || !is_array($data['methods'] ?? null) || !is_array($data['classes'] ?? [])) {
            return null;
        }

        return new self($mapping, $data['classes'] ?? [], $data['methods']);
    }

    /**
     * An inner class Minecraft's mappings don't name (e.g. one added by a mixin) keeps its id, after
     * the name of the class around it.
     */
    protected function className(string $id): string
    {
        $suffix = '';

        while (!isset($this->classes[$id])) {
            $dollar = strrpos($id, '$');

            if ($dollar === false) {
                return $id.$suffix;
            }

            $suffix = substr($id, $dollar).$suffix;
            $id = substr($id, 0, $dollar);
        }

        return $this->classes[$id].$suffix;
    }
}
