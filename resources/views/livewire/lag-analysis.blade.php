@php
    use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\LagAnalysis;
    use FreshDoktor\MinecraftPerformanceAnalyser\Services\ProfileAnalyzer;
    use FreshDoktor\MinecraftPerformanceAnalyser\Support\Capabilities;
    use FreshDoktor\MinecraftPerformanceAnalyser\Support\Format;

    $s = 'minecraft-performance-analyser::strings.lag.';
    $seconds = $this->pollSeconds();
    $availability = $this->availability(Capabilities::PROFILE);
@endphp

<div wire:init="load">
    <style>
        .mpa-lag { --mpa-border: rgba(127, 127, 127, 0.25); --mpa-muted: rgb(113, 113, 122); display: flex; flex-direction: column; gap: 1rem; }
        .mpa-lag .mpa-row { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .mpa-lag .mpa-muted { color: var(--mpa-muted); font-size: 0.8125rem; }
        .mpa-lag .mpa-card { border: 1px solid var(--mpa-border); border-radius: 0.75rem; padding: 1rem; display: flex; flex-direction: column; gap: 0.625rem; min-width: 0; }
        .mpa-lag .mpa-card h3 { font-weight: 600; font-size: 0.9375rem; }
        .mpa-lag .mpa-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        @media (max-width: 1024px) { .mpa-lag .mpa-grid { grid-template-columns: minmax(0, 1fr); } }
        .mpa-lag .mpa-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.75rem 1rem; }
        @media (max-width: 1024px) { .mpa-lag .mpa-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .mpa-lag .mpa-stats dt { font-size: 0.75rem; color: var(--mpa-muted); }
        .mpa-lag .mpa-stats dd { font-weight: 600; font-variant-numeric: tabular-nums; }
        .mpa-lag table { width: 100%; font-size: 0.8125rem; border-collapse: collapse; }
        .mpa-lag th { text-align: left; font-weight: 600; color: var(--mpa-muted); padding: 0.25rem 0.5rem; border-bottom: 1px solid var(--mpa-border); }
        .mpa-lag td { padding: 0.3rem 0.5rem; border-bottom: 1px solid var(--mpa-border); font-variant-numeric: tabular-nums; vertical-align: top; }
        .mpa-lag td.mpa-num, .mpa-lag th.mpa-num { text-align: right; white-space: nowrap; }
        .mpa-lag td.mpa-frame { font-family: ui-monospace, monospace; font-size: 0.75rem; overflow-wrap: anywhere; }
        .mpa-lag tr.mpa-owner { cursor: pointer; }
        .mpa-lag tr.mpa-owner:hover, .mpa-lag tr.mpa-active { background: rgba(127, 127, 127, 0.12); }
        .mpa-lag .mpa-bar { height: 0.375rem; border-radius: 9999px; background: rgba(127, 127, 127, 0.2); overflow: hidden; margin-top: 0.25rem; }
        .mpa-lag .mpa-bar div { height: 100%; background: rgb(239, 68, 68); }
        .mpa-lag .mpa-progress { height: 0.5rem; border-radius: 9999px; background: rgba(127, 127, 127, 0.25); overflow: hidden; }
        .mpa-lag .mpa-progress div { height: 100%; background: rgb(59, 130, 246); transition: width 0.5s; }
        .mpa-lag .mpa-badge { font-size: 0.6875rem; padding: 0 0.375rem; border-radius: 0.375rem; background: rgba(127, 127, 127, 0.15); color: var(--mpa-muted); margin-left: 0.375rem; }
        .mpa-lag .mpa-flame { position: relative; }
        .mpa-lag .mpa-flame canvas { display: block; width: 100%; cursor: pointer; }
        .mpa-lag .mpa-tip { position: absolute; pointer-events: none; z-index: 10; max-width: 32rem; padding: 0.375rem 0.5rem; border-radius: 0.375rem; font-size: 0.75rem; background: rgba(24, 24, 27, 0.92); color: #fff; overflow-wrap: anywhere; }
        .mpa-lag .mpa-timeline { position: relative; height: 1.25rem; border-radius: 0.375rem; background: rgba(127, 127, 127, 0.15); }
        .mpa-lag .mpa-timeline span { position: absolute; top: 0; bottom: 0; min-width: 2px; background: rgb(239, 68, 68); }
    </style>

    <div wire:key="mpa-lag-poll-{{ $seconds ?? 0 }}" @if ($seconds !== null) wire:poll.{{ $seconds }}s="tick" @endif class="mpa-lag">
        @if ($state === LagAnalysis::STATE_LOADING)
            <div class="mpa-row mpa-muted">
                <x-filament::loading-indicator class="h-5 w-5" />
                <span>{{ trans($s.'loading') }}</span>
            </div>
        @elseif ($state === LagAnalysis::STATE_BRIDGE_MISSING)
            <x-filament::callout color="warning" icon="tabler-plug-off" :heading="trans($s.'bridge_missing')" :description="$error" />
        @elseif ($state === LagAnalysis::STATE_NOT_SUPPORTED)
            <x-filament::callout color="gray" icon="tabler-clock-pause" :heading="trans($s.'not_supported')" :description="trans($s.'not_supported_body')" />
        @endif

        @if ($state === LagAnalysis::STATE_READY)
            {{-- Start or follow a profile --}}
            <section class="mpa-card">
                <h3>{{ trans($s.'start_title') }}</h3>
                <span class="mpa-muted">{{ trans($s.'start_body') }}</span>

                @if ($running)
                    @php
                        $progress = max(0, min(1, (float) ($running['progress'] ?? 0)));
                    @endphp
                    <div class="mpa-row">
                        <x-filament::loading-indicator class="h-4 w-4" />
                        <span>{{ trans($s.'running', ['seconds' => (int) ($running['seconds'] ?? 0), 'elapsed' => Format::duration($running['elapsed_ms'] ?? 0)]) }}</span>
                        @if (($running['state'] ?? null) === 'processing')
                            <span class="mpa-muted">{{ trans($s.'processing') }}</span>
                        @endif
                    </div>
                    <div class="mpa-progress"><div style="width: {{ round($progress * 100, 1) }}%"></div></div>

                    @if ($this->canProfile())
                        <div>
                            <x-filament::button color="gray" icon="tabler-player-stop" wire:click="cancel">{{ trans($s.'cancel') }}</x-filament::button>
                        </div>
                    @endif
                @elseif ($availability['reason'] !== null && !$availability['disabled'])
                    {{-- E.g. a proxy without Server thread: nothing to start here, only past results. --}}
                    <span class="mpa-muted">{{ $availability['text'] }}</span>
                @elseif ($this->canProfile())
                    <div class="mpa-row">
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="seconds">
                                @foreach (LagAnalysis::DURATIONS as $duration)
                                    <option value="{{ $duration }}">{{ trans($s.'duration', ['seconds' => $duration]) }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>

                        <x-filament::button icon="tabler-player-play" wire:click="start" wire:loading.attr="disabled" :disabled="$availability['disabled']">
                            {{ trans($s.'start') }}
                        </x-filament::button>
                    </div>

                    @if ($availability['disabled'])
                        <span class="mpa-muted">{{ $availability['text'] }}</span>
                    @endif
                @else
                    <span class="mpa-muted">{{ trans($s.'no_permission') }}</span>
                @endif

                @if ($indexBuilding)
                    <span class="mpa-muted">
                        {{ $indexStatus && $indexStatus['total'] ? trans($s.'index_progress', ['current' => (int) $indexStatus['current'], 'total' => (int) $indexStatus['total']]) : trans($s.'index_queued') }}
                    </span>
                @endif
            </section>

            @if ($error)
                <x-filament::callout color="danger" icon="tabler-alert-triangle" :heading="trans($s.'failed')" :description="$error" />
            @endif
        @endif

        {{-- Stored profiles --}}
        @if ($stored !== [])
            <section class="mpa-card">
                <h3>{{ trans($s.'stored', ['count' => count($stored)]) }}</h3>
                <table>
                    <thead>
                        <tr>
                            <th>{{ trans($s.'started') }}</th>
                            <th>{{ trans($s.'length') }}</th>
                            <th>{{ trans($s.'by') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stored as $profile)
                            <tr @class(['mpa-active' => $profile['key'] === $selected])>
                                <td>{{ \Illuminate\Support\Carbon::createFromTimestampMs($profile['started_at'])->setTimezone(user()?->timezone ?? config('app.timezone'))->isoFormat('LLL') }}</td>
                                <td>{{ trans($s.'duration', ['seconds' => $profile['seconds']]) }} ({{ $profile['mode'] }})</td>
                                <td>{{ $profile['user'] ?? '–' }}</td>
                                <td class="mpa-num">
                                    <x-filament::link tag="button" wire:click="select('{{ $profile['key'] }}')">{{ trans($s.'open') }}</x-filament::link>
                                    ·
                                    <x-filament::link tag="button" wire:click="download('{{ $profile['key'] }}')">{{ trans($s.'download') }}</x-filament::link>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @elseif ($state === LagAnalysis::STATE_READY && !$running)
            <span class="mpa-muted">{{ trans($s.'none_yet') }}</span>
        @endif

        {{-- The selected profile --}}
        @if ($summary)
            @php
                $thread = $summary['server_thread'];
                $gc = $summary['gc'];
                $duration = max(1.0, (float) $summary['duration_ms']);
            @endphp

            @if ($summary['state'] === ProfileAnalyzer::STATE_NO_SAMPLES || $summary['state'] === ProfileAnalyzer::STATE_IDLE)
                <x-filament::callout color="info" icon="tabler-player-pause" :heading="trans($s.'paused')" :description="trans($s.'paused_body')" />
            @elseif ($summary['state'] === ProfileAnalyzer::STATE_NO_SERVER_THREAD)
                <x-filament::callout color="warning" icon="tabler-alert-triangle" :heading="trans($s.'no_server_thread')" :description="trans($s.'no_server_thread_body')" />
            @endif

            @if (in_array('truncated', $summary['warnings'], true))
                <x-filament::callout color="warning" icon="tabler-cut" :heading="trans($s.'truncated')" />
            @endif

            <section class="mpa-card">
                <h3>{{ trans($s.'result', ['seconds' => $summary['seconds']]) }}</h3>
                <dl class="mpa-stats">
                    <div>
                        <dt>{{ trans($s.'tick_load') }}</dt>
                        <dd>{{ $thread && $thread['tick_load_percent'] !== null ? Format::percent($thread['tick_load_percent']) : '–' }}</dd>
                    </div>
                    <div>
                        <dt>{{ trans($s.'tick_work') }}</dt>
                        <dd>{{ $thread ? $this->formatMs($thread['work_ms']) : '–' }}</dd>
                    </div>
                    <div>
                        <dt>{{ trans($s.'gc_pauses') }}</dt>
                        <dd>{{ trans($s.'gc_pauses_value', ['count' => $gc['count'], 'time' => $this->formatMs($gc['time_ms'])]) }}</dd>
                    </div>
                    <div>
                        <dt>{{ trans($s.'cpu') }}</dt>
                        <dd>{{ $summary['cpu_percent'] !== null ? Format::percent($summary['cpu_percent']) : '–' }}</dd>
                    </div>
                </dl>
                <span class="mpa-muted">{{ trans($thread && $thread['mapping'] === null && $summary['mode'] === 'wall' ? $s.'tick_load_unknown' : $s.'tick_load_hint') }}</span>

                @if ($gc['pauses'] !== [])
                    <div class="mpa-timeline" title="{{ trans($s.'gc_timeline') }}">
                        @foreach ($gc['pauses'] as $pause)
                            <span style="left: {{ round(min(100, $pause['start_offset_ms'] / $duration * 100), 2) }}%; width: {{ round(min(100, $pause['duration_ms'] / $duration * 100), 2) }}%" title="{{ $pause['kind'] }}: {{ $this->formatMs($pause['duration_ms']) }}"></span>
                        @endforeach
                    </div>
                    @unless ($gc['pauses_complete'])
                        <span class="mpa-muted">{{ trans($s.'gc_incomplete') }}</span>
                    @endunless
                @endif
            </section>

            @if ($summary['owners'] !== [])
                <div class="mpa-grid">
                    <section class="mpa-card">
                        <h3>{{ trans($s.'per_mod') }}</h3>
                        <span class="mpa-muted">{{ trans($s.'per_mod_hint') }}</span>
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ trans($s.'owner') }}</th>
                                    <th class="mpa-num">{{ trans($s.'self') }}</th>
                                    <th class="mpa-num">{{ trans($s.'inclusive') }}</th>
                                </tr>
                            </thead>
                            <tbody x-data>
                                @foreach ($summary['owners'] as $owner)
                                    <tr class="mpa-owner" x-on:click="$dispatch('mpa-flame-filter', { owner: @js($owner['key']) })" title="{{ trans($s.'filter_hint') }}">
                                        <td>
                                            {{ $this->ownerName($owner) }}
                                            @if (in_array($owner['kind'], ['mod', 'plugin'], true))
                                                <span class="mpa-badge">{{ trans($s.'kinds.'.$owner['kind']) }}</span>
                                            @endif
                                            <div class="mpa-bar"><div style="width: {{ min(100, $owner['self_percent']) }}%"></div></div>
                                        </td>
                                        <td class="mpa-num">{{ Format::percent($owner['self_percent']) }}<br><span class="mpa-muted">{{ $this->formatMs($owner['self_ms']) }}</span></td>
                                        <td class="mpa-num">{{ Format::percent($owner['inclusive_percent']) }}<br><span class="mpa-muted">{{ $this->formatMs($owner['inclusive_ms']) }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>

                    <section class="mpa-card">
                        <h3>{{ trans($s.'top_methods') }}</h3>
                        <span class="mpa-muted">{{ trans($s.'top_methods_hint') }}</span>
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ trans($s.'method') }}</th>
                                    <th class="mpa-num">{{ trans($s.'self') }}</th>
                                    <th class="mpa-num">{{ trans($s.'inclusive') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($summary['top_methods'] as $method)
                                    <tr>
                                        <td class="mpa-frame">
                                            {{ $method['frame'] }}
                                            <div class="mpa-muted">{{ $this->ownerName(['key' => $method['owner'], 'name' => $method['owner_name'], 'kind' => $method['owner']]) }}</div>
                                        </td>
                                        <td class="mpa-num">{{ Format::percent($method['self_percent']) }}</td>
                                        <td class="mpa-num">{{ Format::percent($method['inclusive_percent']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                </div>
            @endif
        @endif

    </div>

    {{-- The flamegraph lives in the browser, Livewire must not touch it --}}
    <div class="mpa-lag" style="margin-top: 1rem">
    <section class="mpa-card" wire:ignore x-data="mpaFlamegraph(@js($this->flameConfig()))" x-on:mpa-flame.window="load($event.detail)" x-on:mpa-flame-filter.window="setFilter($event.detail.owner)" x-show="ready" x-cloak>
        <div class="mpa-row">
            <h3 x-text="S.title"></h3>
            <label class="mpa-row mpa-muted"><input type="checkbox" x-model="showIdle" x-on:change="rebuild()"> <span x-text="S.show_idle"></span></label>
            <template x-if="filter !== null">
                <x-filament::link tag="button" x-on:click="setFilter(null)"><span x-text="S.clear_filter.replace(':owner', ownerLabel(filter))"></span></x-filament::link>
            </template>
            <template x-if="zoomed">
                <x-filament::link tag="button" x-on:click="resetZoom()"><span x-text="S.reset_zoom"></span></x-filament::link>
            </template>
        </div>
        <span class="mpa-muted" x-text="S.hint"></span>
        <div class="mpa-flame">
            <canvas x-ref="canvas"></canvas>
            <div class="mpa-tip" x-ref="tip" x-show="tip" x-cloak></div>
        </div>
        <span class="mpa-muted" x-show="empty" x-text="S.empty"></span>
    </section>
    </div>
</div>

{{--
    Flamegraph of the Server thread for the tab Lag analysis, drawn on a canvas.

    The component sends the stacks once per opened profile as the browser event mpa-flame:
    {flame: {owners: [{key, name, kind}], frames: [[name, ownerIndex]], stacks: [[[frameIndex…], count, idle]]}}.
    The tree is built here, so filtering by a mod (event mpa-flame-filter from the table) and showing or
    hiding the idle time need no request. Root at the top, callees below, width = samples.

    In the view instead of a .js file, so the plugin needs no file functions to ship it, and inside the
    component's own view, as @script needs the component.
--}}
@script
<script>
@verbatim
Alpine.data('mpaFlamegraph', (config) => ({
    S: config.strings,
    ownerNames: config.owners,
    ready: false,
    empty: false,
    data: null,
    filter: null,
    showIdle: false,
    root: null,
    zoom: null,
    zoomed: false,
    tip: false,
    rects: [],
    rowHeight: 18,

    init() {
        this.observer = new ResizeObserver(() => this.draw());
        this.observer.observe(this.$refs.canvas.parentElement);

        const canvas = this.$refs.canvas;
        canvas.addEventListener('mousemove', (event) => this.hover(event));
        canvas.addEventListener('mouseleave', () => { this.tip = false; });
        canvas.addEventListener('click', (event) => this.click(event));
    },

    destroy() {
        this.observer?.disconnect();
    },

    load(detail) {
        this.data = detail.flame;
        this.filter = null;
        this.ready = true;
        this.rebuild();
    },

    setFilter(ownerKey) {
        if (!this.data) {
            return;
        }

        const index = ownerKey === null ? -1 : this.data.owners.findIndex((owner) => owner.key === ownerKey);
        this.filter = index < 0 || this.filter === index ? null : index;
        this.rebuild();
        this.$el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    },

    ownerLabel(index) {
        const owner = this.data?.owners[index];

        if (!owner) {
            return '';
        }

        return ['mod', 'plugin'].includes(owner.kind) ? owner.name : (this.ownerNames[owner.kind] ?? owner.name);
    },

    // Merge the stacks into a tree: {name, owner, value, children: Map}.
    rebuild() {
        const root = { name: this.S.root, owner: -1, value: 0, children: new Map() };

        for (const [ids, count, idle] of this.data?.stacks ?? []) {
            if (idle && !this.showIdle) {
                continue;
            }

            if (this.filter !== null && !ids.some((id) => this.data.frames[id][1] === this.filter)) {
                continue;
            }

            root.value += count;
            let node = root;

            for (const id of ids) {
                let child = node.children.get(id);

                if (!child) {
                    child = { name: this.data.frames[id][0], owner: this.data.frames[id][1], value: 0, children: new Map() };
                    node.children.set(id, child);
                }

                child.value += count;
                node = child;
            }
        }

        this.root = root;
        this.zoom = root;
        this.zoomed = false;
        this.empty = root.value === 0;
        this.draw();
    },

    resetZoom() {
        this.zoom = this.root;
        this.zoomed = false;
        this.draw();
    },

    depth(node) {
        let max = 0;

        for (const child of node.children.values()) {
            max = Math.max(max, this.depth(child));
        }

        return max + 1;
    },

    color(node) {
        if (node.owner < 0) {
            return 'rgb(161, 161, 170)';
        }

        const owner = this.data.owners[node.owner];
        const highlighted = this.filter === null || node.owner === this.filter;
        const alpha = highlighted ? 1 : 0.35;

        switch (owner.kind) {
            case 'jvm': return `rgba(161, 161, 170, ${alpha})`;
            case 'minecraft': return `rgba(34, 197, 94, ${alpha})`;
            case 'loader': return `rgba(59, 130, 246, ${alpha})`;
            case 'libraries': return `rgba(20, 184, 166, ${alpha})`;
            case 'other': return `rgba(113, 113, 122, ${alpha})`;
        }

        // Mods and plugins: a warm color per mod, the same on every draw.
        let hash = 0;
        for (const char of owner.key) {
            hash = (hash * 31 + char.charCodeAt(0)) | 0;
        }

        return `hsla(${Math.abs(hash) % 60}, 85%, ${50 + (Math.abs(hash >> 8) % 15)}%, ${alpha})`;
    },

    draw() {
        const canvas = this.$refs.canvas;
        const width = canvas.parentElement.clientWidth;

        if (!this.zoom || width === 0) {
            return;
        }

        const rows = Math.min(this.depth(this.zoom), 200);
        const height = rows * this.rowHeight;
        const ratio = window.devicePixelRatio || 1;

        canvas.width = width * ratio;
        canvas.height = height * ratio;
        canvas.style.height = height + 'px';

        const ctx = canvas.getContext('2d');
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, width, height);
        ctx.font = '11px ui-monospace, monospace';
        ctx.textBaseline = 'middle';

        this.rects = [];
        const total = Math.max(1, this.zoom.value);

        const drawNode = (node, x, level) => {
            const w = node.value / total * width;

            if (w < 0.5 || level >= rows) {
                return;
            }

            const y = level * this.rowHeight;
            ctx.fillStyle = this.color(node);
            ctx.fillRect(x, y, Math.max(w - 1, 0.5), this.rowHeight - 1);
            this.rects.push({ x, y, w, node });

            if (w > 30) {
                ctx.fillStyle = 'rgb(24, 24, 27)';
                ctx.save();
                ctx.beginPath();
                ctx.rect(x, y, w - 2, this.rowHeight);
                ctx.clip();
                ctx.fillText(this.shortName(node.name), x + 3, y + this.rowHeight / 2);
                ctx.restore();
            }

            let childX = x;
            for (const child of [...node.children.values()].sort((a, b) => b.value - a.value)) {
                drawNode(child, childX, level + 1);
                childX += child.value / total * width;
            }
        };

        drawNode(this.zoom, 0, 0);
    },

    // net/minecraft/server/MinecraftServer.tickServer → MinecraftServer.tickServer
    shortName(name) {
        const slash = name.lastIndexOf('/');

        return slash >= 0 && !name.includes('(') ? name.slice(slash + 1) : name;
    },

    find(event) {
        const box = this.$refs.canvas.getBoundingClientRect();
        const x = event.clientX - box.left;
        const y = event.clientY - box.top;

        return this.rects.find((rect) => x >= rect.x && x < rect.x + rect.w && y >= rect.y && y < rect.y + this.rowHeight) ?? null;
    },

    hover(event) {
        const rect = this.find(event);
        const tip = this.$refs.tip;

        if (!rect) {
            this.tip = false;

            return;
        }

        const percent = (rect.node.value / Math.max(1, this.root.value) * 100).toFixed(2);
        tip.textContent = `${rect.node.name}\n${rect.node.owner >= 0 ? this.ownerLabel(rect.node.owner) + ' · ' : ''}${this.S.samples.replace(':count', rect.node.value).replace(':percent', percent)}`;
        tip.style.whiteSpace = 'pre-line';

        const box = this.$refs.canvas.getBoundingClientRect();
        tip.style.left = Math.min(event.clientX - box.left + 12, box.width - 260) + 'px';
        tip.style.top = (event.clientY - box.top + 14) + 'px';
        this.tip = true;
    },

    click(event) {
        const rect = this.find(event);

        if (!rect) {
            return;
        }

        this.zoom = rect.node === this.zoom ? this.root : rect.node;
        this.zoomed = this.zoom !== this.root;
        this.draw();
    },
}));
@endverbatim
</script>
@endscript
