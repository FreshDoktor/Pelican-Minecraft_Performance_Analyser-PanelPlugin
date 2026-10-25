@php
    $s = 'minecraft-performance-analyser::strings.overview.';
    $seconds = $this->pollSeconds();
@endphp

{{-- The poll sits on an element keyed by state and interval, so Livewire sets it up anew whenever either changes. --}}
<div wire:init="refresh">
    <style>
        .mpa { --mpa-border: rgba(127, 127, 127, 0.25); --mpa-muted: rgb(113, 113, 122); display: flex; flex-direction: column; gap: 1rem; }
        .mpa-row { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .mpa-muted { color: var(--mpa-muted); font-size: 0.8125rem; }
        .mpa-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        @media (max-width: 1024px) { .mpa-grid { grid-template-columns: minmax(0, 1fr); } }
        .mpa-card { border: 1px solid var(--mpa-border); border-radius: 0.75rem; padding: 1rem; display: flex; flex-direction: column; gap: 0.625rem; min-width: 0; }
        .mpa-card h3 { font-weight: 600; font-size: 0.9375rem; }
        .mpa-chart { width: 100%; height: 9rem; display: block; border-bottom: 1px solid var(--mpa-border); }
        .mpa-legend { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; font-size: 0.8125rem; font-variant-numeric: tabular-nums; }
        .mpa-dot { display: inline-block; width: 0.625rem; height: 0.625rem; border-radius: 9999px; margin-right: 0.375rem; vertical-align: middle; }
        .mpa-facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.75rem 1rem; }
        @media (max-width: 1024px) { .mpa-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .mpa-fact dt { font-size: 0.75rem; color: var(--mpa-muted); }
        .mpa-fact dd { font-weight: 600; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .mpa-fact small { display: block; font-weight: 400; font-size: 0.75rem; color: var(--mpa-muted); }
        .mpa-code { font-family: ui-monospace, monospace; font-size: 0.8125rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; background: rgba(127, 127, 127, 0.12); overflow-wrap: anywhere; }
        .mpa-steps { list-style: decimal; padding-left: 1.25rem; display: flex; flex-direction: column; gap: 0.375rem; font-size: 0.875rem; }
    </style>

    <div wire:key="mpa-poll-{{ $state }}-{{ $seconds ?? 0 }}" @if ($seconds !== null) wire:poll.{{ $seconds }}s.visible="refresh" @endif class="mpa">

        @if ($this->isFake())
            <x-filament::callout color="info" icon="tabler-flask" :heading="trans($s.'fake.title')" :description="trans($s.'fake.body')" />
        @endif

        @switch($state)
            @case(\FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Overview::STATE_LOADING)
                <div class="mpa-row mpa-muted">
                    <x-filament::loading-indicator class="h-5 w-5" />
                    <span>{{ trans($s.'loading') }}</span>
                </div>
                @break

            @case(\FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Overview::STATE_BRIDGE_MISSING)
                <div class="mpa-card">
                    <x-filament::callout color="warning" icon="tabler-plug-off" :heading="trans($s.'bridge_missing.title')" :description="$error" />

                    <p class="mpa-muted">{{ trans($s.'bridge_missing.intro') }}</p>

                    <ol class="mpa-steps">
                        <li>{!! trans($s.'bridge_missing.step_install', ['url' => e(\FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin::BRIDGE_URL)]) !!}</li>
                        <li>{{ trans($s.'bridge_missing.step_firewall') }}</li>
                        <li>{{ trans($s.'bridge_missing.step_address', ['url' => $this->bridgeAddress()]) }}</li>
                        <li>{{ trans($s.'bridge_missing.step_retry') }}</li>
                    </ol>

                    <div class="mpa-row">
                        <x-filament::button color="gray" icon="tabler-refresh" wire:click="refresh">
                            {{ trans($s.'retry') }}
                        </x-filament::button>

                        @if ($url = $this->nodeSettingsUrl())
                            <x-filament::button color="gray" icon="tabler-settings" tag="a" :href="$url">
                                {{ trans($s.'bridge_missing.node_settings') }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
                @break

            @case(\FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Overview::STATE_NOT_READY)
                <x-filament::callout color="gray" icon="tabler-player-pause" :heading="trans($s.'not_ready.'.$notReadyCode.'.title')" :description="trans($s.'not_ready.'.$notReadyCode.'.body')" />
                @break

            @case(\FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Overview::STATE_ERROR)
                <x-filament::callout color="danger" icon="tabler-alert-triangle" :heading="trans($s.'error')" :description="$error">
                    <x-slot name="footer">
                        <div class="mpa-row">
                            <x-filament::button color="gray" icon="tabler-refresh" wire:click="refresh">
                                {{ trans($s.'retry') }}
                            </x-filament::button>

                            {{-- Token or allowed_ips: set up on the node, the bridge address is there too. --}}
                            @if ($url = $this->nodeSettingsUrl())
                                <x-filament::button color="gray" icon="tabler-settings" tag="a" :href="$url">
                                    {{ trans($s.'bridge_missing.node_settings') }}
                                </x-filament::button>
                            @endif
                        </div>
                    </x-slot>
                </x-filament::callout>
                @break

            @default
                @php
                    $findings = $this->findings();
                    $heap = $this->heapChart(600, 140);
                    $gc = $this->gcChart(600, 140);
                @endphp

                {{-- Diagnosis --}}
                @forelse ($findings as $finding)
                    <x-filament::callout
                        :color="$finding['severity']"
                        :icon="$finding['severity'] === 'danger' ? 'tabler-alert-octagon' : 'tabler-alert-triangle'"
                        :heading="$finding['title']"
                        :description="$finding['body']"
                    />
                @empty
                    <x-filament::callout color="success" icon="tabler-circle-check" :heading="trans($s.'no_findings')" :description="trans($s.'no_findings_body')" />
                @endforelse

                @if ($vmError)
                    <x-filament::callout color="warning" icon="tabler-alert-triangle" :heading="trans($s.'vm_failed')" :description="$vmError" />
                @endif

                <div class="mpa-grid">
                    <section class="mpa-card">
                        <h3>{{ trans($s.'heap.title') }}</h3>
                        <span class="mpa-muted">{{ trans($s.'heap.after_gc') }}</span>

                        @if ($heap)
                            <svg class="mpa-chart" viewBox="0 0 600 140" preserveAspectRatio="none" role="img" aria-label="{{ trans($s.'heap.title') }}">
                                <line x1="0" y1="0.5" x2="600" y2="0.5" stroke="rgb(161, 161, 170)" stroke-dasharray="6 4" vector-effect="non-scaling-stroke" />
                                <polygon points="{{ $heap['old'] }}" fill="rgba(245, 158, 11, 0.55)" stroke="rgb(245, 158, 11)" vector-effect="non-scaling-stroke" />
                                <polygon points="{{ $heap['young'] }}" fill="rgba(59, 130, 246, 0.5)" stroke="rgb(59, 130, 246)" vector-effect="non-scaling-stroke" />
                            </svg>

                            <div class="mpa-legend">
                                <span><span class="mpa-dot" style="background: rgb(59, 130, 246)"></span>{{ trans($s.'heap.young') }}: {{ $heap['young_now'] }}</span>
                                <span><span class="mpa-dot" style="background: rgb(245, 158, 11)"></span>{{ trans($s.'heap.old') }}: {{ $heap['old_now'] }}</span>
                                <span><span class="mpa-dot" style="background: rgb(161, 161, 170)"></span>{{ trans($s.'heap.max') }}: {{ $heap['max'] }}</span>
                                <span class="mpa-muted">{{ trans($s.'span', ['span' => $heap['span']]) }}</span>
                            </div>
                        @endif
                    </section>

                    <section class="mpa-card">
                        <h3>{{ trans($s.'gc.title') }}</h3>
                        <span class="mpa-muted">{{ trans($s.'gc.subtitle') }}</span>

                        @if ($gc)
                            <svg class="mpa-chart" viewBox="0 0 600 140" preserveAspectRatio="none" role="img" aria-label="{{ trans($s.'gc.title') }}">
                                <polyline points="{{ $gc['line'] }}" fill="none" stroke="rgb(239, 68, 68)" stroke-width="2" vector-effect="non-scaling-stroke" />
                            </svg>

                            <div class="mpa-legend">
                                <span><span class="mpa-dot" style="background: rgb(239, 68, 68)"></span>{{ trans($s.'gc.now', ['value' => $gc['now']]) }}</span>
                                <span>{{ trans($s.'gc.share', ['share' => $gc['share']]) }}</span>
                                <span class="mpa-muted">{{ trans($s.'gc.top', ['value' => $gc['top']]) }}</span>
                            </div>
                        @else
                            <span class="mpa-muted">{{ trans($s.'gc.waiting') }}</span>
                        @endif
                    </section>
                </div>

                {{-- The JVM at a glance --}}
                <section class="mpa-card">
                    <h3>{{ trans($s.'jvm') }}</h3>

                    <dl class="mpa-facts">
                        @foreach ($this->facts() as $fact)
                            <div class="mpa-fact">
                                <dt>{{ $fact['label'] }}</dt>
                                <dd>
                                    {{ $fact['value'] }}
                                    @if (filled($fact['hint'] ?? null))
                                        <small>{{ $fact['hint'] }}</small>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($args = $this->vmArgs())
                        <div class="mpa-muted">{{ trans($s.'vm_args') }}</div>
                        <div class="mpa-code">{{ $args }}</div>
                    @endif
                </section>
        @endswitch
    </div>
</div>
