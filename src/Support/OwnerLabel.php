<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Support;

use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ModPackageIndex;

/**
 * Name of an owner of the ModPackageIndex as shown: the jar's name, or the translated name of a fixed
 * owner (Minecraft, loader/server, JVM, libraries, other).
 */
final class OwnerLabel
{
    /**
     * @param  array{key: string, name: string, kind: string}  $owner
     */
    public static function name(array $owner): string
    {
        return match ($owner['kind']) {
            ModPackageIndex::MINECRAFT, ModPackageIndex::LOADER, ModPackageIndex::JVM, ModPackageIndex::LIBRARIES, ModPackageIndex::OTHER => (string) trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.lag.owners.'.$owner['kind']),
            default => $owner['name'],
        };
    }
}
