<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Support;

use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\MappingException;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ProfileAnalyzer;

/**
 * Reads the mapping files MethodNameService downloads and joins them into MethodNames, from the names a
 * server runs with to Mojang's (the names of the idle table in ProfileAnalyzer::MAPPINGS):
 *
 * - intermediary (Fabric, Quilt): Fabric's intermediary (tiny v2, official → intermediary) joined with
 *   Mojang's server mappings (ProGuard, mojmap → official) over the obfuscated name and descriptor.
 *   Classes are translated too, they are named class_1234 at runtime.
 * - srg (Forge 1.17 to 1.20, NeoForge 1.20.1): MCPConfig's joined.tsrg (official → srg) joined with
 *   Mojang's server mappings the same way. Only methods, the classes already have Mojang's names.
 * - mcp (Forge up to 1.16): MCP's methods.csv (srg → MCP name). Only methods, the classes have MCP's
 *   names; Mojang has no mappings before 1.14.4.
 *
 * The ids (method_1234, m_1234_, func_1234_a) name a method across all classes, so one map from id to
 * name is enough; methods overriding each other share the id.
 *
 * Plain PHP, tested without Laravel.
 */
final class MappingFiles
{
    /** Java's primitive types in ProGuard's notation and in a descriptor. */
    protected const PRIMITIVES = [
        'void' => 'V', 'boolean' => 'Z', 'byte' => 'B', 'char' => 'C', 'short' => 'S',
        'int' => 'I', 'long' => 'J', 'float' => 'F', 'double' => 'D',
    ];

    public static function intermediary(string $tiny, string $proguard): MethodNames
    {
        $mojang = self::proguard($proguard);
        $classes = [];
        $methods = [];
        $class = null;

        foreach (self::lines($tiny) as $i => $line) {
            if ($i === 0) {
                // tiny	2	0	official	intermediary
                if (!str_starts_with($line, "tiny\t2\t") || !str_ends_with($line, "\tofficial\tintermediary")) {
                    throw new MappingException('intermediary is not tiny v2 from official to intermediary');
                }

                continue;
            }

            $parts = explode("\t", $line);

            if ($parts[0] === 'c' && count($parts) >= 3) {
                // c	<official>	<intermediary>
                $class = $parts[1];

                if (isset($mojang['classes'][$class]) && $mojang['classes'][$class] !== $parts[2]) {
                    $classes[$parts[2]] = $mojang['classes'][$class];
                }
            } elseif ($parts[0] === '' && ($parts[1] ?? null) === 'm' && count($parts) >= 5 && $class !== null) {
                // 	m	<descriptor>	<official>	<intermediary>
                $name = $mojang['methods'][$class][$parts[3].$parts[2]] ?? null;

                if ($name !== null && $name !== $parts[4]) {
                    $methods[$parts[4]] = $name;
                }
            }
        }

        return self::names(ProfileAnalyzer::MAPPING_INTERMEDIARY, $classes, $methods);
    }

    /**
     * MCPConfig's joined.tsrg in tsrg2 ("tsrg2 obf srg id", from 1.17). Before 1.17 Forge runs with
     * func_ ids, which MCP's names cover (mcp()).
     */
    public static function srg(string $tsrg, string $proguard): MethodNames
    {
        $mojang = self::proguard($proguard);
        $methods = [];
        $class = null;

        foreach (self::lines($tsrg) as $i => $line) {
            if ($i === 0) {
                if (!str_starts_with($line, 'tsrg2 obf srg')) {
                    throw new MappingException('joined.tsrg is not tsrg2 from obf to srg');
                }

                continue;
            }

            // Parameters, "static" and the like are indented twice.
            if (str_starts_with($line, "\t\t")) {
                continue;
            }

            $parts = explode(' ', trim($line));

            if ($line[0] !== "\t") {
                $class = $parts[0];

                continue;
            }

            // Methods have a descriptor: <obf> <descriptor> <srg> <id>, fields don't.
            if ($class === null || count($parts) < 3 || !str_starts_with($parts[1], '(')) {
                continue;
            }

            $name = $mojang['methods'][$class][$parts[0].$parts[1]] ?? null;

            if ($name !== null && $name !== $parts[2] && preg_match('/^m_\d+_$/', $parts[2])) {
                $methods[$parts[2]] = $name;
            }
        }

        if ($methods === []) {
            throw new MappingException('joined.tsrg has no methods that match the server mappings');
        }

        return self::names(ProfileAnalyzer::MAPPING_SRG, [], $methods);
    }

    /**
     * MCP's methods.csv: searge,name,side,desc (the description may be quoted and hold commas).
     */
    public static function mcp(string $csv): MethodNames
    {
        $methods = [];

        foreach (self::lines($csv) as $i => $line) {
            if ($i === 0) {
                if (!str_starts_with($line, 'searge,name')) {
                    throw new MappingException('methods.csv has no header searge,name');
                }

                continue;
            }

            [$searge, $name] = array_pad(explode(',', $line, 3), 2, '');

            if (preg_match('/^func_\d+_[a-zA-Z]+_?$/', $searge) && preg_match('/^[A-Za-z_$][\w$]*$/', $name)) {
                $methods[$searge] = $name;
            }
        }

        return self::names(ProfileAnalyzer::MAPPING_MCP, [], $methods);
    }

