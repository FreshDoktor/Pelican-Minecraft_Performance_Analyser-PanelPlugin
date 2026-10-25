<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

/**
 * Which mod, plugin or part of the server a frame of a profile belongs to.
 *
 * Built from the jars of mods/ and plugins/ (see ModJarReader, PackageIndexService): every package a jar
 * has classes in points to that jar, the longest matching package wins. Before that come the fixed
 * owners (Minecraft, loader/server, JVM), and mixin handlers, which mods inject into Minecraft's own
 * classes: their methods are named like handler$zza000$<modid>$<name> and belong to that mod, not to
 * Minecraft.
 *
 * Plain PHP, tested without Laravel.
 *
 * @phpstan-import-type JarInfo from ModJarReader
 *
 * @phpstan-type Owner array{key: string, name: string, kind: string}
 */
final class ModPackageIndex
{
    public const MINECRAFT = 'minecraft';

    public const LOADER = 'loader';

    public const JVM = 'jvm';

    public const LIBRARIES = 'libraries';

    public const OTHER = 'other';

    /** Kinds of owners: the fixed ones above, and mod or plugin for jars. */
    public const KIND_MOD = 'mod';

    public const KIND_PLUGIN = 'plugin';

    /**
     * Owners whatever the jars say. Longest prefix first is not needed, none of them overlap.
     *
     * Besides the ones of PLAN.md: Mixin and ModLauncher (load the mods), Spigot, Paper's old package
     * and Velocity count as the loader/server; javax and com/sun as the JVM.
     */
    public const FIXED = [
        'net/minecraft/' => self::MINECRAFT,
        'com/mojang/' => self::MINECRAFT,
        'net/fabricmc/' => self::LOADER,
        'org/quiltmc/' => self::LOADER,
        'net/neoforged/' => self::LOADER,
        'net/minecraftforge/' => self::LOADER,
        'cpw/mods/' => self::LOADER,
        'org/spongepowered/asm/' => self::LOADER,
        'io/papermc/' => self::LOADER,
        'com/destroystokyo/paper/' => self::LOADER,
        'org/bukkit/' => self::LOADER,
        'org/spigotmc/' => self::LOADER,
        'com/velocitypowered/' => self::LOADER,
        'java/' => self::JVM,
        'javax/' => self::JVM,
        'jdk/' => self::JVM,
        'sun/' => self::JVM,
        'com/sun/' => self::JVM,
    ];

    /**
     * Libraries the server ships, for frames no jar of mods/ or plugins/ claims.
     */
    public const LIBRARY_PREFIXES = [
        'io/netty/',
        'it/unimi/dsi/fastutil/',
        'com/google/',
        'org/apache/',
        'org/slf4j/',
        'org/joml/',
        'org/lwjgl/',
        'com/electronwill/',
        'org/jline/',
        'net/kyori/',
        'org/objectweb/asm/',
        'com/llamalad7/mixinextras/',
        'oshi/',
    ];

    /** Method name of a mixin handler: <type>$<hash>$<modid>$<name>, e.g. handler$zza000$sodium$onRender. */
    public const MIXIN_PATTERN = '/^(?:handler|redirect|modify[A-Za-z]*|localvar|wrapOperation|wrapWithCondition|wrapMethod|constant|args|inject)\$[0-9a-z]+\$([A-Za-z0-9_.-]+)\$/';

    /**
     * @param  array<string, Owner>  $owners  by key
     * @param  array<string, string>  $packages  owner key by package (slashes, without trailing slash)
     * @param  array<string, string>  $modIds  owner key by mod id
     */
    public function __construct(
        protected array $owners = [],
        protected array $packages = [],
        protected array $modIds = [],
    ) {
        foreach ([self::MINECRAFT, self::LOADER, self::JVM, self::LIBRARIES, self::OTHER] as $key) {
            $this->owners[$key] ??= ['key' => $key, 'name' => $key, 'kind' => $key];
        }
    }

