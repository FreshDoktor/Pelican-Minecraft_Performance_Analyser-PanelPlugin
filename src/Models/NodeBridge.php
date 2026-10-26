<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Models;

use App\Models\Node;
use Carbon\CarbonImmutable;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\TickMonitor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * The address and the token of the MPA Bridge of a node, set in the admin area on the node's page (see
 * NodeBridgeTab). A node has a bridge only with both: without them the panel never contacts the node, see
 * BridgeClient. The token is the bridge's own (token in mpa-bridge.yml, printed by install.sh), never the
 * token of Wings. It is stored encrypted like daemon_token and never shown again. The certificate pin
 * (mpa-bridge -fingerprint, printed by install.sh) is optional and not secret: with it the panel checks the
 * bridge's certificate by its key instead of the CA store, so the bridge's self-signed certificate passes.
 *
 * @property int $id
 * @property int $node_id
 * @property string $url
 * @property string|null $token
 * @property string|null $tls_pin
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
        'token',
        'tls_pin',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            'token' => 'encrypted',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public static function for(Node $node): ?static
    {
        return static::query()->where('node_id', $node->id)->first();
    }

    public static function urlFor(Node $node): ?string
    {
        return static::query()->where('node_id', $node->id)->value('url');
    }

    public static function pinFor(Node $node): ?string
    {
        return static::query()->where('node_id', $node->id)->value('tls_pin');
    }

    /**
     * Whether a token is stored for the node, for the placeholder of the form. The token itself isn't read.
     */
    public static function hasTokenFor(Node $node): bool
    {
        return static::query()->where('node_id', $node->id)->whereNotNull('token')->exists();
    }

    /**
     * Whether address and token are both set, i.e. the node has a bridge the panel may ask.
     */
    public function isConfigured(): bool
    {
        return static::normalize($this->url) !== null && static::normalizeToken($this->token) !== null;
    }

    /**
     * Save the address, the token and the certificate pin of a node's bridge. An empty token keeps the stored
     * one, an empty pin removes it (the form shows the stored pin), an empty address removes all of them: the
     * node has no bridge then.
     */
    public static function saveFor(Node $node, ?string $url, ?string $token = null, ?string $pin = null): void
    {
        $url = static::normalize($url);
        $token = static::normalizeToken($token);
        $pin = static::normalizePin($pin);

        // Another address may be another bridge, the console asks it again for the tick monitor.
        Cache::forget(TickMonitor::nodeCacheKey($node));

        if ($url === null) {
            static::query()->where('node_id', $node->id)->delete();

            return;
        }

        static::query()->updateOrCreate(['node_id' => $node->id], ['url' => $url, 'tls_pin' => $pin, ...($token === null ? [] : ['token' => $token])]);
    }

    /**
     * Trimmed and without a trailing slash, null if empty.
     */
    public static function normalize(?string $url): ?string
    {
        $url = rtrim(trim((string) $url), '/');

        return $url === '' ? null : $url;
    }

    /**
     * Trimmed, null if empty.
     */
    public static function normalizeToken(?string $token): ?string
    {
        $token = trim((string) $token);

        return $token === '' ? null : $token;
    }

    /**
     * Trimmed, null if empty. The form is checked by the form and by BridgeClient (BridgeAddress::isValidPin()).
     */
    public static function normalizePin(?string $pin): ?string
    {
        $pin = trim((string) $pin);

        return $pin === '' ? null : $pin;
    }
}
