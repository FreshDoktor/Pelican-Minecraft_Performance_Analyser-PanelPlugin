@php
    use FreshDoktor\MinecraftPerformanceAnalyser\Services\ThreadDumpAnalyzer;
    use FreshDoktor\MinecraftPerformanceAnalyser\Support\Format;
    use FreshDoktor\MinecraftPerformanceAnalyser\Support\OwnerLabel;
    use Illuminate\Support\Carbon;

    $s = 'minecraft-performance-analyser::strings.threads.';
    $stateColors = ['RUNNABLE' => 'success', 'BLOCKED' => 'danger', 'WAITING' => 'warning', 'TIMED_WAITING' => 'gray', 'NEW' => 'info', 'TERMINATED' => 'gray', ThreadDumpAnalyzer::VM => 'gray'];
@endphp

<div>
    <style>
        .mpa-thr { --mpa-border: rgba(127, 127, 127, 0.25); --mpa-muted: rgb(113, 113, 122); display: flex; flex-direction: column; gap: 1rem; }
        .mpa-thr .mpa-row { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .mpa-thr .mpa-muted { color: var(--mpa-muted); font-size: 0.8125rem; }
        .mpa-thr .mpa-card { border: 1px solid var(--mpa-border); border-radius: 0.75rem; padding: 1rem; display: flex; flex-direction: column; gap: 0.625rem; min-width: 0; }
        .mpa-thr .mpa-card h3 { font-weight: 600; font-size: 0.9375rem; }
        .mpa-thr .mpa-segment { display: inline-flex; flex-wrap: wrap; border: 1px solid var(--mpa-border); border-radius: 0.5rem; overflow: hidden; }
        .mpa-thr .mpa-segment button { padding: 0.3rem 0.625rem; font-size: 0.8125rem; }
        .mpa-thr .mpa-segment button + button { border-left: 1px solid var(--mpa-border); }
        .mpa-thr .mpa-segment button.mpa-active { background: rgba(127, 127, 127, 0.2); font-weight: 600; }
        .mpa-thr details { border-bottom: 1px solid var(--mpa-border); }
        .mpa-thr details.mpa-deadlock { background: rgba(239, 68, 68, 0.08); }
        .mpa-thr summary { display: grid; grid-template-columns: minmax(0, 1fr) 9rem 7rem 5rem; gap: 0.5rem; padding: 0.4rem 0.25rem; cursor: pointer; font-size: 0.8125rem; font-variant-numeric: tabular-nums; align-items: center; }
        .mpa-thr .mpa-head { display: grid; grid-template-columns: minmax(0, 1fr) 9rem 7rem 5rem; gap: 0.5rem; padding: 0.25rem; font-size: 0.75rem; font-weight: 600; color: var(--mpa-muted); border-bottom: 1px solid var(--mpa-border); }
        .mpa-thr .mpa-num { text-align: right; }
        .mpa-thr pre { font-family: ui-monospace, monospace; font-size: 0.75rem; white-space: pre-wrap; overflow-wrap: anywhere; padding: 0.5rem 0.75rem 0.75rem 1.25rem; }
        .mpa-thr textarea { width: 100%; height: 16rem; font-family: ui-monospace, monospace; font-size: 0.75rem; border: 1px solid var(--mpa-border); border-radius: 0.5rem; padding: 0.5rem; background: transparent; }
    </style>

    {{-- While the dump can't be taken (server stopped, JVM starting, …) look again now and then. --}}
    <div wire:key="mpa-thr-{{ $availability['disabled'] ? 'wait' : 'ok' }}" @if ($availability['disabled']) wire:poll.{{ $this::POLL_UNAVAILABLE_SECONDS }}s.visible="$refresh" @endif class="mpa-thr">
        <section class="mpa-card">
            <h3>{{ trans($s.'title') }}</h3>
            <span class="mpa-muted">{{ trans($s.'intro') }}</span>

            @if ($availability['reason'] === null || $availability['disabled'])
                <div>
                    <x-filament::button icon="tabler-list-tree" wire:click="create" wire:loading.attr="disabled" wire:target="create" :disabled="$availability['disabled']">
                        {{ trans($s.'create') }}
                    </x-filament::button>
                    <x-filament::loading-indicator class="h-5 w-5 inline-block" wire:loading wire:target="create" />
                </div>
            @endif

            @if ($availability['text'])
                <span class="mpa-muted">{{ $availability['text'] }}</span>
            @endif

            @if ($error)
                <x-filament::callout color="danger" icon="tabler-alert-triangle" :heading="trans($s.'failed')" :description="$error" />
            @endif
        </section>

        @if ($analysis)
            @if ($analysis['deadlocks'] !== [])
                <x-filament::callout color="danger" icon="tabler-lock-exclamation" :heading="trans($s.'deadlock', ['count' => count($analysis['deadlocks'])])">
                    <x-slot name="description">
                        {{-- Inside the callout's <p>, so spans and line breaks only. --}}
                        @foreach ($analysis['deadlocks'] as $deadlock)
                            @foreach ($deadlock['threads'] ?? [] as $thread)
                                <span>„{{ $thread['name'] ?? '?' }}“ {{ $thread['waiting_for'] ?? '' }}@if (!empty($thread['held_by'])) → „{{ $thread['held_by'] }}“@endif</span><br>
                            @endforeach
                        @endforeach
                    </x-slot>
                </x-filament::callout>
            @endif

            <section class="mpa-card" x-data="{ state: 'all' }">
                <h3>{{ trans($s.'result', ['count' => $analysis['total']]) }}</h3>
                <span class="mpa-muted">
                    {{ trans($s.'taken', [
                        'time' => Carbon::createFromTimestampMs($stored['saved_at'])->setTimezone(user()?->timezone ?? config('app.timezone'))->isoFormat('LLL'),
                        'user' => $stored['user'] ?? '–',
                        'duration' => Format::duration($analysis['duration_ms']),
                    ]) }}
                    @if ($analysis['vm']) · {{ $analysis['vm'] }} @endif
                </span>

                <div class="mpa-segment">
                    <button type="button" :class="{ 'mpa-active': state === 'all' }" x-on:click="state = 'all'">{{ trans($s.'all', ['count' => $analysis['total']]) }}</button>
                    @foreach ($analysis['states'] as $state => $count)
                        @if ($count > 0)
                            <button type="button" :class="{ 'mpa-active': state === @js($state) }" x-on:click="state = @js($state)">{{ trans($s.'states.'.$state) }} ({{ $count }})</button>
                        @endif
                    @endforeach
                </div>

                <div>
                    <div class="mpa-head">
                        <span>{{ trans($s.'name') }}</span>
                        <span>{{ trans($s.'state') }}</span>
                        <span class="mpa-num">{{ trans($s.'cpu') }}</span>
                        <span class="mpa-num">{{ trans($s.'cpu_share') }}</span>
                    </div>

                    @foreach ($analysis['threads'] as $thread)
                        <details x-show="state === 'all' || state === @js($thread['state'])" @class(['mpa-deadlock' => $thread['deadlocked']])>
                            <summary>
                                <span>
                                    {{ $thread['name'] }}
                                    @if ($thread['deadlocked'])
                                        <x-filament::badge color="danger" size="sm" class="inline-flex">{{ trans($s.'deadlocked') }}</x-filament::badge>
                                    @endif
                                    <span class="mpa-muted">
                                        @if ($thread['owner']) · {{ OwnerLabel::name($thread['owner']) }} @endif
                                        @if ($thread['daemon']) · {{ trans($s.'daemon') }} @endif
                                        @if ($thread['held_by']) · {{ trans($s.'waits_for', ['thread' => $thread['held_by']]) }} @endif
                                    </span>
                                </span>
                                <span>
                                    <x-filament::badge :color="$stateColors[$thread['state']] ?? 'gray'" size="sm" class="inline-flex">{{ trans($s.'states.'.$thread['state']) }}</x-filament::badge>
                                    @if ($thread['state_detail'])
                                        <span class="mpa-muted">{{ $thread['state_detail'] }}</span>
                                    @endif
                                </span>
                                <span class="mpa-num">{{ $thread['cpu_ms'] !== null ? Format::duration($thread['cpu_ms']) : '–' }}</span>
                                <span class="mpa-num">{{ $thread['cpu_percent'] !== null ? Format::percent($thread['cpu_percent']) : '–' }}</span>
                            </summary>
                            <pre>{{ $thread['stack'] === [] ? trans($s.'no_stack') : implode("\n", $thread['stack']) }}</pre>
                        </details>
                    @endforeach
                </div>
                <span class="mpa-muted">{{ trans($s.'cpu_hint') }}</span>
            </section>

            @if ($analysis['raw'] !== '')
                <section class="mpa-card" x-data="{ copied: false }">
                    <div class="mpa-row">
                        <h3>{{ trans($s.'raw') }}</h3>
                        <x-filament::link tag="button" icon="tabler-copy" x-on:click="navigator.clipboard.writeText($refs.raw.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                            <span x-show="!copied">{{ trans($s.'copy') }}</span>
                            <span x-show="copied" x-cloak>{{ trans($s.'copied') }}</span>
                        </x-filament::link>
                    </div>
                    <textarea x-ref="raw" readonly>{{ $analysis['raw'] }}</textarea>
                </section>
            @endif
        @else
            <span class="mpa-muted">{{ trans($s.'none_yet') }}</span>
        @endif
    </div>
</div>
