<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use FreshDoktor\MinecraftPerformanceAnalyser\Jobs\BuildPackageIndexJob;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The package index of a server: what each jar in mods/ and plugins/ contains, read through the daemon
 * by BuildPackageIndexJob on the queue and kept in the cache, per jar by size and modification time,
 * so a jar is only read again when it changed.
 *
 * Built the same way as the scan of the Mods Addon (InstalledModsService), without depending on it.
 *
 * @phpstan-import-type JarInfo from ModJarReader
 *
 * @phpstan-type JarFile array{folder: string, name: string, size: int, modified: string}
 * @phpstan-type JarEntry array{size: int, modified: string, read_at: string, error: ?string, info: JarInfo}
 */
class PackageIndexService
{
    public const FOLDERS = ['mods', 'plugins'];

    protected const CACHE_DAYS = 30;

    /** A status older than this is a job that died, a new one may start. */
    protected const STALE_MINUTES = 30;

    protected const RETRY_UNREADABLE_MINUTES = 60;

    public const DEFAULT_MAX_JAR_MB = 64;

    public function __construct(protected ModJarReader $reader) {}

    /**
     * The index as far as it is read, the fixed owners only if nothing is read yet.
     */
    public function index(Server $server): ModPackageIndex
    {
        $jars = [];

        foreach ($this->entries($server) as $path => $entry) {
            $jars[$path] = $entry['info'];
        }

        return ModPackageIndex::fromJars($jars);
    }

    /**
     * Jars of mods/ and plugins/ as the daemon lists them, by path (e.g. mods/sodium.jar).
     *
     * @return array<string, JarFile>
     *
     * @throws Exception
     */
    public function listJars(Server $server): array
    {
        $jars = [];
        $files = app(DaemonFileRepository::class)->setServer($server);

        foreach (self::FOLDERS as $folder) {
            try {
                $entries = $files->getDirectory($folder);
            } catch (RequestException $exception) {
                // The folder doesn't exist, e.g. plugins/ on a modded server.
                if ($exception->response->status() === 404) {
                    continue;
                }

                throw $exception;
            }

            foreach ($entries as $entry) {
                // The daemon calls a file "file" and a folder "directory", symlinks are none of our business.
                if (!is_array($entry) || !is_string($entry['name'] ?? null) || empty($entry['file']) || !empty($entry['directory']) || !empty($entry['symlink'])) {
                    continue;
                }

                $name = $entry['name'];

                if (!str_ends_with(strtolower($name), '.jar') || str_contains($name, '/') || str_contains($name, '\\')) {
                    continue;
                }

                $jars["$folder/$name"] = [
                    'folder' => $folder,
                    'name' => $name,
                    'size' => (int) ($entry['size'] ?? 0),
                    'modified' => (string) ($entry['modified'] ?? ''),
                ];
            }
        }

        ksort($jars, SORT_NATURAL | SORT_FLAG_CASE);

        return $jars;
    }

    /**
     * Read every new or changed jar, forget the ones that are gone. Runs on the queue.
     *
     * @param  callable(int $current, int $total): void|null  $progress
     *
     * @throws Exception
     */
    public function build(Server $server, ?callable $progress = null): void
    {
        $jars = $this->listJars($server);
        $entries = array_intersect_key($this->entries($server), $jars);
        $toRead = array_values(array_filter($jars, fn (array $jar) => !$this->isCurrent($entries[$jar['folder'].'/'.$jar['name']] ?? null, $jar)));

        $maxBytes = max(1, (int) config(MinecraftPerformanceAnalyserPlugin::ID.'.max_jar_size_mb', self::DEFAULT_MAX_JAR_MB)) * 1024 * 1024;
        $files = app(DaemonFileRepository::class)->setServer($server);
        $total = count($toRead);

        foreach ($toRead as $i => $jar) {
            if ($progress) {
                $progress($i, $total);
            }

            $path = $jar['folder'].'/'.$jar['name'];
            $entry = [
                'size' => $jar['size'],
                'modified' => $jar['modified'],
                'read_at' => now()->toIso8601String(),
                'error' => null,
                'info' => $this->reader->fromEntries(fn () => null, [], $jar['name']),
            ];

            if ($jar['size'] > $maxBytes) {
                $entry['error'] = 'too_large';
            } else {
                try {
                    $bytes = $files->getContent($path, $maxBytes);
                    $entry['info'] = $this->reader->parse($bytes, $jar['name']);
                    unset($bytes);
                } catch (Throwable $exception) {
                    // Keep the name from the file name, the next build tries again.
                    $entry['error'] = 'unreadable';
                    report($exception);
                }
            }

            if ($jar['folder'] === 'plugins') {
                $entry['info']['kind'] = ModPackageIndex::KIND_PLUGIN;
            }

            $entries[$path] = $entry;

            // Let the page use what is read while the rest is still being read.
            if ($i % 10 === 9) {
                $this->putEntries($server, $entries);
            }
        }

        $this->putEntries($server, $entries);

        if ($progress) {
            $progress($total, $total);
        }
    }

