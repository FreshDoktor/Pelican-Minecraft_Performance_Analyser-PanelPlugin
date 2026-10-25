@php
    use FreshDoktor\MinecraftPerformanceAnalyser\Support\Format;
    use FreshDoktor\MinecraftPerformanceAnalyser\Support\OwnerLabel;
    use Illuminate\Support\Carbon;

    $s = 'minecraft-performance-analyser::strings.memory.';
    $pending = in_array($status['status'] ?? null, ['queued', 'running'], true);
@endphp

<div>
    <style>
        .mpa-mem { --mpa-border: rgba(127, 127, 127, 0.25); --mpa-muted: rgb(113, 113, 122); display: flex; flex-direction: column; gap: 1rem; }
        .mpa-mem .mpa-row { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .mpa-mem .mpa-muted { color: var(--mpa-muted); font-size: 0.8125rem; }
        .mpa-mem .mpa-card { border: 1px solid var(--mpa-border); border-radius: 0.75rem; padding: 1rem; display: flex; flex-direction: column; gap: 0.625rem; min-width: 0; }
        .mpa-mem .mpa-card h3 { font-weight: 600; font-size: 0.9375rem; }
        .mpa-mem .mpa-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.75rem 1rem; }
        @media (max-width: 1024px) { .mpa-mem .mpa-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .mpa-mem .mpa-stats dt { font-size: 0.75rem; color: var(--mpa-muted); }
        .mpa-mem .mpa-stats dd { font-weight: 600; font-variant-numeric: tabular-nums; }
        .mpa-mem details { border-bottom: 1px solid var(--mpa-border); }
        .mpa-mem summary { display: grid; grid-template-columns: minmax(0, 1fr) 7rem 7rem 6rem; gap: 0.5rem; padding: 0.4rem 0.25rem; cursor: pointer; font-size: 0.8125rem; font-variant-numeric: tabular-nums; }
        .mpa-mem summary .mpa-num, .mpa-mem .mpa-class .mpa-num { text-align: right; }
        .mpa-mem .mpa-head { display: grid; grid-template-columns: minmax(0, 1fr) 7rem 7rem 6rem; gap: 0.5rem; padding: 0.25rem; font-size: 0.75rem; font-weight: 600; color: var(--mpa-muted); border-bottom: 1px solid var(--mpa-border); }
        .mpa-mem .mpa-head .mpa-num { text-align: right; }
        .mpa-mem .mpa-class { display: grid; grid-template-columns: minmax(0, 1fr) 7rem 7rem 6rem; gap: 0.5rem; padding: 0.2rem 0.25rem 0.2rem 1.25rem; font-size: 0.75rem; font-variant-numeric: tabular-nums; }
        .mpa-mem .mpa-class code { font-family: ui-monospace, monospace; overflow-wrap: anywhere; }
        .mpa-mem .mpa-bar { height: 0.375rem; border-radius: 9999px; background: rgba(127, 127, 127, 0.2); overflow: hidden; margin-top: 0.25rem; }
        .mpa-mem .mpa-bar div { height: 100%; background: rgb(245, 158, 11); }
        .mpa-mem .mpa-badge { font-size: 0.6875rem; padding: 0 0.375rem; border-radius: 0.375rem; background: rgba(127, 127, 127, 0.15); color: var(--mpa-muted); margin-left: 0.375rem; }
    </style>

    {{-- Poll while the histogram is made, and slowly while it can't be made (server stopped, JVM starting, …). --}}
    <div wire:key="mpa-mem-{{ $pending ? 'pending' : ($availability['disabled'] ? 'wait' : 'idle') }}" @if ($pending) wire:poll.2s="tick" @elseif ($availability['disabled']) wire:poll.{{ $this::POLL_UNAVAILABLE_SECONDS }}s.visible="tick" @endif class="mpa-mem">
        <section class="mpa-card">
            <h3>{{ trans($s.'title') }}</h3>

            <x-filament::callout color="warning" icon="tabler-player-pause" :heading="trans($s.'warning_title')" :description="trans($s.'warning_body')" />

            @if ($pending)
                <div class="mpa-row">
                    <x-filament::loading-indicator class="h-5 w-5" />
                    <span>{{ trans($s.($status['status'] === 'queued' ? 'queued' : 'running'), ['seconds' => max(0, time() - (int) $status['queued_at'])]) }}</span>
                </div>
            @elseif ($canCreate && ($availability['reason'] === null || $availability['disabled']))
                <div>
                    <x-filament::button icon="tabler-chart-bar" wire:click="create" wire:confirm="{{ trans($s.'confirm') }}" :disabled="$availability['disabled']">
                        {{ trans($s.'create') }}
                    </x-filament::button>
                </div>
            @endif

            @if (!$pending && $availability['text'])
                <span class="mpa-muted">{{ $availability['text'] }}</span>
            @endif

            @if (($status['status'] ?? null) === 'failed')
                <x-filament::callout color="danger" icon="tabler-alert-triangle" :heading="trans($s.'failed')" :description="$this->errorMessage($status['error'] ?? null)">
                    <x-slot name="footer">
                        <x-filament::link tag="button" wire:click="dismissError">{{ trans($s.'dismiss') }}</x-filament::link>
                    </x-slot>
                </x-filament::callout>
            @endif
        </section>

        @if ($analysis)
            <section class="mpa-card">
                <h3>{{ trans($s.'result') }}</h3>
                <span class="mpa-muted">
                    {{ trans($s.'taken', [
                        'time' => Carbon::createFromTimestampMs($stored['saved_at'])->setTimezone(user()?->timezone ?? config('app.timezone'))->isoFormat('LLL'),
                        'user' => $stored['user'] ?? '–',
                        'duration' => Format::duration($analysis['duration_ms']),
                    ]) }}
                </span>

                <dl class="mpa-stats">
                    <div>
                        <dt>{{ trans($s.'total_bytes') }}</dt>
                        <dd>{{ Format::bytes($analysis['total']['bytes']) }}</dd>
                    </div>
                    <div>
                        <dt>{{ trans($s.'total_instances') }}</dt>
                        <dd>{{ number_format($analysis['total']['instances']) }}</dd>
                    </div>
                    <div>
                        <dt>{{ trans($s.'total_classes') }}</dt>
                        <dd>{{ number_format($analysis['total']['classes']) }}</dd>
                    </div>
                    <div>
                        <dt>{{ trans($s.'omitted') }}</dt>
                        <dd>{{ Format::bytes($analysis['omitted']['bytes']) }} <span class="mpa-muted">({{ Format::percent($analysis['omitted']['percent']) }})</span></dd>
                    </div>
                </dl>
                <span class="mpa-muted">{{ trans($s.'all_hint', ['limit' => number_format($analysis['limit']), 'classes' => number_format($analysis['omitted']['classes'])]) }}</span>

                <div>
                    <div class="mpa-head">
                        <span>{{ trans($s.'owner') }}</span>
                        <span class="mpa-num">{{ trans($s.'bytes') }}</span>
                        <span class="mpa-num">{{ trans($s.'instances') }}</span>
                        <span class="mpa-num">{{ trans($s.'share') }}</span>
                    </div>

                    @foreach ($analysis['owners'] as $owner)
                        <details>
                            <summary>
                                <span>
                                    {{ OwnerLabel::name($owner) }}
                                    @if (in_array($owner['kind'], ['mod', 'plugin'], true))
                                        <span class="mpa-badge">{{ trans('minecraft-performance-analyser::strings.lag.kinds.'.$owner['kind']) }}</span>
                                    @endif
                                    <span class="mpa-muted">· {{ trans($s.'classes', ['count' => $owner['class_count']]) }}</span>
                                    <div class="mpa-bar"><div style="width: {{ min(100, $owner['percent']) }}%"></div></div>
                                </span>
                                <span class="mpa-num">{{ Format::bytes($owner['bytes']) }}</span>
                                <span class="mpa-num">{{ number_format($owner['instances']) }}</span>
                                <span class="mpa-num">{{ Format::percent($owner['percent']) }}</span>
                            </summary>

                            @foreach ($owner['classes'] as $class)
                                <div class="mpa-class">
                                    <code title="{{ $class['name'] }}{{ $class['module'] ? ' ('.$class['module'].')' : '' }}">{{ $class['readable'] }}</code>
                                    <span class="mpa-num">{{ Format::bytes($class['bytes']) }}</span>
                                    <span class="mpa-num">{{ number_format($class['instances']) }}</span>
                                    <span></span>
                                </div>
                            @endforeach

                            @if ($owner['class_count'] > count($owner['classes']))
                                <div class="mpa-class mpa-muted">{{ trans($s.'more_classes', ['count' => $owner['class_count'] - count($owner['classes'])]) }}</div>
                            @endif
                        </details>
                    @endforeach
                </div>
            </section>
        @elseif (!$pending)
            <span class="mpa-muted">{{ trans($s.'none_yet') }}</span>
        @endif
    </div>
</div>
