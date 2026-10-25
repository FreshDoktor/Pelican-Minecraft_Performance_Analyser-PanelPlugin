<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Reads what the ModPackageIndex needs from a jar of mods/ or plugins/: which Java packages its classes
 * are in, and the name and ids the jar gives itself (fabric.mod.json, quilt.mod.json, mods.toml,
 * neoforge.mods.toml, mcmod.info, plugin.yml, paper-plugin.yml, bungee.yml, velocity-plugin.json).
 *
 * A copy of the metadata part of ModJarParser of the Mods Addon (no dependency on other plugins), cut
 * down to name, version and ids and extended by plugin metadata and the package list. Everything but
 * parse() is plain PHP and tested without Laravel.
 *
 * @phpstan-type JarInfo array{kind: string, mod_ids: list<string>, name: string, version: ?string, loader: ?string, packages: array<string, int>}
 */
class ModJarReader
{
    /** Metadata files are tiny, anything bigger is not what we are looking for. */
    protected const MAX_METADATA_BYTES = 1024 * 1024;

    /** Folder on the local disk the jar is written to while it is read. */
    protected const TEMP_DIRECTORY = 'minecraft-performance-analyser/tmp';

    /**
     * Packages are cut to this many segments: deep enough to tell apart mods of one author
     * (me/jellysquid/mods/sodium, me/jellysquid/mods/lithium), short enough to keep the index small.
     */
    public const PACKAGE_DEPTH = 4;

    /** Most packages kept per jar, the ones with the most classes. */
    public const MAX_PACKAGES = 200;

