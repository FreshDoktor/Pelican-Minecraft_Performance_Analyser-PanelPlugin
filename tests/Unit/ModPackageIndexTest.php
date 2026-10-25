<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Services\ModJarReader;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ModPackageIndex;
use PHPUnit\Framework\TestCase;

/**
 * There are no fixtures of a server with mods yet (the test server is vanilla), so the jars here are
 * built by hand: entry lists and metadata like real Fabric, NeoForge and Paper jars have them.
 */
class ModPackageIndexTest extends TestCase
{
    /**
     * A jar as ModJarReader sees it.
     *
     * @param  array<string, string>  $files  metadata files by name
     * @param  list<string>  $classes
     * @return array<string, mixed>
     */
    public static function jar(string $filename, array $files, array $classes): array
    {
        return (new ModJarReader)->fromEntries(fn (string $name) => $files[$name] ?? null, [...array_keys($files), ...$classes], $filename);
    }

    /**
     * Sodium and Lithium share me/jellysquid/mods, Create has an unrelocated library, the plugin names
     * its main class.
     */
    public static function index(): ModPackageIndex
    {
        return ModPackageIndex::fromJars([
            'sodium-fabric-0.6.0+mc1.21.jar' => self::jar('sodium-fabric-0.6.0+mc1.21.jar', [
                'fabric.mod.json' => '{"schemaVersion": 1, "id": "sodium", "name": "Sodium", "version": "0.6.0"}',
            ], [
                'me/jellysquid/mods/sodium/client/SodiumClientMod.class',
                'me/jellysquid/mods/sodium/client/render/chunk/Foo.class',
                'me/jellysquid/mods/sodium/mixin/core/MinecraftMixin.class',
                'META-INF/versions/21/me/jellysquid/mods/sodium/Bar.class',
            ]),
            'lithium-fabric-0.13.0.jar' => self::jar('lithium-fabric-0.13.0.jar', [
                'fabric.mod.json' => '{"id": "lithium", "name": "Lithium", "version": "0.13.0"}',
            ], [
                'me/jellysquid/mods/lithium/common/ai/Pathing.class',
                'me/jellysquid/mods/lithium/common/world/Chunks.class',
            ]),
            'create-1.21.1-6.0.0.jar' => self::jar('create-1.21.1-6.0.0.jar', [
                'META-INF/neoforge.mods.toml' => "modLoader=\"javafml\"\n[[mods]]\nmodId=\"create\"\ndisplayName=\"Create\"\nversion=\"6.0.0\"\n[[mods]]\nmodId=\"ponder\"\n",
            ], [
                'com/simibubi/create/Create.class',
                'com/simibubi/create/content/kinetics/belt/BeltBlockEntity.class',
                'com/simibubi/create/content/kinetics/belt/BeltInventory.class',
                'com/simibubi/create/foundation/utility/Lang.class',
                'org/example/shadedlib/Util.class',
            ]),
            'MyPlugin-2.0.jar' => self::jar('MyPlugin-2.0.jar', [
                'plugin.yml' => "name: MyPlugin\nversion: '2.0' # comment\nmain: de.example.myplugin.MyPlugin\napi-version: '1.21'\n",
            ], [
                'de/example/myplugin/MyPlugin.class',
                'de/example/myplugin/listeners/JoinListener.class',
            ]),
        ]);
    }

    public function test_reader_takes_name_ids_and_packages(): void
    {
        $jar = self::jar('create-1.21.1-6.0.0.jar', [
            'META-INF/neoforge.mods.toml' => "[[mods]]\nmodId=\"create\"\ndisplayName=\"Create\"\nversion=\"6.0.0\"\n[[mods]]\nmodId=\"ponder\"\n",
        ], [
            'com/simibubi/create/Create.class',
            'com/simibubi/create/content/kinetics/belt/BeltBlockEntity.class',
            'com/simibubi/create/content/kinetics/belt/BeltInventory.class',
            'assets/create/lang/en_us.json',
        ]);

        $this->assertSame('mod', $jar['kind']);
        $this->assertSame('Create', $jar['name']);
        $this->assertSame('6.0.0', $jar['version']);
        $this->assertSame('neoforge', $jar['loader']);
        $this->assertSame(['create', 'ponder'], $jar['mod_ids']);
        // Cut to 4 segments, most classes first.
        $this->assertSame(['com/simibubi/create/content' => 2, 'com/simibubi/create' => 1], $jar['packages']);
    }

    public function test_reader_reads_plugins_and_falls_back_to_the_file_name(): void
    {
        $plugin = self::jar('MyPlugin-2.0.jar', ['plugin.yml' => "name: MyPlugin\nversion: '2.0'\nmain: de.example.myplugin.MyPlugin\n"], []);

        $this->assertSame('plugin', $plugin['kind']);
        $this->assertSame('MyPlugin', $plugin['name']);
        $this->assertSame('2.0', $plugin['version']);
        // Without classes listed, the main class still names the package.
        $this->assertSame(['de/example/myplugin' => 1], $plugin['packages']);

        $unknown = self::jar('some-library-1.2.3.jar', [], ['org/lib/Thing.class']);
        $this->assertSame('some library', $unknown['name']);
        $this->assertSame([], $unknown['mod_ids']);
    }

