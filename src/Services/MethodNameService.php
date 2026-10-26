<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\MappingException;
use FreshDoktor\MinecraftPerformanceAnalyser\Jobs\BuildMethodNamesJob;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\MappingFiles;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\MethodNames;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Readable method names for the lag analysis, one set per mapping and Minecraft version for all servers:
 * downloaded only when a profile needs them, by BuildMethodNamesJob on the queue, and kept on the
 * panel's local disk (storage/app/private/minecraft-performance-analyser/mappings/<mapping>-<version>.json).
 *
 * Sources (see MappingFiles for how they are joined):
 *
 * - Mojang's server mappings, through the version manifest (from 1.14.4), checked against its SHA-1,
 * - Fabric's intermediary from maven.fabricmc.net,
 * - MCPConfig and MCP's names from maven.minecraftforge.net.
 *
 * The files themselves are not kept, only the names for the ids.
 */
class MethodNameService
{
    public const DIRECTORY = 'minecraft-performance-analyser/mappings';

    protected const TEMP_DIRECTORY = 'minecraft-performance-analyser/tmp';

    public const MOJANG_MANIFEST = 'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json';

    public const FABRIC_MAVEN = 'https://maven.fabricmc.net';

    public const FORGE_MAVEN = 'https://maven.minecraftforge.net';

    /** The largest file accepted, Mojang's server mappings have about 8 MB. */
    protected const MAX_BYTES = 64 * 1024 * 1024;

    protected const TIMEOUT_SECONDS = 60;

    /** A status older than this is a job that died, a new one may start. */
    protected const STALE_MINUTES = 30;

    /** After a download failed. */
    protected const RETRY_MINUTES = 30;

    /** When there is nothing to download for the version, e.g. intermediary of a snapshot. */
    protected const UNAVAILABLE_DAYS = 7;

    public const STATE_READY = 'ready';

    public const STATE_BUILDING = 'building';

    public const STATE_UNAVAILABLE = 'unavailable';

    public const STATE_FAILED = 'failed';

    public function enabled(): bool
    {
        return (bool) config(MinecraftPerformanceAnalyserPlugin::ID.'.readable_names', true);
    }

    public function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    public static function isValidVersion(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+(?:\.\d+)?$/', $version);
    }

