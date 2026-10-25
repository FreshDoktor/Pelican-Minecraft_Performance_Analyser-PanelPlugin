<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The last class histogram and the last thread dump of a server, as the bridge answered them, on the
 * panel's local disk: storage/app/private/minecraft-performance-analyser/snapshots/<server uuid>/.
 * One of each, a new one replaces the old one. Kept so the tab shows them again after a reload without
 * stopping the server once more.
 */
class SnapshotStore
{
    public const DIRECTORY = 'minecraft-performance-analyser/snapshots';

    public const HISTOGRAM = 'histogram';

    public const THREADS = 'threads';

    public function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /**
     * @param  array<string, mixed>  $data  answer of the bridge
     */
    public function put(Server $server, string $type, array $data, ?User $user): void
    {
        $this->disk()->put($this->path($server, $type), (string) json_encode([
            'saved_at' => now()->getTimestampMs(),
            'user' => $user?->username,
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{saved_at: int, user: ?string, data: array<string, mixed>}|null
     */
    public function get(Server $server, string $type): ?array
    {
        try {
            $json = $this->disk()->get($this->path($server, $type));
            $stored = is_string($json) ? json_decode($json, true) : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_array($stored) && is_array($stored['data'] ?? null) ? $stored : null;
    }

    public function has(Server $server, string $type): bool
    {
        return $this->disk()->exists($this->path($server, $type));
    }

    public function deleteServer(Server $server): void
    {
        $this->disk()->deleteDirectory(self::DIRECTORY.'/'.$server->uuid);
    }

    protected function path(Server $server, string $type): string
    {
        return self::DIRECTORY.'/'.$server->uuid.'/'.($type === self::THREADS ? self::THREADS : self::HISTOGRAM).'.json';
    }
}