    public function test_fixed_owners(): void
    {
        $index = self::index();

        $this->assertSame(ModPackageIndex::MINECRAFT, $index->ownerOf('net/minecraft/server/MinecraftServer.tickServer'));
        $this->assertSame(ModPackageIndex::MINECRAFT, $index->ownerOf('com/mojang/datafixers/DataFixerUpper.update'));
        $this->assertSame(ModPackageIndex::MINECRAFT, $index->ownerOf('net/minecraft/server/MinecraftServer$$Lambda.0x0000000010f1aa00.run'));
        $this->assertSame(ModPackageIndex::LOADER, $index->ownerOf('net/fabricmc/loader/impl/Foo.bar'));
        $this->assertSame(ModPackageIndex::LOADER, $index->ownerOf('net/neoforged/neoforge/event/EventHooks.onTick'));
        $this->assertSame(ModPackageIndex::LOADER, $index->ownerOf('org/bukkit/craftbukkit/CraftServer.tick'));
        $this->assertSame(ModPackageIndex::LOADER, $index->ownerOf('io/papermc/paper/Foo.bar'));
        $this->assertSame(ModPackageIndex::JVM, $index->ownerOf('java/util/HashMap.get'));
        $this->assertSame(ModPackageIndex::JVM, $index->ownerOf('jdk/internal/misc/Unsafe.park'));
        $this->assertSame(ModPackageIndex::JVM, $index->ownerOf('sun/nio/ch/Net.poll'));
        $this->assertSame(ModPackageIndex::JVM, $index->ownerOf('Parker::park(bool, long)'));
        $this->assertSame(ModPackageIndex::JVM, $index->ownerOf('__pthread_cond_timedwait'));
        // A native library without symbols, as in the profiles with mods.
        $this->assertSame(ModPackageIndex::JVM, $index->ownerOf('/usr/lib/x86_64-linux-gnu/libc.so.6'));
        $this->assertSame(ModPackageIndex::LIBRARIES, $index->ownerOf('io/netty/channel/epoll/Native.epollWait'));
        $this->assertSame(ModPackageIndex::OTHER, $index->ownerOf('org/unknown/Thing.run'));
    }

    public function test_jars_own_their_packages_longest_first(): void
    {
        $index = self::index();

        $this->assertSame('jar:sodium-fabric-0.6.0+mc1.21.jar', $index->ownerOf('me/jellysquid/mods/sodium/client/render/chunk/Foo.render'));
        $this->assertSame('jar:lithium-fabric-0.13.0.jar', $index->ownerOf('me/jellysquid/mods/lithium/common/ai/Pathing.find'));
        $this->assertSame('jar:create-1.21.1-6.0.0.jar', $index->ownerOf('com/simibubi/create/content/kinetics/belt/BeltBlockEntity.tick'));
        $this->assertSame('jar:create-1.21.1-6.0.0.jar', $index->ownerOf('com/simibubi/create/Create.init'));
        // The library Create ships unrelocated goes with Create.
        $this->assertSame('jar:create-1.21.1-6.0.0.jar', $index->ownerOf('org/example/shadedlib/Util.call'));
        $this->assertSame('jar:MyPlugin-2.0.jar', $index->ownerOf('de/example/myplugin/listeners/JoinListener.onJoin'));

        $this->assertSame(['key' => 'jar:MyPlugin-2.0.jar', 'name' => 'MyPlugin', 'kind' => 'plugin'], $index->owner('jar:MyPlugin-2.0.jar'));
        $this->assertSame('Sodium', $index->owner('jar:sodium-fabric-0.6.0+mc1.21.jar')['name']);
        $this->assertSame(4, $index->jarCount());
    }

    public function test_mixin_handlers_in_minecraft_classes_belong_to_their_mod(): void
    {
        $index = self::index();

        $this->assertSame('jar:lithium-fabric-0.13.0.jar', $index->ownerOf('net/minecraft/world/level/Level.handler$zza000$lithium$onTick'));
        $this->assertSame('jar:create-1.21.1-6.0.0.jar', $index->ownerOf('net/minecraft/server/level/ServerLevel.redirect$bcd012$create$tickBelts'));
        // ponder is the second mod of the Create jar.
        $this->assertSame('jar:create-1.21.1-6.0.0.jar', $index->ownerOf('net/minecraft/world/entity/Entity.wrapOperation$zzb000$ponder$wrap'));
        $this->assertSame('jar:sodium-fabric-0.6.0+mc1.21.jar', $index->ownerOf('net/minecraft/client/Minecraft.modifyReturnValue$aaa000$sodium$fps'));

        // A mod id no jar declares gets an owner of its own.
        $key = $index->ownerOf('net/minecraft/world/level/Level.handler$zza001$hiddenmod$tick');
        $this->assertSame('modid:hiddenmod', $key);
        $this->assertSame(['key' => 'modid:hiddenmod', 'name' => 'hiddenmod', 'kind' => 'mod'], $index->owner($key));

        // A plain Minecraft method stays Minecraft's.
        $this->assertSame(ModPackageIndex::MINECRAFT, $index->ownerOf('net/minecraft/world/level/Level.tick'));
    }

    public function test_index_survives_the_cache(): void
    {
        $index = self::index();
        $copy = ModPackageIndex::fromArray(json_decode((string) json_encode($index->toArray()), true));

        $this->assertSame($index->ownerOf('me/jellysquid/mods/lithium/common/ai/Pathing.find'), $copy->ownerOf('me/jellysquid/mods/lithium/common/ai/Pathing.find'));
        $this->assertSame($index->owners(), $copy->owners());
    }
}