    /**
     * @param  array<string, JarInfo>  $jars  by file name
     */
    public static function fromJars(array $jars): self
    {
        $owners = [];
        $claims = [];
        $modIds = [];

        ksort($jars, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($jars as $file => $jar) {
            $key = 'jar:'.$file;
            $owners[$key] = [
                'key' => $key,
                'name' => (string) ($jar['name'] ?? $file),
                'kind' => ($jar['kind'] ?? self::KIND_MOD) === self::KIND_PLUGIN ? self::KIND_PLUGIN : self::KIND_MOD,
            ];

            foreach ($jar['packages'] ?? [] as $package => $count) {
                // Two jars with classes in the same package (an unrelocated library): the one with more classes gets it.
                if (!isset($claims[$package]) || $count > $claims[$package][1]) {
                    $claims[$package] = [$key, (int) $count];
                }
            }

            foreach ($jar['mod_ids'] ?? [] as $modId) {
                $modIds[strtolower($modId)] ??= $key;
            }
        }

        return new self($owners, array_map(fn (array $claim) => $claim[0], $claims), $modIds);
    }

    /**
     * @return Owner
     */
    public function owner(string $key): array
    {
        return $this->owners[$key] ?? ['key' => $key, 'name' => $key, 'kind' => self::OTHER];
    }

    /**
     * @return array<string, Owner>
     */
    public function owners(): array
    {
        return $this->owners;
    }

    /**
     * Owner key of a frame of a collapsed stack, e.g. net/minecraft/server/MinecraftServer.tickServer,
     * com/simibubi/create/content/Foo$$Lambda.0x0000000010f1aa00.run or Parker::park(bool, long).
     */
    public function ownerOf(string $frame): string
    {
        [$class, $method] = self::split($frame);

        // Native and JVM frames (Unsafe_Park, __pthread_cond_timedwait, Parker::park(bool, long)) have no package.
        if ($class === null) {
            return self::JVM;
        }

        if ($method !== null && preg_match(self::MIXIN_PATTERN, $method, $matches)) {
            return $this->modIds[strtolower($matches[1])] ?? $this->ownerForUnknownModId($matches[1]);
        }

        foreach (self::FIXED as $prefix => $key) {
            if (str_starts_with($class, $prefix)) {
                return $key;
            }
        }

        // Longest package first: from the class's own package up to its first segment.
        $package = $class;

        while (($slash = strrpos($package, '/')) !== false) {
            $package = substr($package, 0, $slash);

            if (isset($this->packages[$package])) {
                return $this->packages[$package];
            }
        }

        foreach (self::LIBRARY_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return self::LIBRARIES;
            }
        }

        return self::OTHER;
    }

    /**
     * Class (slashes) and method of a Java frame, [null, null] for native frames.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function split(string $frame): array
    {
        // Java frames have a package with slashes; C++ frames like Parker::park(bool, long) and kernel frames none.
        if (!str_contains($frame, '/') || str_contains($frame, '(') || str_contains($frame, '::')) {
            return [null, null];
        }

        $dot = strrpos($frame, '.');

        if ($dot === false) {
            return [$frame, null];
        }

        return [substr($frame, 0, $dot), substr($frame, $dot + 1)];
    }

    /**
     * A mixin of a mod id no jar declares (e.g. a mod inside another jar): an owner of its own.
     */
    protected function ownerForUnknownModId(string $modId): string
    {
        $key = 'modid:'.strtolower($modId);

        $this->owners[$key] ??= ['key' => $key, 'name' => $modId, 'kind' => self::KIND_MOD];
        $this->modIds[strtolower($modId)] = $key;

        return $key;
    }

    /**
     * @return array{owners: array<string, Owner>, packages: array<string, string>, mod_ids: array<string, string>}
     */
    public function toArray(): array
    {
        return ['owners' => $this->owners, 'packages' => $this->packages, 'mod_ids' => $this->modIds];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['owners'] ?? [], $data['packages'] ?? [], $data['mod_ids'] ?? []);
    }

    public function jarCount(): int
    {
        return count(array_filter($this->owners, fn (array $owner) => str_starts_with($owner['key'], 'jar:')));
    }
}
