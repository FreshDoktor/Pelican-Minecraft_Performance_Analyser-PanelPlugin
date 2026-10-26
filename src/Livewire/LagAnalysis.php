<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Livewire;

use App\Facades\Activity;
use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Concerns\BelongsToServer;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\MethodNameService;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\MinecraftVersionService;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ModPackageIndex;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\PackageIndexService;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ProfileAnalyzer;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ProfileStore;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\Capabilities;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\Format;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\MethodNames;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\OwnerLabel;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The tab Lag analysis: start a profile of the Server thread (10, 30 or 60 s, mode wall), follow it,
 * and show where the tick time went: the share per mod (self and inclusive), the top methods, a
 * flamegraph that can be filtered by mod, the GC pauses during the profile and the raw profile to
 * download.
 *
 * Finished profiles are stored on the panel (ProfileStore, the newest 5), the bridge forgets them
 * after 15 minutes. Frames are mapped to mods with the package index of the server, which
 * BuildPackageIndexJob reads on the queue; until it is there, everything outside Minecraft, the loader
 * and the JVM shows as "other".
 *
 * Fabric and Forge run with ids instead of method names (method_18765, m_8793_, func_72835_b). The
 * names for the server's Minecraft version are downloaded the first time a profile needs them
 * (MethodNameService, on the queue) and replace the ids in the top methods and the flamegraph.
 *
 * The stacks for the flamegraph go to the browser once per opened profile as a browser event
 * (mpa-flame), not with every update.
 */
class LagAnalysis extends Component
{
    use BelongsToServer;

    public const NAME = 'minecraft-performance-analyser.lag-analysis';

    /** Durations offered, in seconds. */
    public const DURATIONS = [10, 30, 60];

    public const MODE = 'wall';

    public const FLAME_EVENT = 'mpa-flame';

    public const STATE_LOADING = 'loading';

    public const STATE_READY = 'ready';

    public const STATE_BRIDGE_MISSING = 'bridge_missing';

    /** The bridge has no profiles yet (not_implemented, before its milestone 3). */
    public const STATE_NOT_SUPPORTED = 'not_supported';

    public int $seconds = 30;

    #[Locked]
    public string $state = self::STATE_LOADING;

    #[Locked]
    public ?string $error = null;

    /** @var array<string, mixed>|null profile object of the running profile, without result */
    #[Locked]
    public ?array $running = null;

    /** @var list<array<string, mixed>> stored profiles, newest first */
    #[Locked]
    public array $stored = [];

    #[Locked]
    public ?string $selected = null;

    /** @var array<string, mixed>|null analysis of the selected profile, without the stacks */
    #[Locked]
    public ?array $summary = null;

    #[Locked]
    public bool $indexBuilding = false;

    /** @var array{status: string, current: ?int, total: ?int}|null */
    #[Locked]
    public ?array $indexStatus = null;

    /**
     * Readable names of the selected profile, null when its names need none (mojmap) or are off.
     *
     * @var array{state: string, mapping: string, version: ?string}|null
     */
    #[Locked]
    public ?array $names = null;

    protected function requiredPermission(): string
    {
        return Permissions::READ;
    }

    /**
     * First load, called by wire:init: the stored profiles, a profile that is running already (started
     * by someone else or before a reload) and the package index.
     */
    public function load(): void
    {
        $server = $this->getServer();

        $this->stored = $this->store()->list($server);

        // No bridge, or one that can't profile: the stored profiles are all there is, no need to ask.
        $reason = $this->capabilities()->reason(Capabilities::PROFILE);

        if (in_array($reason, [Capabilities::BRIDGE_MISSING, Capabilities::NOT_IMPLEMENTED], true)) {
            $this->state = $reason === Capabilities::BRIDGE_MISSING ? self::STATE_BRIDGE_MISSING : self::STATE_NOT_SUPPORTED;
        } else {
            $this->loadFromBridge();
        }

        $this->indexBuilding = $this->indexService()->dispatchIfNeeded($server);
        $this->indexStatus = $this->indexService()->status($server);

        if ($this->selected === null && $this->stored !== []) {
            $this->select($this->stored[0]['key']);
        }
    }

    /**
     * A profile that is running already, and finished ones the panel doesn't have yet.
     */
    protected function loadFromBridge(): void
    {
        $server = $this->getServer();

        try {
            foreach ($this->bridge()->profiles($server) as $profile) {
                if (in_array($profile['state'] ?? null, ['running', 'processing'], true)) {
                    $this->running = $profile;

                    break;
                }

                // Finished while nobody looked: keep it.
                if (($profile['state'] ?? null) === 'done' && !$this->isStored($profile)) {
                    $this->fetchAndStore((string) ($profile['id'] ?? ''));
                }
            }

            $this->state = self::STATE_READY;
        } catch (BridgeException $exception) {
            $this->handleBridgeError($exception);
        } catch (Throwable $exception) {
            report($exception);
            $this->state = self::STATE_READY;
            $this->error = $this->trans('errors.unexpected');
        }
    }