    /**
     * The names, if they are there already.
     */
    public function get(string $mapping, string $version): ?MethodNames
    {
        if (!MethodNames::supports($mapping) || !self::isValidVersion($version)) {
            return null;
        }

        try {
            $json = $this->disk()->exists($this->path($mapping, $version)) ? $this->disk()->get($this->path($mapping, $version)) : null;
            $data = is_string($json) ? json_decode($json, true) : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_array($data) ? MethodNames::fromArray($data) : null;
    }

    /**
     * Ready, building, or why not. Queues the download when the names are missing and it didn't fail
     * a short while ago.
     *
     * @return array{state: string, names: ?MethodNames}
     */
    public function request(string $mapping, string $version): array
    {
        $names = $this->get($mapping, $version);

        if ($names !== null) {
            return ['state' => self::STATE_READY, 'names' => $names];
        }

        if (!MethodNames::supports($mapping) || !self::isValidVersion($version)) {
            return ['state' => self::STATE_UNAVAILABLE, 'names' => null];
        }

        if ($this->isBuilding($mapping, $version)) {
            return ['state' => self::STATE_BUILDING, 'names' => null];
        }

        $failed = Cache::get($this->failedKey($mapping, $version));

        if (is_string($failed)) {
            return ['state' => $failed, 'names' => null];
        }

        // Atomic, so two open pages don't queue two downloads.
        if (!Cache::add($this->statusKey($mapping, $version), 'queued', now()->addMinutes(self::STALE_MINUTES))) {
            return ['state' => self::STATE_BUILDING, 'names' => null];
        }

        try {
            BuildMethodNamesJob::dispatch($mapping, $version);
        } catch (Throwable $exception) {
            report($exception);
            $this->finish($mapping, $version);

            return ['state' => self::STATE_FAILED, 'names' => null];
        }

        return ['state' => self::STATE_BUILDING, 'names' => null];
    }

    /**
     * Download, join and keep the names. Runs on the queue. A failure is remembered for a while, so
     * the page doesn't queue the same download on every visit.
     */
    public function build(string $mapping, string $version): void
    {
        try {
            $names = match ($mapping) {
                ProfileAnalyzer::MAPPING_INTERMEDIARY => MappingFiles::intermediary($this->intermediary($version), $this->mojang($version)),
                ProfileAnalyzer::MAPPING_SRG => MappingFiles::srg($this->mcpConfig($version), $this->mojang($version)),
                ProfileAnalyzer::MAPPING_MCP => MappingFiles::mcp($this->mcp($version)),
                default => throw MappingException::unavailable("no names for mapping $mapping"),
            };

            $this->disk()->put($this->path($mapping, $version), (string) json_encode([
                ...$names->toArray(),
                'minecraft' => $version,
                'built_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            Cache::forget($this->failedKey($mapping, $version));
        } catch (MappingException $exception) {
            Cache::put($this->failedKey($mapping, $version), $exception->unavailable ? self::STATE_UNAVAILABLE : self::STATE_FAILED, $exception->unavailable ? now()->addDays(self::UNAVAILABLE_DAYS) : now()->addMinutes(self::RETRY_MINUTES));

            if (!$exception->unavailable) {
                report($exception);
            }
        } catch (Throwable $exception) {
            Cache::put($this->failedKey($mapping, $version), self::STATE_FAILED, now()->addMinutes(self::RETRY_MINUTES));

            throw $exception;
        }
    }

    public function isBuilding(string $mapping, string $version): bool
    {
        return Cache::has($this->statusKey($mapping, $version));
    }

    public function updateStatus(string $mapping, string $version, string $status): void
    {
        Cache::put($this->statusKey($mapping, $version), $status, now()->addMinutes(self::STALE_MINUTES));
    }

    public function finish(string $mapping, string $version): void
    {
        Cache::forget($this->statusKey($mapping, $version));
    }

    /**
     * Mojang's server mappings, from 1.14.4.
     */
    protected function mojang(string $version): string
    {
        $manifest = $this->download(self::MOJANG_MANIFEST)->json();
        $entry = null;

        foreach (is_array($manifest['versions'] ?? null) ? $manifest['versions'] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $version && is_string($candidate['url'] ?? null)) {
                $entry = $candidate;

                break;
            }
        }

        if ($entry === null) {
            throw MappingException::unavailable("Mojang has no version $version");
        }

        $meta = $this->download($entry['url'], $entry['sha1'] ?? null)->json();
        $mappings = $meta['downloads']['server_mappings'] ?? null;

        if (!is_array($mappings) || !is_string($mappings['url'] ?? null)) {
            throw MappingException::unavailable("Mojang has no server mappings for $version");
        }

        return $this->download($mappings['url'], $mappings['sha1'] ?? null)->body();
    }

    protected function intermediary(string $version): string
    {
        $url = self::FABRIC_MAVEN."/net/fabricmc/intermediary/$version/intermediary-$version-v2.jar";

        return $this->fromZip($this->download($url)->body(), 'mappings/mappings.tiny');
    }

    protected function mcpConfig(string $version): string
    {
        $base = self::FORGE_MAVEN.'/de/oceanlabs/mcp/mcp_config';
        $build = MappingFiles::mcpConfigVersion(MappingFiles::mavenVersions($this->download("$base/maven-metadata.xml")->body()), $version);

        if ($build === null) {
            throw MappingException::unavailable("no MCPConfig for $version");
        }

        return $this->fromZip($this->download("$base/$build/mcp_config-$build.zip")->body(), 'config/joined.tsrg');
    }

    protected function mcp(string $version): string
    {
        $base = self::FORGE_MAVEN.'/de/oceanlabs/mcp';
        $chosen = MappingFiles::mcpVersion(
            MappingFiles::mavenVersions($this->download("$base/mcp_stable/maven-metadata.xml")->body()),
            MappingFiles::mavenVersions($this->download("$base/mcp_snapshot/maven-metadata.xml")->body()),
            $version,
        );

        if ($chosen === null) {
            throw MappingException::unavailable("no MCP names for $version");
        }

        ['channel' => $channel, 'version' => $mcp] = $chosen;

        return $this->fromZip($this->download("$base/mcp_$channel/$mcp/mcp_$channel-$mcp.zip")->body(), 'methods.csv');
    }

    /**
     * @throws MappingException 404 as unavailable, other errors as failed
     */
    protected function download(string $url, ?string $sha1 = null): Response
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(10)
                ->withUserAgent('Minecraft Performance Analyser (Pelican plugin)')
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new MappingException("$url: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->status() === 404) {
            throw MappingException::unavailable("$url: 404");
        }

        if (!$response->successful()) {
            throw new MappingException("$url: HTTP {$response->status()}");
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            throw new MappingException("$url: larger than ".self::MAX_BYTES.' bytes');
        }

        if (is_string($sha1) && !hash_equals(strtolower($sha1), sha1($body))) {
            throw new MappingException("$url: SHA-1 does not match");
        }

        return $response;
    }

    /**
     * One entry of a jar or zip.
     */
    protected function fromZip(string $bytes, string $entry): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new MappingException('PHP has no zip extension');
        }

        // ZipArchive only reads from disk.
        $tempFile = self::TEMP_DIRECTORY.'/'.Str::uuid().'.zip';

        try {
            $this->disk()->put($tempFile, $bytes);
            $zip = new ZipArchive();

            if ($zip->open($this->disk()->path($tempFile), ZipArchive::RDONLY) !== true) {
                throw new MappingException("not a zip file, looking for $entry");
            }

            try {
                $stat = $zip->statName($entry);
                $contents = $stat !== false && ($stat['size'] ?? 0) <= self::MAX_BYTES ? $zip->getFromName($entry) : false;
            } finally {
                $zip->close();
            }
        } finally {
            $this->disk()->delete($tempFile);
        }

        if (!is_string($contents)) {
            throw new MappingException("$entry missing in the zip file");
        }

        return $contents;
    }

    protected function path(string $mapping, string $version): string
    {
        return self::DIRECTORY."/$mapping-$version.json";
    }

    protected function statusKey(string $mapping, string $version): string
    {
        return MinecraftPerformanceAnalyserPlugin::ID.":method-names-status:$mapping-$version";
    }

    protected function failedKey(string $mapping, string $version): string
    {
        return MinecraftPerformanceAnalyserPlugin::ID.":method-names-failed:$mapping-$version";
    }
}
