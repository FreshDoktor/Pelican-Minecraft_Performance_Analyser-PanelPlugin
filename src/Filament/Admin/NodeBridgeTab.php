<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Filament\Admin;

use App\Models\Node;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Models\NodeBridge;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\BridgeClient;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\BridgeAddress;

/**
 * Tab "MPA Bridge" on the edit page of a node in the admin area: the address the panel reaches the
 * bridge of this node under, the bridge's token, the pin of its certificate, and a button to test them.
 *
 * Added through EditNode::registerCustomTabs() (Pelican's CanCustomizeTabs), see NodeBridgeServiceProvider.
 * The fields are not part of the node: they aren't dehydrated into the node's attributes, they are saved
 * into NodeBridge together with the node instead (saveRelationshipsUsing runs inside the save of the page).
 * The stored token is never filled into the form, an empty token field keeps it. The pin is not secret, the
 * field shows the stored one, an empty pin field removes it.
 */
class NodeBridgeTab
{
    public const FIELD = 'mpa_bridge_url';

    public const TOKEN_FIELD = 'mpa_bridge_token';

    public const PIN_FIELD = 'mpa_bridge_tls_pin';

    /** Example for the address field only, there is no default address. */
    public const URL_EXAMPLE = 'https://10.0.0.5:8090';

    /** Id of the tab, also its value in the query string (?tab=mpa-bridge). */
    public const TAB_ID = 'mpa-bridge';

    public static function make(): Tab
    {
        return Tab::make(fn () => self::trans('node.tab'))
            ->id(self::TAB_ID)
            ->icon('tabler-activity-heartbeat')
            ->schema([
                Section::make()
                    ->description(fn () => self::trans('node.description'))
                    ->contained(false)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make(self::FIELD)
                            ->label(fn () => self::trans('node.url'))
                            ->helperText(fn () => self::trans('node.url_helper'))
                            ->placeholder(self::URL_EXAMPLE)
                            ->rules(['nullable', 'url:http,https', fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                                // The same rule BridgeClient applies before it sends anything.
                                if (is_string($value) && trim($value) !== '' && !BridgeAddress::isAllowed($value)) {
                                    $fail(self::trans('node.url_insecure'));
                                }
                            }])
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->formatStateUsing(fn (?Node $record) => $record ? NodeBridge::urlFor($record) : null)
                            ->dehydrated(false)
                            ->saveRelationshipsUsing(function (Node $record, ?string $state, Get $get) {
                                NodeBridge::saveFor($record, $state, $get(self::TOKEN_FIELD), $get(self::PIN_FIELD));
                            }),
                        TextInput::make(self::TOKEN_FIELD)
                            ->label(fn () => self::trans('node.token'))
                            ->helperText(fn () => self::trans('node.token_helper'))
                            ->placeholder(fn (?Node $record) => $record && NodeBridge::hasTokenFor($record) ? self::trans('node.token_stored') : null)
                            ->password()
                            // Keeps the browser from filling in the admin's own password.
                            ->autocomplete('new-password')
                            ->rules(['nullable', 'string', 'min:32'])
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->formatStateUsing(fn () => null)
                            ->dehydrated(false),
                        TextInput::make(self::PIN_FIELD)
                            ->label(fn () => self::trans('node.pin'))
                            ->helperText(fn () => self::trans('node.pin_helper'))
                            ->placeholder('sha256//…')
                            ->rules(['nullable', 'string', fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                                if (is_string($value) && trim($value) !== '' && !BridgeAddress::isValidPin(trim($value))) {
                                    $fail(self::trans('node.pin_invalid'));
                                }
                            }])
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->formatStateUsing(fn (?Node $record) => $record ? NodeBridge::pinFor($record) : null)
                            ->dehydrated(false),
                        Actions::make([
                            Action::make('mpa_test_bridge')
                                ->label(fn () => self::trans('node.test'))
                                ->icon('tabler-plug-connected')
                                ->color('gray')
                                ->action(fn (Get $get, Node $record) => self::test($record, $get(self::FIELD), $get(self::TOKEN_FIELD), $get(self::PIN_FIELD))),
                        ]),
                    ]),
            ]);
    }

    /**
     * Ask the bridge under the address in the field, saved or not, with the token and the pin in the fields,
     * else the stored ones (GET /api/v1/status). An empty address is not tested, there is no default one.
     * BridgeClient refuses an http address the form would refuse, and a pin of another form.
     */
    protected static function test(Node $node, ?string $url, ?string $token, ?string $pin): void
    {
        $client = app(BridgeClient::class);
        $url = NodeBridge::normalize($url) ?? ($client->isFake() ? BridgeClient::FAKE_URL : null);

        try {
            if ($url === null) {
                throw new BridgeException(BridgeException::NOT_CONFIGURED, self::trans('errors.not_configured'));
            }

            $status = $client->nodeStatus($node, $url, NodeBridge::normalizeToken($token), NodeBridge::normalizePin($pin));
        } catch (BridgeException $exception) {
            Notification::make()
                ->title(self::trans('node.test_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $replace = [
            'url' => $url,
            'version' => is_string($status['bridge_version'] ?? null) ? $status['bridge_version'] : '?',
            'features' => implode(', ', array_filter($status['features'] ?? [], 'is_string')) ?: '–',
        ];

        // Another major API version has other fields, the plugin would misread them.
        if (($status['api_version'] ?? null) !== BridgeClient::API_VERSION) {
            Notification::make()
                ->title(self::trans('node.test_api_mismatch'))
                ->body(self::trans('node.test_api_mismatch_body', [...$replace, 'api' => (string) ($status['api_version'] ?? '?'), 'expected' => BridgeClient::API_VERSION]))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(self::trans('node.test_ok'))
            ->body(self::trans('node.test_ok_body', $replace))
            ->success()
            ->send();
    }

    /**
     * @param  array<string, scalar>  $replace
     */
    protected static function trans(string $key, array $replace = []): string
    {
        return trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.'.$key, $replace);
    }
}
