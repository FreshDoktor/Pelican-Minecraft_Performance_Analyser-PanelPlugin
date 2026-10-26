<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Filament\Server\Widgets;

use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\TickMonitor;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\TickSeries;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * A chart under the console like Pelican's CPU and memory charts, with TPS or MSPT of the tick monitor of
 * the bridge (GET /ticks) and the current value in the heading.
 *
 * Registered with Console::registerCustomWidgets() (see MinecraftPerformanceAnalyserPlugin). The console
 * only renders it when the server can deliver values (TickMonitor::shows()), and renders its widgets again
 * when the server starts or stops. In between it polls every 5 s while the console is open; the history
 * comes from the bridge, so it isn't empty when the console opens and the monitor was running already.
 *
 * @phpstan-import-type Current from TickSeries
 */
abstract class TickChart extends ChartWidget
{
    protected ?string $pollingInterval = '5s';

    protected ?string $maxHeight = '200px';

    /** Line colors by level. Normal is Pelican's blue of the CPU and memory charts. */
    public const COLORS = [
        TickSeries::LEVEL_NORMAL => '96, 165, 250',
        TickSeries::LEVEL_WARNING => '251, 191, 36',
        TickSeries::LEVEL_DANGER => '248, 113, 113',
    ];

    /** Color of the value in the heading by level, Filament's theme colors. */
    public const TEXT_COLORS = [
        TickSeries::LEVEL_NORMAL => null,
        TickSeries::LEVEL_WARNING => 'var(--warning-500)',
        TickSeries::LEVEL_DANGER => 'var(--danger-500)',
    ];

    /** @var array{ticks?: array<string, mixed>, error?: string}|null answer of GET /ticks for this request */
    protected ?array $answer = null;

    /** TickSeries::TPS or TickSeries::MSPT. */
    abstract protected function field(): string;

    /** strings.ticks.<key> of the heading and the line. */
    abstract protected function labelKey(): string;

    /** Top of the y axis unless the values are higher. */
    abstract protected function suggestedMax(): int;

    /**
     * The current value for the heading, null for none.
     *
     * @param  Current  $current
     */
    abstract protected function value(array $current): ?string;

    /**
     * What the value means, below the heading.
     *
     * @param  Current  $current
     */
    abstract protected function hint(array $current): ?string;

    public static function canView(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server && app(TickMonitor::class)->shows($server);
    }

