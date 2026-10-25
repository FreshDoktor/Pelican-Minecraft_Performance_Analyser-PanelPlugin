<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\MinecraftVersion;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The Minecraft version of a server, for the diagnosis whether its Java suits it. In this order:
 *
 * 1. the start command in /vm, it is what runs (Forge and NeoForge from 1.17, server jars named after it),
 * 2. the egg variables, unless they say "latest",
 * 3. the folders the server installed, through the daemon (the way the Client Modpack plugin finds it).
 *
 * 2 and 3 are cached per JVM start, so the daemon is asked at most once per start and day.
 *
 * @phpstan-type Detected array{version: string, source: string, detail: ?string}
 */
class MinecraftVersionService
{
    public const SOURCE_LAUNCH = 'launch';

    public const SOURCE_VARIABLE = 'variable';

    public const SOURCE_FILES = 'files';

    /** Variables of the eggs, the first with a release wins. FORGE_VERSION may be e.g. 1.20.1-47.2.0. */
    protected const VARIABLES = ['MINECRAFT_VERSION', 'MC_VERSION', 'VANILLA_VERSION', 'FORGE_VERSION'];

    /** Folders with one subfolder per installed Minecraft version, the first that has one wins. */
    protected const FOLDERS = [
        // Fabric and Quilt
        'libraries/net/fabricmc/intermediary',
        // Forge and NeoForge from 1.17, e.g. 1.20.1-20230612.114412
        'libraries/net/minecraft/server',
        // Vanilla, Paper and its forks from 1.18 unpack the server jar here
        'versions',
        // Forge up to 1.16, e.g. 1.12.2-14.23.5.2860
        'libraries/net/minecraftforge/forge',
    ];

    protected const CACHE_HOURS = 24;

    /** When the daemon failed, try again sooner. */
    protected const RETRY_MINUTES = 10;

    /**
     * @param  array<string, mixed>|null  $vm  answer of GET /vm
     * @return Detected|null
     */
    public function detect(Server $server, ?array $vm): ?array
    {
        $launch = is_array($vm['launch'] ?? null) ? $vm['launch'] : [];
        $version = MinecraftVersion::fromLaunch(
            is_string($launch['java_command'] ?? null) ? $launch['java_command'] : null,
            is_string($launch['class_path'] ?? null) ? $launch['class_path'] : null,
        );

        if ($version !== null) {
            return ['version' => $version, 'source' => self::SOURCE_LAUNCH, 'detail' => null];
        }

        $key = MinecraftPerformanceAnalyserPlugin::ID.':minecraft-version:'.$server->id.':'.(int) ($launch['started_at'] ?? 0);
        $cached = Cache::get($key);

        if (is_array($cached) && array_key_exists('detected', $cached)) {
            return $cached['detected'];
        }

        $failed = false;
        $detected = $this->fromVariables($server) ?? $this->fromFolders($server, $failed);

        Cache::put($key, ['detected' => $detected], $failed && $detected === null ? now()->addMinutes(self::RETRY_MINUTES) : now()->addHours(self::CACHE_HOURS));

        return $detected;
    }

    /**
     * @return Detected|null
     */
    protected function fromVariables(Server $server): ?array
    {
        try {
            $values = [];

            foreach ($server->variables()->whereIn('env_variable', self::VARIABLES)->get() as $variable) {
                // Without a value of its own the server runs with the egg's default.
                $values[$variable->env_variable] = $variable->server_value ?? $variable->default_value;
            }
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        foreach (self::VARIABLES as $name) {
            $version = MinecraftVersion::fromVariable(is_string($values[$name] ?? null) ? $values[$name] : null);

            if ($version !== null) {
                return ['version' => $version, 'source' => self::SOURCE_VARIABLE, 'detail' => $name];
            }
        }

        return null;
    }

    /**
     * @return Detected|null
     */
    protected function fromFolders(Server $server, bool &$failed): ?array
    {
        $files = app(DaemonFileRepository::class)->setServer($server);

        foreach (self::FOLDERS as $folder) {
            try {
                $entries = $files->getDirectory($folder);
            } catch (RequestException $exception) {
                // Most servers have only one of the folders.
                if ($exception->response->status() !== 404) {
                    report($exception);
                    $failed = true;
                }

                continue;
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;

                continue;
            }

            $names = [];

            foreach ($entries as $entry) {
                if (is_array($entry) && !empty($entry['directory']) && is_string($entry['name'] ?? null)) {
                    $names[] = $entry['name'];
                }
            }

            $version = MinecraftVersion::newest($names);

            if ($version !== null) {
                return ['version' => $version, 'source' => self::SOURCE_FILES, 'detail' => $folder];
            }
        }

        return null;
    }
}
