<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use App\Models\Server;
use App\Models\User;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The finished profiles of a server, the newest 5 (config keep_profiles), as JSON files on the panel's
 * local disk: storage/app/private/minecraft-performance-analyser/profiles/<server uuid>/. The bridge
 * forgets a result after 15 minutes, these are for looking at again, comparing and downloading.
 *
 * A file holds the profile object of the bridge as it came (the raw profile for the download) and who
 * started it. The share per mod is worked out anew when a profile is opened, so it uses the current
 * package index.
 *
 * @phpstan-type StoredProfile array{key: string, id: string, mode: string, seconds: int, started_at: int, user: ?string, state: string}
 */
class ProfileStore
{
    public const DIRECTORY = 'minecraft-performance-analyser/profiles';

    public const DEFAULT_KEEP = 5;

    public function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    public function directory(Server $server): string
    {
        return self::DIRECTORY.'/'.$server->uuid;
    }

    /**
     * Keys sort by start time: <started_at>-<id>.
     */
    public static function key(array $profile): string
    {
        return sprintf('%013d-%s', (int) ($profile['started_at'] ?? 0), BridgeClient::profileId((string) ($profile['id'] ?? '')));
    }

    public static function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^\d{13}-[0-9a-f]{16}$/', $key);
    }

    /**
     * Save a finished profile and drop the oldest beyond the limit.
     *
     * @param  array<string, mixed>  $profile  profile object of the bridge, state done
     */
    public function save(Server $server, array $profile, ?User $user): string
    {
        $key = self::key($profile);

        $this->disk()->put($this->path($server, $key), (string) json_encode([
            'saved_at' => now()->getTimestampMs(),
            'user' => $user?->username,
            'profile' => $profile,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->prune($server);

        return $key;
    }

    /**
     * Stored profiles, newest first.
     *
     * @return list<StoredProfile>
     */
    public function list(Server $server): array
    {
        $profiles = [];

        foreach (array_reverse($this->keys($server)) as $key) {
            $stored = $this->get($server, $key);

            if ($stored === null) {
                continue;
            }

            $profile = $stored['profile'];
            $profiles[] = [
                'key' => $key,
                'id' => (string) ($profile['id'] ?? ''),
                'mode' => (string) ($profile['mode'] ?? ''),
                'seconds' => (int) ($profile['seconds'] ?? 0),
                'started_at' => (int) ($profile['started_at'] ?? 0),
                'user' => is_string($stored['user'] ?? null) ? $stored['user'] : null,
                'state' => (string) ($profile['state'] ?? ''),
            ];
        }

        return $profiles;
    }

    /**
     * @return array{saved_at: int, user: ?string, profile: array<string, mixed>}|null
     */
    public function get(Server $server, string $key): ?array
    {
        if (!self::isValidKey($key)) {
            return null;
        }

        try {
            $json = $this->disk()->get($this->path($server, $key));
            $data = is_string($json) ? json_decode($json, true) : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_array($data) && is_array($data['profile'] ?? null) ? $data : null;
    }

    /**
     * Key of the newest stored profile, without reading any.
     */
    public function latestKey(Server $server): ?string
    {
        $keys = $this->keys($server);

        return $keys === [] ? null : $keys[count($keys) - 1];
    }

    public function has(Server $server, string $key): bool
    {
        return self::isValidKey($key) && $this->disk()->exists($this->path($server, $key));
    }

    /**
     * The raw profile object of the bridge, for the download.
     */
    public function raw(Server $server, string $key): ?string
    {
        $stored = $this->get($server, $key);

        return $stored === null ? null : (string) json_encode($stored['profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function deleteServer(Server $server): void
    {
        $this->disk()->deleteDirectory($this->directory($server));
    }

    public function keep(): int
    {
        return max(1, (int) config(MinecraftPerformanceAnalyserPlugin::ID.'.keep_profiles', self::DEFAULT_KEEP));
    }

    protected function prune(Server $server): void
    {
        $keys = $this->keys($server);

        foreach (array_slice($keys, 0, max(0, count($keys) - $this->keep())) as $key) {
            $this->disk()->delete($this->path($server, $key));
        }
    }

    /**
     * Keys of the stored profiles, oldest first.
     *
     * @return list<string>
     */
    protected function keys(Server $server): array
    {
        $keys = [];

        foreach ($this->disk()->files($this->directory($server)) as $file) {
            $key = basename($file, '.json');

            if (self::isValidKey($key) && str_ends_with($file, '.json')) {
                $keys[] = $key;
            }
        }

        sort($keys);

        return $keys;
    }

    protected function path(Server $server, string $key): string
    {
        return $this->directory($server).'/'.$key.'.json';
    }
}