    /**
     * Filament checks canView() on every update of a widget. Whether the widget is there at all is decided
     * when the console renders it; an update only checks that the user may still see the values, so a
     * server that stops meanwhile shows that instead of an error.
     */
    public function bootCanAuthorizeAccess(): void
    {
        $server = Filament::getTenant();

        abort_unless($server instanceof Server && TickMonitor::mayAccess($server), 403);
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getHeading(): Htmlable
    {
        $current = $this->current();
        $value = $current === null ? null : $this->value($current);
        $html = e($this->trans($this->labelKey()));

        if ($value !== null) {
            $color = self::TEXT_COLORS[$current['level']] ?? null;
            $html .= ' <span'.($color === null ? '' : ' style="color: '.$color.'"').'>'.e($value).'</span>';
        }

        return new HtmlString($html);
    }

    public function getDescription(): ?string
    {
        $answer = $this->answer();

        if (isset($answer['error'])) {
            return $answer['error'];
        }

        $current = $this->current();

        if ($current === null) {
            return null;
        }

        if ($current['state'] !== TickSeries::STATE_VALUES) {
            return $this->stateText($current);
        }

        if ($current['stale']) {
            return $this->trans('stale');
        }

        return $this->hint($current);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $ticks = $this->answer()['ticks'] ?? [];
        $points = TickSeries::points($ticks, $this->field());
        $level = $this->current()['level'] ?? TickSeries::LEVEL_NORMAL;
        $rgb = self::COLORS[$level] ?? self::COLORS[TickSeries::LEVEL_NORMAL];

        $dataset = [
            'label' => $this->trans($this->labelKey()),
            'data' => array_column($points, 'v'),
            'backgroundColor' => "rgba($rgb, 0.3)",
            'tension' => 0.3,
            'fill' => true,
            'spanGaps' => false,
        ];

        // Gaps (a lag analysis, a window without values) as a dashed line at the last value, the area below
        // hatched (getOptions() draws the pattern in the color of mpaHatch).
        $gaps = [
            'label' => $this->trans('not_measured'),
            'data' => TickSeries::filled($points),
            'borderDash' => [6, 6],
            'borderWidth' => 1.5,
            'pointRadius' => 0,
            'fill' => 'origin',
            'mpaHatch' => $rgb,
            'tension' => 0,
            'spanGaps' => false,
        ];

        // Normal keeps the line color of the theme, like Pelican's charts.
        if ($level !== TickSeries::LEVEL_NORMAL) {
            $dataset['borderColor'] = $gaps['borderColor'] = "rgb($rgb)";
        }

        return [
            'datasets' => [$dataset, $gaps],
            'labels' => array_map(fn (array $point) => $this->time($point['t']), $points),
            'locale' => user()?->language ?? 'en',
        ];
    }

    /**
     * JavaScript, for the hatched area below the gaps: a line dataset with mpaHatch (an "r, g, b" color) and
     * no backgroundColor of its own gets a pattern of diagonal stripes in that color. Datasets with their
     * own backgroundColor keep it.
     */
    protected function getOptions(): RawJs
    {
        $max = $this->suggestedMax();

        return RawJs::make(<<<JS
            {
                scales: {
                    y: { min: 0, suggestedMax: {$max} },
                    x: { display: false },
                },
                plugins: {
                    legend: { display: false },
                },
                datasets: {
                    line: {
                        backgroundColor: (context) => {
                            const rgb = context.dataset && context.dataset.mpaHatch;
                            if (!rgb) {
                                return undefined;
                            }
                            window.mpaHatches = window.mpaHatches || {};
                            if (!window.mpaHatches[rgb]) {
                                const size = 8;
                                const canvas = document.createElement('canvas');
                                canvas.width = canvas.height = size;
                                const draw = canvas.getContext('2d');
                                draw.strokeStyle = 'rgba(' + rgb + ', 0.45)';
                                draw.lineWidth = 1.5;
                                draw.beginPath();
                                draw.moveTo(0, size);
                                draw.lineTo(size, 0);
                                draw.moveTo(-size / 2, size / 2);
                                draw.lineTo(size / 2, -size / 2);
                                draw.moveTo(size / 2, size * 1.5);
                                draw.lineTo(size * 1.5, size / 2);
                                draw.stroke();
                                window.mpaHatches[rgb] = draw.createPattern(canvas, 'repeat');
                            }
                            return window.mpaHatches[rgb];
                        },
                    },
                },
            }
        JS);
    }

    /**
     * @return array{ticks?: array<string, mixed>, error?: string}
     */
    protected function answer(): array
    {
        if ($this->answer !== null) {
            return $this->answer;
        }

        $server = Filament::getTenant();

        return $this->answer = $server instanceof Server
            ? app(TickMonitor::class)->ticks($server)
            : ['error' => $this->trans('no_server')];
    }

    /**
     * @return Current|null
     */
    protected function current(): ?array
    {
        $ticks = $this->answer()['ticks'] ?? null;

        return is_array($ticks) ? TickSeries::current($ticks) : null;
    }

    /**
     * @param  Current  $current
     */
    protected function stateText(array $current): string
    {
        $key = match (true) {
            $current['state'] === 'starting' && $current['reason'] === 'jvm_starting' => 'jvm_starting',
            $current['state'] === 'unsupported' && $current['reason'] === 'no_server_thread' => 'no_server_thread',
            default => $current['state'],
        };

        return $this->trans('states.'.$key);
    }

    /**
     * A number like ≈ 19.8.
     */
    protected function approx(float $value, string $unit = ''): string
    {
        return '≈ '.number_format($value, 1).$unit;
    }

    protected function time(int $ms): string
    {
        return Carbon::createFromTimestampMs($ms)->setTimezone(user()?->timezone ?? config('app.timezone'))->format('H:i:s');
    }

    /**
     * @param  array<string, scalar>  $replace
     */
    protected function trans(string $key, array $replace = []): string
    {
        return trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.ticks.'.$key, $replace);
    }
}
