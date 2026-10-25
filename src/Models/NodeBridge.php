<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Models;

use App\Models\Node;
use Carbon\CarbonImmutable;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\TickMonitor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * The address of the MPA Bridge of a node, set in the admin area on the node's page (see NodeBridgeTab).
 * Nodes without one use <scheme>://<fqdn>:<default_port>, see BridgeClient::baseUrl().
 *
 * @property int $id
 * @property int $node_id
 * @property string $url
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Node $node
 */
class NodeBridge extends Model
{
    protected $table = 'minecraft_performance_analyser_bridges';

    protected $fillable = [
        'node_id',
        'url',
    ];

    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public static function urlFor(Node $node): ?string
    {
        return static::query()->where('node_id', $node->id)->value('url');
    }

    /**
     * Save the address of a node's bridge, an empty one goes back to the default address.
     */
    public static function saveFor(Node $node, ?string $url): void
    {
        $url = static::normalize($url);

        // Another address may be another bridge, the console asks it again for the tick monitor.
        Cache::forget(TickMonitor::nodeCacheKey($node));

        if ($url === null) {
            static::query()->where('node_id', $node->id)->delete();

            return;
        }

        static::query()->updateOrCreate(['node_id' => $node->id], ['url' => $url]);
    }

    /**
     * Trimmed and without a trailing slash, null if empty.
     */
    public static function normalize(?string $url): ?string
    {
        $url = rtrim(trim((string) $url), '/');

        return $url === '' ? null : $url;
    }
}
