<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Support;

/**
 * The Minecraft version of a server from what it runs and what it installed, and the Java it needs.
 * Plain PHP, MinecraftVersionService gathers the inputs.
 */
class MinecraftVersion
{
    /** The first release of each Java requirement, newest first (minecraft.wiki, Fabric docs). */
    public const JAVA_REQUIREMENTS = [
        '26.1' => 25,
        '1.20.5' => 21,
        '1.18' => 17,
        '1.17' => 16,
    ];

    public const OLDEST_JAVA = 8;

    /** Releases only, e.g. 1.20.1 or 26.1; snapshots and "latest" are no help. */
    protected const VERSION = '(\d+\.\d+(?:\.\d+)?)';

    /** Server jars named after their Minecraft version, e.g. forge-1.12.2-14.23.5.2860.jar, minecraft_server.1.12.2.jar. */
    protected const JAR_PREFIXES = 'fabric-server-mc\.|minecraft_server\.|forge-|paper-|purpur-|folia-|pufferfish-|spigot-|craftbukkit-|mohist-|magma-';

    public static function requiredJava(string $version): int
    {
        foreach (self::JAVA_REQUIREMENTS as $since => $java) {
            if (version_compare($version, $since, '>=')) {
                return $java;
            }
        }

        return self::OLDEST_JAVA;
    }

    /**
     * From the start command, sun.rt.javaCommand and java.class.path in /vm: Forge and NeoForge from
     * 1.17 pass --fml.mcVersion, their class path names the libraries of the Minecraft version, and
     * many server jars carry it in their name.
     */
    public static function fromLaunch(?string $javaCommand, ?string $classPath): ?string
    {
        $v = self::VERSION;
        $patterns = [
            "/--fml\\.mcVersion[ =]$v(?![\\w.])/",
            "#(?:^|[\\s:/])libraries/net/minecraft/server/$v-#",
            "#(?:^|[\\s:/])libraries/net/fabricmc/intermediary/$v/#",
            "#(?:^|[\\s:/])libraries/net/minecraftforge/forge/$v-#",
            '#(?:^|[\\s:/=])(?:'.self::JAR_PREFIXES.")$v(?=[-.])[^\\s:/]*\\.jar(?![\\w.])#",
        ];

        foreach ($patterns as $pattern) {
            foreach ([$javaCommand, $classPath] as $text) {
                if (is_string($text) && preg_match($pattern, $text, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * From an egg variable such as MC_VERSION. Forge eggs may name the full version, e.g. 1.20.1-47.2.0.
     */
    public static function fromVariable(?string $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/^'.self::VERSION.'(?:-|$)/', $value, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * The newest version among folder names such as 1.21.1 (versions/, intermediary) or
     * 1.20.1-20230612.114412 (libraries/net/minecraft/server). After an update the old one may still
     * be there.
     *
     * @param  list<string>  $names
     */
    public static function newest(array $names): ?string
    {
        $versions = [];

        foreach ($names as $name) {
            $version = self::fromVariable($name);

            if ($version !== null) {
                $versions[] = $version;
            }
        }

        if ($versions === []) {
            return null;
        }

        usort($versions, 'version_compare');

        return $versions[count($versions) - 1];
    }
}