    public function start(): void
    {
        $server = $this->getServer();

        abort_unless($this->userCan(Permissions::PROFILE), 403);

        if (!in_array($this->seconds, self::DURATIONS, true)) {
            $this->seconds = 30;
        }

        $this->error = null;

        try {
            $this->running = $this->bridge()->startProfile($server, $this->seconds, self::MODE);
        } catch (BridgeException $exception) {
            // Someone else started one in the meantime: follow that one.
            if ($exception->errorCode === 'profile_running' && is_string($exception->details['profile_id'] ?? null)) {
                $this->follow($exception->details['profile_id']);

                return;
            }

            $this->handleBridgeError($exception);
            $this->forgetCapabilities();

            return;
        }

        Activity::event('server:performance.profile')
            ->subject($server)
            ->property(['seconds' => $this->seconds, 'mode' => self::MODE])
            ->log();
    }

    public function cancel(): void
    {
        abort_unless($this->userCan(Permissions::PROFILE), 403);

        $id = (string) ($this->running['id'] ?? '');
        $this->running = null;

        if ($id === '') {
            return;
        }

        try {
            $this->bridge()->deleteProfile($this->getServer(), $id);
        } catch (BridgeException $exception) {
            if ($exception->errorCode !== 'profile_not_found') {
                $this->error = $exception->getMessage();
            }
        }
    }

    /**
     * Called by wire:poll while a profile runs or the package index is read.
     */
    public function tick(): void
    {
        if ($this->running !== null) {
            $this->follow((string) ($this->running['id'] ?? ''));
        }

        if ($this->names !== null && $this->names['state'] === MethodNameService::STATE_BUILDING && $this->selected !== null && !$this->nameService()->isBuilding($this->names['mapping'], (string) $this->names['version'])) {
            // Now with the readable names, or why there are none.
            $this->select($this->selected);
        }

        if ($this->indexBuilding) {
            $this->indexStatus = $this->indexService()->status($this->getServer());

            if ($this->indexStatus === null) {
                $this->indexBuilding = false;

                // Now with the names of the mods.
                if ($this->selected !== null) {
                    $this->select($this->selected);
                }
            }
        }
    }

    /**
     * Open a stored profile.
     */
    public function select(string $key): void
    {
        $server = $this->getServer();
        $stored = ProfileStore::isValidKey($key) ? $this->store()->get($server, $key) : null;

        if ($stored === null) {
            $this->summary = null;
            $this->selected = null;
            $this->names = null;

            return;
        }

        $analysis = app(ProfileAnalyzer::class)->analyze($stored['profile'], $this->index());
        $analysis = $this->withReadableNames($analysis, $stored['minecraft'] ?? null);
        $flame = $analysis['flame'];
        unset($analysis['flame']);

        $this->selected = $key;
        $this->summary = [...$analysis, 'user' => $stored['user'] ?? null];

        $this->dispatch(self::FLAME_EVENT, key: $key, flame: $flame);
    }

    public function download(string $key): ?StreamedResponse
    {
        $server = $this->getServer();
        $raw = ProfileStore::isValidKey($key) ? $this->store()->raw($server, $key) : null;

        if ($raw === null) {
            return null;
        }

        return response()->streamDownload(function () use ($raw) {
            echo $raw;
        }, 'profile-'.$server->uuid_short.'-'.$key.'.json', ['Content-Type' => 'application/json']);
    }

    /**
     * Seconds until the next tick(), null when nothing is going on. While no profile can be started for a
     * passing reason (server stopped, JVM starting), slowly, so the button comes back by itself.
     */
    public function pollSeconds(): ?int
    {
        return match (true) {
            $this->running !== null => 1,
            $this->indexBuilding, ($this->names['state'] ?? null) === MethodNameService::STATE_BUILDING => 3,
            $this->state === self::STATE_READY && $this->capabilities()->disabled(Capabilities::PROFILE) => self::POLL_UNAVAILABLE_SECONDS,
            default => null,
        };
    }

    public function canProfile(): bool
    {
        return $this->userCan(Permissions::PROFILE);
    }

    /**
     * Name of an owner as shown: the jar's name, or the translated name of a fixed owner.
     *
     * @param  array{key: string, name: string, kind: string}  $owner
     */
    public function ownerName(array $owner): string
    {
        return OwnerLabel::name($owner);
    }

    public function formatMs(int|float|null $ms): string
    {
        return Format::duration($ms);
    }