    /**
     * Mojang's server mappings (ProGuard): classes official → mojmap (as in a frame, with slashes), and
     * the methods of each official class by official name and descriptor.
     *
     * @return array{classes: array<string, string>, methods: array<string, array<string, string>>}
     */
    public static function proguard(string $text): array
    {
        $lines = self::lines($text);

        // First the classes, a descriptor may name a class further down.
        $toOfficial = [];
        $classes = [];

        foreach ($lines as $line) {
            if ($line[0] !== ' ' && $line[0] !== '#' && preg_match('/^(\S+) -> (\S+):$/', $line, $m)) {
                $toOfficial[$m[1]] = strtr($m[2], '.', '/');
                $classes[strtr($m[2], '.', '/')] = strtr($m[1], '.', '/');
            }
        }

        if ($classes === []) {
            throw new MappingException('the server mappings have no classes');
        }

        $methods = [];
        $class = null;

        foreach ($lines as $line) {
            if ($line[0] !== ' ') {
                $class = preg_match('/^(\S+) -> (\S+):$/', $line, $m) ? strtr($m[2], '.', '/') : $class;

                continue;
            }

            // [from:to:]<return> <name>(<arguments>)[:from[:to]] -> <official>; fields have no parentheses.
            if ($class === null || !preg_match('/^\s+(?:\d+:\d+:)?(\S+) ([^\s(]+)\(([^)]*)\)(?::\d+)*\s+->\s+(\S+)$/', $line, $m)) {
                continue;
            }

            [, $return, $name, $arguments, $official] = $m;

            if ($name === '<init>' || $name === '<clinit>') {
                continue;
            }

            $descriptor = '(';

            foreach ($arguments === '' ? [] : explode(',', $arguments) as $type) {
                $descriptor .= self::descriptor($type, $toOfficial);
            }

            $methods[$class][$official.$descriptor.')'.self::descriptor($return, $toOfficial)] = $name;
        }

        return ['classes' => $classes, 'methods' => $methods];
    }

    /**
     * The versions in a maven-metadata.xml.
     *
     * @return list<string>
     */
    public static function mavenVersions(string $xml): array
    {
        preg_match_all('#<version>\s*([^<\s]+)\s*</version>#', $xml, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The MCPConfig for a Minecraft version: the newest build (1.20.1-20230612.114412), else the one
     * named after the version alone. The srg ids stay the same across builds.
     *
     * @param  list<string>  $versions
     */
    public static function mcpConfigVersion(array $versions, string $minecraft): ?string
    {
        $builds = array_values(array_filter($versions, fn (string $v) => preg_match('/^'.preg_quote($minecraft, '/').'-\d{8}\.\d{6}$/', $v)));

        if ($builds !== []) {
            sort($builds);

            return $builds[count($builds) - 1];
        }

        return in_array($minecraft, $versions, true) ? $minecraft : null;
    }

    /**
     * The MCP names for a Minecraft version: a stable release (39-1.12) before a snapshot
     * (20180814-1.12), the newest of each. MCP names many of them after the minor version only
     * (1.12 for 1.12.2), and its ids stay the same across versions, so that one serves as well, else
     * one of another patch (1.16.5 for 1.16.4).
     *
     * @param  list<string>  $stable  versions of mcp_stable
     * @param  list<string>  $snapshot  versions of mcp_snapshot
     * @return array{channel: 'stable'|'snapshot', version: string}|null
     */
    public static function mcpVersion(array $stable, array $snapshot, string $minecraft): ?array
    {
        $minor = preg_quote(implode('.', array_slice(explode('.', $minecraft), 0, 2)), '/');

        foreach (array_unique([preg_quote($minecraft, '/'), $minor, $minor.'\.\d+']) as $suffix) {
            foreach (['stable' => $stable, 'snapshot' => $snapshot] as $channel => $versions) {
                $numbers = [];

                foreach ($versions as $version) {
                    if (preg_match('/^(\d+)-'.$suffix.'$/', $version, $m)) {
                        $numbers[$version] = (int) $m[1];
                    }
                }

                if ($numbers !== []) {
                    arsort($numbers);

                    return ['channel' => $channel, 'version' => (string) array_key_first($numbers)];
                }
            }
        }

        return null;
    }

    /**
     * java.lang.String[] → [Ljava/lang/String; with the official name of Minecraft's own classes.
     *
     * @param  array<string, string>  $toOfficial
     */
    protected static function descriptor(string $type, array $toOfficial): string
    {
        $arrays = '';

        while (str_ends_with($type, '[]')) {
            $arrays .= '[';
            $type = substr($type, 0, -2);
        }

        return $arrays.(self::PRIMITIVES[$type] ?? 'L'.($toOfficial[$type] ?? strtr($type, '.', '/')).';');
    }

    /**
     * @param  array<string, string>  $classes
     * @param  array<string, string>  $methods
     */
    protected static function names(string $mapping, array $classes, array $methods): MethodNames
    {
        if ($methods === []) {
            throw new MappingException("no method names for $mapping");
        }

        return new MethodNames($mapping, $classes, $methods);
    }

    /**
     * Lines without line breaks, empty ones left out.
     *
     * @return list<string>
     */
    protected static function lines(string $text): array
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];

        return array_values(array_filter($lines, fn (string $line) => $line !== ''));
    }
}