    /**
     * @return JarInfo
     */
    public function parse(string $bytes, string $filename): array
    {
        if ($bytes === '' || !class_exists(ZipArchive::class)) {
            return $this->fromEntries(fn () => null, [], $filename);
        }

        // ZipArchive only reads from disk.
        $disk = Storage::disk('local');
        $tempFile = self::TEMP_DIRECTORY.'/'.Str::uuid().'.jar';

        try {
            if (!$disk->put($tempFile, $bytes)) {
                return $this->fromEntries(fn () => null, [], $filename);
            }

            $zip = new ZipArchive();

            if ($zip->open($disk->path($tempFile), ZipArchive::RDONLY) !== true) {
                return $this->fromEntries(fn () => null, [], $filename);
            }

            try {
                $names = [];

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);

                    if (is_string($name)) {
                        $names[] = $name;
                    }
                }

                return $this->fromEntries(fn (string $name) => $this->readEntry($zip, $name), $names, $filename);
            } finally {
                $zip->close();
            }
        } finally {
            $disk->delete($tempFile);
        }
    }

    protected function readEntry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);

        if ($stat === false || ($stat['size'] ?? 0) > self::MAX_METADATA_BYTES) {
            return null;
        }

        $contents = $zip->getFromName($name);

        return is_string($contents) ? $contents : null;
    }

    /**
     * @param  callable(string): ?string  $read  contents of an entry, null if missing
     * @param  list<string>  $names  all entries of the jar
     * @return JarInfo
     */
    public function fromEntries(callable $read, array $names, string $filename): array
    {
        $found = array_values(array_filter([
            $this->parseFabric($read('fabric.mod.json')),
            $this->parseQuilt($read('quilt.mod.json')),
            $this->parseModsToml($read('META-INF/neoforge.mods.toml'), 'neoforge'),
            $this->parseModsToml($read('META-INF/mods.toml'), 'forge'),
            $this->parseMcmodInfo($read('mcmod.info')),
            $this->parsePluginYml($read('paper-plugin.yml'), 'paper'),
            $this->parsePluginYml($read('plugin.yml'), 'bukkit'),
            $this->parsePluginYml($read('bungee.yml'), 'bungeecord'),
            $this->parseVelocity($read('velocity-plugin.json')),
        ]));

        $info = [
            'kind' => 'mod',
            'mod_ids' => [],
            'name' => $this->cleanFilename($filename),
            'version' => null,
            'loader' => null,
            'packages' => $this->packages($names),
        ];

        foreach ($found as $index => $meta) {
            if ($index === 0) {
                $info['kind'] = $meta['kind'];
                $info['name'] = $meta['name'] ?? $meta['mod_ids'][0] ?? $info['name'];
                $info['version'] = $meta['version'];
                $info['loader'] = $meta['loader'];
            }

            $info['mod_ids'] = array_values(array_unique([...$info['mod_ids'], ...$meta['mod_ids']]));

            // The main class of a plugin names its package for sure, even if it has few classes.
            if (isset($meta['main']) && preg_match('#^([\w$]+(?:\.[\w$]+)+)\.[\w$]+$#', $meta['main'], $matches)) {
                $package = $this->cut(str_replace('.', '/', $matches[1]));
                $info['packages'][$package] = max($info['packages'][$package] ?? 0, 1);
            }
        }

        return $info;
    }

    /**
     * Class count per package of a jar, packages cut to PACKAGE_DEPTH segments. Classes of other Java
     * versions (META-INF/versions) and classes without a package are left out.
     *
     * @param  list<string>  $names
     * @return array<string, int> by package, most classes first
     */
    public function packages(array $names): array
    {
        $packages = [];

        foreach ($names as $name) {
            if (!str_ends_with($name, '.class') || str_starts_with($name, 'META-INF/')) {
                continue;
            }

            $slash = strrpos($name, '/');

            if ($slash === false) {
                continue;
            }

            $package = $this->cut(substr($name, 0, $slash));
            $packages[$package] = ($packages[$package] ?? 0) + 1;
        }

        arsort($packages);

        return array_slice($packages, 0, self::MAX_PACKAGES, true);
    }

    protected function cut(string $package): string
    {
        return implode('/', array_slice(explode('/', trim($package, '/')), 0, self::PACKAGE_DEPTH));
    }

    /** @return array<string, mixed>|null */
    protected function parseFabric(?string $json): ?array
    {
        $data = $this->decodeJson($json);

        if (!is_array($data) || $this->string($data['id'] ?? null) === null) {
            return null;
        }

        return $this->meta('mod', [$data['id']], $data['name'] ?? null, $data['version'] ?? null, 'fabric');
    }

    /** @return array<string, mixed>|null */
    protected function parseQuilt(?string $json): ?array
    {
        $data = $this->decodeJson($json);
        $loader = is_array($data) ? ($data['quilt_loader'] ?? null) : null;

        if (!is_array($loader) || $this->string($loader['id'] ?? null) === null) {
            return null;
        }

        return $this->meta('mod', [$loader['id']], $loader['metadata']['name'] ?? null, $loader['version'] ?? null, 'quilt');
    }

    /** @return array<string, mixed>|null */
    protected function parseModsToml(?string $toml, string $loader): ?array
    {
        if ($toml === null || trim($toml) === '') {
            return null;
        }

        $ids = [];
        $first = null;

        foreach ($this->parseToml($toml) as $table) {
            if ($table['name'] === 'mods' && isset($table['values']['modId'])) {
                $ids[] = $table['values']['modId'];
                $first ??= $table['values'];
            } elseif (str_starts_with($table['name'], 'dependencies.') && strtolower($table['values']['modId'] ?? '') === 'neoforge') {
                // Early NeoForge releases still used mods.toml, their dependency gives them away.
                $loader = 'neoforge';
            }
        }

        if ($first === null) {
            return null;
        }

        return $this->meta('mod', $ids, $first['displayName'] ?? null, $first['version'] ?? null, $loader);
    }

    /** @return array<string, mixed>|null */
    protected function parseMcmodInfo(?string $json): ?array
    {
        $data = $this->decodeJson($json);

        // Either a plain list or, in version 2 of the format, wrapped in "modList".
        $mods = is_array($data['modList'] ?? null) ? $data['modList'] : $data;
        $mod = is_array($mods) ? ($mods[0] ?? null) : null;

        if (!is_array($mod) || $this->string($mod['modid'] ?? null) === null) {
            return null;
        }

        return $this->meta('mod', [$mod['modid']], $mod['name'] ?? null, $mod['version'] ?? null, 'forge');
    }

    /**
     * plugin.yml of Bukkit/Spigot/Paper, paper-plugin.yml and bungee.yml: name, version and main class.
     *
     * @return array<string, mixed>|null
     */
    protected function parsePluginYml(?string $yaml, string $loader): ?array
    {
        if ($yaml === null || trim($yaml) === '') {
            return null;
        }

        $values = $this->parseYamlTopLevel($yaml);
        $name = $this->string($values['name'] ?? null);

        if ($name === null) {
            return null;
        }

        return [...$this->meta('plugin', [strtolower($name)], $name, $values['version'] ?? null, $loader), 'main' => $this->string($values['main'] ?? null)];
    }

    /** @return array<string, mixed>|null */
    protected function parseVelocity(?string $json): ?array
    {
        $data = $this->decodeJson($json);

        if (!is_array($data) || $this->string($data['id'] ?? null) === null) {
            return null;
        }

        return [...$this->meta('plugin', [$data['id']], $data['name'] ?? null, $data['version'] ?? null, 'velocity'), 'main' => $this->string($data['main'] ?? null)];
    }

    /**
     * @param  array<mixed>  $ids
     * @return array{kind: string, mod_ids: list<string>, name: ?string, version: ?string, loader: string}
     */
    protected function meta(string $kind, array $ids, mixed $name, mixed $version, string $loader): array
    {
        return [
            'kind' => $kind,
            'mod_ids' => array_values(array_filter(array_map(fn ($id) => $this->string($id), $ids))),
            'name' => $this->string($name),
            'version' => $this->resolvedVersion($version),
            'loader' => $loader,
        ];
    }

    /**
     * Top-level "key: value" lines of a YAML file, enough for plugin.yml.
     *
     * @return array<string, string>
     */
    public function parseYamlTopLevel(string $yaml): array
    {
        $values = [];

        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*?)\s*$/', $line, $matches) && $matches[2] !== '') {
                $value = preg_replace('/\s+#.*$/', '', $matches[2]) ?? $matches[2];
                $values[$matches[1]] = trim($value, " \"'");
            }
        }

        return $values;
    }

    /**
     * Just enough TOML for mods.toml: tables, array tables and string, multi-line string and bare values.
     * The same as ModJarParser::parseToml() of the Mods Addon.
     *
     * @return array<int, array{name: string, values: array<string, string>}>
     */
    public function parseToml(string $toml): array
    {
        $tables = [];
        $current = ['name' => '', 'values' => []];

        $lines = preg_split('/\R/', $toml) ?: [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (preg_match('/^\[\[?\s*([^\]]+?)\s*\]\]?/', $line, $matches)) {
                $tables[] = $current;
                $current = ['name' => trim($matches[1], " \"'"), 'values' => []];

                continue;
            }

            if (!preg_match('/^["\']?([A-Za-z0-9_.-]+)["\']?\s*=\s*(.*)$/', $line, $matches)) {
                continue;
            }

            [$key, $raw] = [$matches[1], $matches[2]];

            foreach (['"""', "'''"] as $quote) {
                if (!str_starts_with($raw, $quote)) {
                    continue;
                }

                $value = substr($raw, 3);
                while (!str_contains($value, $quote) && $i + 1 < $count) {
                    $value .= "\n".$lines[++$i];
                }

                $end = strpos($value, $quote);
                $current['values'][$key] = trim($end === false ? $value : substr($value, 0, $end));

                continue 2;
            }

            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $raw, $matches)) {
                $current['values'][$key] = stripcslashes($matches[1]);
            } elseif (preg_match("/^'([^']*)'/", $raw, $matches)) {
                $current['values'][$key] = $matches[1];
            } else {
                $current['values'][$key] = trim(explode('#', $raw, 2)[0]);
            }
        }

        $tables[] = $current;

        return $tables;
    }

    /**
     * A readable name from a file name, e.g. "create-fabric-0.5.1.jar" → "create".
     */
    public function cleanFilename(string $filename): string
    {
        $name = preg_replace('/\.jar$/i', '', $filename) ?? $filename;
        $name = preg_replace('/[-_+](?:v|\d)[\d.\-_+a-zA-Z]*$/', '', $name) ?? $name;
        $name = preg_replace('/[-_+]mc[\d.]+$/i', '', $name) ?? $name;
        $name = preg_replace('/[-_+](fabric|forge|neoforge|quilt|spigot|paper|bukkit|velocity|sponge|liteloader)$/i', '', $name) ?? $name;

        $name = trim(str_replace(['-', '_', '+'], ' ', $name));

        return $name !== '' ? $name : $filename;
    }

    protected function decodeJson(?string $json): mixed
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        // Some mods put a byte order mark or raw line breaks into their strings.
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;

        $data = json_decode($json, true, 64, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($data === null) {
            $data = json_decode(str_replace(["\r", "\n", "\t"], ' ', $json), true, 64, JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return $data;
    }

    /** A version, unless it is a build placeholder that never got replaced. */
    protected function resolvedVersion(mixed $version): ?string
    {
        $version = $this->string($version);

        if ($version === null || str_contains($version, '${') || preg_match('/^@.+@$/', $version)) {
            return null;
        }

        return $version;
    }

    protected function string(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 200);
    }
}