    /**
     * Texts the flamegraph script shows.
     *
     * @return array<string, mixed>
     */
    public function flameConfig(): array
    {
        return [
            'strings' => trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.lag.flame'),
            'owners' => trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.lag.owners'),
        ];
    }

    protected function follow(string $id): void
    {
        $server = $this->getServer();

        try {
            $profile = $this->bridge()->profile($server, $id);
        } catch (BridgeException $exception) {
            $this->running = null;

            if ($exception->errorCode !== 'profile_not_found') {
                $this->handleBridgeError($exception);
            }

            return;
        }

        match ($profile['state'] ?? null) {
            'done' => $this->finishProfile($profile),
            'failed' => $this->failProfile($profile),
            default => $this->running = $profile,
        };
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    protected function finishProfile(array $profile): void
    {
        $server = $this->getServer();
        $this->running = null;

        try {
            $key = $this->store()->save($server, $profile, user(), $this->minecraftVersion());
        } catch (Throwable $exception) {
            report($exception);
            $this->error = $this->trans('errors.unexpected');

            return;
        }

        // The panel keeps it now, the bridge can forget it.
        try {
            $this->bridge()->deleteProfile($server, (string) $profile['id']);
        } catch (Throwable) {
            // It expires on its own after 15 minutes.
        }

        $this->stored = $this->store()->list($server);
        $this->select($key);

        // The new profile may show that the server has no Server thread.
        $this->forgetCapabilities();
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    protected function failProfile(array $profile): void
    {
        $this->running = null;
        $error = is_array($profile['error'] ?? null) ? $profile['error'] : [];

        $this->error = $this->bridge()->exceptionFromError($error)->getMessage();
    }

    protected function fetchAndStore(string $id): void
    {
        try {
            $profile = $this->bridge()->profile($this->getServer(), $id);

            if (($profile['state'] ?? null) === 'done') {
                $this->store()->save($this->getServer(), $profile, null, $this->minecraftVersion());
                $this->stored = $this->store()->list($this->getServer());
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    protected function isStored(array $profile): bool
    {
        try {
            return $this->store()->has($this->getServer(), ProfileStore::key($profile));
        } catch (Throwable) {
            return true;
        }
    }

    protected function handleBridgeError(BridgeException $exception): void
    {
        $this->error = $exception->getMessage();

        $this->state = match (true) {
            $exception->isBridgeMissing() => self::STATE_BRIDGE_MISSING,
            $exception->errorCode === 'not_implemented' => self::STATE_NOT_SUPPORTED,
            default => self::STATE_READY,
        };
    }

    /**
     * The readable names for the mapping the profile ran with, if they are there; else queues their
     * download and keeps the ids for now.
     *
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    protected function withReadableNames(array $analysis, ?string $version): array
    {
        $mapping = $analysis['server_thread']['mapping'] ?? null;
        $this->names = null;

        if (!MethodNames::supports($mapping) || !$this->nameService()->enabled()) {
            return $analysis;
        }

        // Profiles stored before the readable names don't know the version, the server's current one is the best guess.
        $version ??= $this->minecraftVersion();

        if ($version === null) {
            $this->names = ['state' => 'no_version', 'mapping' => $mapping, 'version' => null];

            return $analysis;
        }

        try {
            ['state' => $state, 'names' => $names] = $this->nameService()->request($mapping, $version);
        } catch (Throwable $exception) {
            report($exception);
            ['state' => $state, 'names' => $names] = ['state' => MethodNameService::STATE_FAILED, 'names' => null];
        }

        $this->names = ['state' => $state, 'mapping' => $mapping, 'version' => $version];

        return $names === null ? $analysis : $names->apply($analysis);
    }

    /**
     * The Minecraft version the server runs, from its start command if the bridge answers.
     */
    protected function minecraftVersion(): ?string
    {
        $server = $this->getServer();

        try {
            $vm = $this->bridge()->vm($server);
        } catch (Throwable) {
            $vm = null;
        }

        try {
            return app(MinecraftVersionService::class)->detect($server, $vm)['version'] ?? null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    protected function nameService(): MethodNameService
    {
        return app(MethodNameService::class);
    }

    protected function index(): ModPackageIndex
    {
        try {
            return $this->indexService()->index($this->getServer());
        } catch (Throwable $exception) {
            report($exception);

            return new ModPackageIndex;
        }
    }

    protected function store(): ProfileStore
    {
        return app(ProfileStore::class);
    }

    protected function indexService(): PackageIndexService
    {
        return app(PackageIndexService::class);
    }

    /**
     * @param  array<string, scalar>  $replace
     */
    protected function trans(string $key, array $replace = []): string
    {
        return trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.'.$key, $replace);
    }

    public function render(): View
    {
        return view('minecraft-performance-analyser::livewire.lag-analysis');
    }
}