    /**
     * Queue a build when a jar is new, changed or gone. Lists the folders through the daemon.
     *
     * @return bool whether a build is queued or running
     */
    public function dispatchIfNeeded(Server $server): bool
    {
        if ($this->isBuilding($server)) {
            return true;
        }

        try {
            $jars = $this->listJars($server);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        $entries = $this->entries($server);
        $changed = count(array_diff_key($entries, $jars)) > 0;

        foreach ($jars as $path => $jar) {
            $changed = $changed || !$this->isCurrent($entries[$path] ?? null, $jar);
        }

        if (!$changed) {
            return false;
        }

        $status = ['status' => 'queued', 'current' => null, 'total' => null, 'updated_at' => now()->timestamp];

        // Atomic, so opening the page twice doesn't queue two builds.
        if (!Cache::add($this->statusKey($server), $status, now()->addMinutes(self::STALE_MINUTES))) {
            return true;
        }

        try {
            BuildPackageIndexJob::dispatch($server->id);
        } catch (Throwable $exception) {
            report($exception);
            $this->finish($server->id);

            return false;
        }

        return true;
    }

    /**
     * @return array{status: string, current: ?int, total: ?int, updated_at: int}|null
     */
    public function status(Server|int $server): ?array
    {
        $status = cache()->get($this->statusKey($server));

        return is_array($status) ? $status : null;
    }

    public function isBuilding(Server|int $server): bool
    {
        return $this->status($server) !== null;
    }

    public function updateStatus(Server|int $server, string $status, ?int $current = null, ?int $total = null): void
    {
        cache()->put($this->statusKey($server), [
            'status' => $status,
            'current' => $current,
            'total' => $total,
            'updated_at' => now()->timestamp,
        ], now()->addMinutes(self::STALE_MINUTES));
    }

    public function finish(Server|int $server): void
    {
        cache()->forget($this->statusKey($server));
    }

    public function forget(Server|int $server): void
    {
        cache()->forget($this->entriesKey($server));
        $this->finish($server);
    }

    /**
     * @return array<string, JarEntry> by path
     */
    public function entries(Server|int $server): array
    {
        $entries = cache()->get($this->entriesKey($server));

        return is_array($entries) ? $entries : [];
    }

    /**
     * @param  array<string, JarEntry>  $entries
     */
    protected function putEntries(Server|int $server, array $entries): void
    {
        cache()->put($this->entriesKey($server), $entries, now()->addDays(self::CACHE_DAYS));
    }

    /**
     * @param  JarEntry|null  $entry
     * @param  JarFile  $jar
     */
    protected function isCurrent(?array $entry, array $jar): bool
    {
        if (!is_array($entry) || ($entry['size'] ?? null) !== $jar['size'] || ($entry['modified'] ?? null) !== $jar['modified']) {
            return false;
        }

        // A jar the daemon couldn't hand out is tried again after a while, not on every page load.
        if (($entry['error'] ?? null) === 'unreadable') {
            try {
                return now()->subMinutes(self::RETRY_UNREADABLE_MINUTES)->lt((string) ($entry['read_at'] ?? ''));
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    protected function entriesKey(Server|int $server): string
    {
        return MinecraftPerformanceAnalyserPlugin::ID.':package-index:'.($server instanceof Server ? $server->id : $server);
    }

    protected function statusKey(Server|int $server): string
    {
        return MinecraftPerformanceAnalyserPlugin::ID.':package-index-status:'.($server instanceof Server ? $server->id : $server);
    }
}
