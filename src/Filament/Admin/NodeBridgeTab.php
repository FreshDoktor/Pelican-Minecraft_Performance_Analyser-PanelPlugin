<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Filament\Admin;

use App\Models\Node;
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

/**
 * Tab "MPA Bridge" on the edit page of a node in the admin area: the address the panel reaches the
 * bridge of this node under, and a button to test it.
 *
 * Added through EditNode::registerCustomTabs() (Pelican's CanCustomizeTabs), see NodeBridgeServiceProvider.
 * The field is not part of the node: it isn't dehydrated into the node's attributes, it is saved into
 * NodeBridge together with the node instead (saveRelationshipsUsing runs inside the save of the page).
 */
class NodeBridgeTab
{
    public const FIELD = 'mpa_bridge_url';

    public static function make(): Tab
    {
        return Tab::make(fn () => self::trans('node.tab'))
            ->id('mpa-bridge')
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
                            ->placeholder(fn (?Node $record) => $record ? app(BridgeClient::class)->defaultUrl($record) : 'http://10.0.0.5:'.BridgeClient::DEFAULT_PORT)
                            ->rules(['nullable', 'url:http,https'])
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->formatStateUsing(fn (?Node $record) => $record ? NodeBridge::urlFor($record) : null)
                            ->dehydrated(false)
                            ->saveRelationshipsUsing(function (Node $record, ?string $state) {
                                NodeBridge::saveFor($record, $state);
                            }),
                        Actions::make([
                            Action::make('mpa_test_bridge')
                                ->label(fn () => self::trans('node.test'))
                                ->icon('tabler-plug-connected')
                                ->color('gray')
                                ->action(fn (Get $get, Node $record) => self::test($record, $get(self::FIELD))),
                        ]),
                    ]),
            ]);
    }

    /**
     * Ask the bridge under the address in the field, saved or not.
     */
    protected static function test(Node $node, ?string $url): void
    {
        $client = app(BridgeClient::class);
        $url = NodeBridge::normalize($url) ?? $client->defaultUrl($node);

        try {
            $version = $client->probe($node, $url);
        } catch (BridgeException $exception) {
            Notification::make()
                ->title(self::trans('node.test_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(self::trans('node.test_ok'))
            ->body(self::trans('node.test_ok_body', ['url' => $url, 'version' => $version ?? '?']))
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
