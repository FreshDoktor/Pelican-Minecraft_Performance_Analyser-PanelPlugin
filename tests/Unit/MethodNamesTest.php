<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\MappingException;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ModPackageIndex;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ProfileAnalyzer;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\MappingFiles;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\MethodNames;
use PHPUnit\Framework\TestCase;

/**
 * The mapping files are cut down by hand to a few lines in their real form (Minecraft 1.20.1: Mojang's
 * server mappings, Fabric's intermediary v2, MCPConfig's joined.tsrg; MCP stable 39-1.12), the files
 * themselves may not be passed on. Checked against the whole files on 2026-10-26: all frames of the
 * Fabric profiles (1.20.1) and the Forge 1.12 modpack get readable names, see PLAN.md step 8.
 */
class MethodNamesTest extends TestCase
{
    /** ProGuard, mojmap → official, with line numbers, a field and an inlined method. */
    protected const PROGUARD = <<<'TXT'
        # (c) 2020 Microsoft Corporation.
        net.minecraft.server.MinecraftServer -> net.minecraft.server.MinecraftServer:
        # {"fileName":"MinecraftServer.java","id":"sourceFile"}
            int tickCount -> ag
            906:940:void tickServer(java.util.function.BooleanSupplier) -> a
            814:816:void waitUntilNextTick() -> v_
            950:999:void tickChildren(java.util.function.BooleanSupplier) -> b
            1:1:void <init>() -> <init>
        net.minecraft.server.MinecraftServer$TimeProfiler -> net.minecraft.server.MinecraftServer$a:
            12:13:void <init>(long,int) -> <init>
        net.minecraft.server.level.ServerLevel -> aif:
            300:400:void tick(java.util.function.BooleanSupplier) -> a
            401:402:boolean isPositionTickingWithEntitiesLoaded(long) -> a
            403:404:net.minecraft.server.level.ServerLevel[] lambda$tick$3(net.minecraft.server.level.ServerLevel,int[]):410:411 -> b
        net.minecraft.server.level.ServerLevel$EntityCallbacks -> aif$a:
            50:51:void onTickingStart(java.lang.Object) -> a
        net.minecraft.server.level.ServerLevel$1 -> aif$1:
            60:61:void run() -> run
        TXT;

    protected const TINY = "tiny\t2\t0\tofficial\tintermediary\n"
        ."c\tnet/minecraft/server/MinecraftServer\tnet/minecraft/server/MinecraftServer\n"
        ."\tf\tI\tag\tfield_4554\n"
        ."\tm\t(Ljava/util/function/BooleanSupplier;)V\ta\tmethod_3748\n"
        ."\t\tp\t1\t\targ1\n"
        ."\tm\t()V\tv_\tmethod_16208\n"
        ."\tm\t(Ljava/util/function/BooleanSupplier;)V\tb\tmethod_3813\n"
        ."c\tnet/minecraft/server/MinecraftServer\$a\tnet/minecraft/server/MinecraftServer\$class_6414\n"
        ."c\taif\tnet/minecraft/class_3218\n"
        ."\tm\t(Ljava/util/function/BooleanSupplier;)V\ta\tmethod_18765\n"
        ."\tm\t(J)Z\ta\tmethod_37117\n"
        ."\tm\t(Laif;[I)[Laif;\tb\tmethod_31420\n"
        ."c\taif\$a\tnet/minecraft/class_3218\$class_5526\n"
        ."\tm\t(Ljava/lang/Object;)V\ta\tmethod_31430\n"
        ."c\taif\$1\tnet/minecraft/class_3218\$1\n";

    protected const TSRG2 = "tsrg2 obf srg id\n"
        ."net/minecraft/server/MinecraftServer net/minecraft/server/MinecraftServer 4977\n"
        ."\tag f_129766_ 129766\n"
        ."\ta (Ljava/util/function/BooleanSupplier;)V m_5705_ 5705\n"
        ."\t\t0 o p_129871_ 129871\n"
        ."\tv_ ()V m_130012_ 130012\n"
        ."\tb (Ljava/util/function/BooleanSupplier;)V m_5703_ 5703\n"
        ."aif net/minecraft/src/C_225_ 225\n"
        ."\ta (Ljava/util/function/BooleanSupplier;)V m_8793_ 8793\n"
        ."\ta (J)Z m_143340_ 143340\n"
        ."\t\tstatic\n"
        ."\tb (Laif;[I)[Laif; m_184049_ 184049\n";

    protected const MCP_CSV = "searge,name,side,desc\n"
        ."func_71217_p,tick,2,Main function called by run() every loop.\n"
        ."func_70071_h_,onUpdate,2,\"Called to update the entity's position/logic, every tick.\"\n"
        ."func_72835_b,tick,2,\"Runs a single tick for the world, such as blocks, weather.\"\n"
        ."func_100011_g,getIsPotionDurationMax,0,Get the value of the isPotionDurationMax field.\n";

    public function test_fabric_gets_mojangs_names_for_classes_and_methods(): void
    {
        $names = MappingFiles::intermediary(self::TINY, self::PROGUARD);

        $this->assertSame(ProfileAnalyzer::MAPPING_INTERMEDIARY, $names->mapping);
        $this->assertSame('net/minecraft/server/MinecraftServer.tickServer', $names->translate('net/minecraft/server/MinecraftServer.method_3748'));
        $this->assertSame('net/minecraft/server/level/ServerLevel.tick', $names->translate('net/minecraft/class_3218.method_18765'));
        // Same official name, the descriptor tells them apart, also with Minecraft's classes and arrays in it.
        $this->assertSame('net/minecraft/server/level/ServerLevel.isPositionTickingWithEntitiesLoaded', $names->translate('net/minecraft/class_3218.method_37117'));
        $this->assertSame('net/minecraft/server/level/ServerLevel.lambda$tick$3', $names->translate('net/minecraft/class_3218.method_31420'));
        // Inner classes, named and anonymous; one Mojang doesn't name keeps its id after the readable outer class.
        $this->assertSame('net/minecraft/server/level/ServerLevel$EntityCallbacks.onTickingStart', $names->translate('net/minecraft/class_3218$class_5526.method_31430'));
        $this->assertSame('net/minecraft/server/level/ServerLevel$1.run', $names->translate('net/minecraft/class_3218$1.run'));
        $this->assertSame('net/minecraft/server/level/ServerLevel$class_9999.run', $names->translate('net/minecraft/class_3218$class_9999.run'));
        $this->assertSame('net/minecraft/server/MinecraftServer$TimeProfiler.<init>', $names->translate('net/minecraft/server/MinecraftServer$class_6414.<init>'));
        // Lambdas, mixin handlers and the lambda classes of the JVM keep their form.
        $this->assertSame('net/minecraft/server/level/ServerLevel.lambda$tick$7', $names->translate('net/minecraft/class_3218.lambda$method_18765$7'));
        $this->assertSame('net/minecraft/server/level/ServerLevel.handler$bha000$fabric-lifecycle-events-v1$startWorldTick', $names->translate('net/minecraft/class_3218.handler$bha000$fabric-lifecycle-events-v1$startWorldTick'));
        $this->assertSame('net/minecraft/server/level/ServerLevel$$Lambda.0x000000003062d0d8.run', $names->translate('net/minecraft/class_3218$$Lambda.0x000000003062d0d8.run'));
        // A mod overriding a method of Minecraft runs it under the same id.
        $this->assertSame('com/example/mod/MyLevel.tick', $names->translate('com/example/mod/MyLevel.method_18765'));
        // Unknown ids, other frames and generated names that only look like an id stay.
        $this->assertSame('net/minecraft/class_1234.method_99999', $names->translate('net/minecraft/class_1234.method_99999'));
        $this->assertSame('dev/onyxstudios/cca/_generated_/GeneratedComponentContainer_EntityImpl_class_1657_13562080.tickServerComponents', $names->translate('dev/onyxstudios/cca/_generated_/GeneratedComponentContainer_EntityImpl_class_1657_13562080.tickServerComponents'));
        $this->assertSame('Unsafe_Park', $names->translate('Unsafe_Park'));
        $this->assertSame('/usr/lib/x86_64-linux-gnu/libc.so.6', $names->translate('/usr/lib/x86_64-linux-gnu/libc.so.6'));
    }

    public function test_forge_srg_gets_mojangs_method_names(): void
    {
        $names = MappingFiles::srg(self::TSRG2, self::PROGUARD);

        $this->assertSame(ProfileAnalyzer::MAPPING_SRG, $names->mapping);
        $this->assertSame('net/minecraft/server/MinecraftServer.tickServer', $names->translate('net/minecraft/server/MinecraftServer.m_5705_'));
        $this->assertSame('net/minecraft/server/MinecraftServer.waitUntilNextTick', $names->translate('net/minecraft/server/MinecraftServer.m_130012_'));
        $this->assertSame('net/minecraft/server/level/ServerLevel.isPositionTickingWithEntitiesLoaded', $names->translate('net/minecraft/server/level/ServerLevel.m_143340_'));
        $this->assertSame('net/minecraft/server/level/ServerLevel.lambda$tick$3', $names->translate('net/minecraft/server/level/ServerLevel.lambda$m_8793_$3'));
        $this->assertSame('net/minecraft/server/level/ServerLevel.lambda$tick$3', $names->translate('net/minecraft/server/level/ServerLevel.m_184049_'));
        // Fields and parameters are left out, so are other mods' names that look alike.
        $this->assertSame('com/example/Foo.m_1_x', $names->translate('com/example/Foo.m_1_x'));
        $this->assertSame(6, $names->count());
    }

    public function test_forge_mcp_gets_mcps_method_names(): void
    {
        $names = MappingFiles::mcp(self::MCP_CSV);

        $this->assertSame('net/minecraft/server/MinecraftServer.tick', $names->translate('net/minecraft/server/MinecraftServer.func_71217_p'));
        $this->assertSame('net/minecraft/world/WorldServer.tick', $names->translate('net/minecraft/world/WorldServer.func_72835_b'));
        // Ids ending in an underscore, here in a mod's entity.
        $this->assertSame('com/Fishmod/mod_LavaCow/entities/EntityMummy.onUpdate', $names->translate('com/Fishmod/mod_LavaCow/entities/EntityMummy.func_70071_h_'));
        $this->assertSame(4, $names->count());
    }

    public function test_files_of_another_form_are_refused(): void
    {
        foreach ([
            fn () => MappingFiles::intermediary("tiny\t2\t0\tintermediary\tnamed\n", self::PROGUARD),
            fn () => MappingFiles::intermediary(self::TINY, '<html>Not found</html>'),
            fn () => MappingFiles::srg("tsrg2 obf srg id\na b 1\n", self::PROGUARD),
            fn () => MappingFiles::mcp("<html>\n"),
        ] as $i => $build) {
            try {
                $build();
                $this->fail("case $i was accepted");
            } catch (MappingException $exception) {
                $this->assertFalse($exception->unavailable, "case $i");
            }
        }
    }

    public function test_the_analysis_shows_readable_names_and_keeps_the_original(): void
    {
        $index = ModPackageIndex::fromJars([
            'immersivesnow-fabric-1.21-1.0.jar' => ModPackageIndexTest::jar('immersivesnow-fabric-1.21-1.0.jar', [
                'fabric.mod.json' => '{"id": "immersivesnow", "name": "Immersive Snow", "version": "1.0"}',
            ], ['straywave/minecraft/immersivesnow/ImmersiveSnowEvents.class']),
        ]);
        $analysis = (new ProfileAnalyzer)->analyze(ProfileAnalyzerTest::fixture('profile-fabric-immersive-snow-g1'), $index);
        $names = new MethodNames(ProfileAnalyzer::MAPPING_INTERMEDIARY, ['net/minecraft/class_3218' => 'net/minecraft/server/level/ServerLevel', 'net/minecraft/class_1937' => 'net/minecraft/world/level/Level'], [
            'method_18765' => 'tick',
            'method_30092' => 'setBlock',
            'method_3748' => 'tickServer',
            'method_47541' => 'lambda$getEntities$14',
        ]);

        $readable = $names->apply($analysis);

        // What is counted doesn't change.
        $this->assertSame($analysis['owners'], $readable['owners']);
        $this->assertSame($analysis['flame']['stacks'], $readable['flame']['stacks']);
        $this->assertSame(array_column($analysis['top_methods'], 'self'), array_column($readable['top_methods'], 'self'));

        $frames = array_column($readable['flame']['frames'], 2, 0);
        $this->assertSame('net/minecraft/server/MinecraftServer.method_3748', $frames['net/minecraft/server/MinecraftServer.tickServer']);
        $this->assertSame('net/minecraft/class_3218.method_18765', $frames['net/minecraft/server/level/ServerLevel.tick']);
        // Frames without a translation keep two fields.
        $this->assertCount(2, $readable['flame']['frames'][array_search('straywave/minecraft/immersivesnow/Utils.setBlock', array_column($readable['flame']['frames'], 0), true)]);

        $top = array_column($readable['top_methods'], null, 'frame');
        $this->assertArrayNotHasKey('raw_frame', $top['straywave/minecraft/immersivesnow/Utils.setBlock']);
        $this->assertSame('net/minecraft/class_3218.method_47541', $top['net/minecraft/server/level/ServerLevel.lambda$getEntities$14']['raw_frame'] ?? null);
    }

    /**
     * The original names go along with the readable ones, the 1.12 modpack has 522 frames with an id.
     */
    public function test_the_flamegraph_with_readable_names_stays_small(): void
    {
        $analysis = (new ProfileAnalyzer)->analyze(ProfileAnalyzerTest::fixture('profile-forge-1.12-modpack'), new ModPackageIndex);
        $methods = [];

        foreach ($analysis['flame']['frames'] as [$frame]) {
            if (preg_match('/func_\d+_[a-zA-Z]+_?$/', $frame, $m)) {
                // About as long as MCP's names.
                $methods[$m[0]] = 'readableNameOf'.substr(md5($m[0]), 0, 6);
            }
        }

        $flame = (new MethodNames(ProfileAnalyzer::MAPPING_MCP, [], $methods))->apply($analysis)['flame'];

        $this->assertCount(522, array_filter($flame['frames'], fn (array $frame) => count($frame) === 3));
        $this->assertLessThan(360 * 1024, strlen((string) json_encode($flame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }

    public function test_stored_names_round_trip(): void
    {
        $names = MappingFiles::intermediary(self::TINY, self::PROGUARD);
        $data = json_decode((string) json_encode($names->toArray()), true);

        $this->assertSame('net/minecraft/server/level/ServerLevel.tick', MethodNames::fromArray($data)?->translate('net/minecraft/class_3218.method_18765'));
        $this->assertNull(MethodNames::fromArray([...$data, 'format' => 0]));
        $this->assertNull(MethodNames::fromArray([...$data, 'mapping' => 'mojmap']));
        $this->assertTrue(MethodNames::supports('srg'));
        $this->assertFalse(MethodNames::supports('mojmap'));
        $this->assertFalse(MethodNames::supports(null));
    }

    public function test_the_mcpconfig_and_mcp_versions_for_a_minecraft_version(): void
    {
        $xml = '<metadata><versioning><versions><version>1.20.1</version><version>1.20.1-20230612.114412</version><version>1.20.1-rc1-20230610.121955</version>'
            .'<version>1.20.1-20230601.000000</version><version>1.20.2</version><version>1.19.4-20230314.122934</version></versions></versioning></metadata>';
        $versions = MappingFiles::mavenVersions($xml);

        $this->assertSame('1.20.1-20230612.114412', MappingFiles::mcpConfigVersion($versions, '1.20.1'));
        $this->assertSame('1.20.2', MappingFiles::mcpConfigVersion($versions, '1.20.2'));
        $this->assertNull(MappingFiles::mcpConfigVersion($versions, '1.20'));

        $stable = ['39-1.12', '29-1.11', '22-1.10.2', '60-1.15', '58-1.14.4', '43-1.13', '41-1.13'];
        $snapshot = ['20180814-1.12', '20171003-1.12', '20210309-1.16.5', '20201028-1.16.3'];

        // 1.12.2 has none of its own, 1.12's serve; stable before snapshot.
        $this->assertSame(['channel' => 'stable', 'version' => '39-1.12'], MappingFiles::mcpVersion($stable, $snapshot, '1.12.2'));
        $this->assertSame(['channel' => 'stable', 'version' => '43-1.13'], MappingFiles::mcpVersion($stable, $snapshot, '1.13'));
        $this->assertSame(['channel' => 'snapshot', 'version' => '20210309-1.16.5'], MappingFiles::mcpVersion($stable, $snapshot, '1.16.5'));
        // Neither its own nor 1.16's: another 1.16.x serves.
        $this->assertSame(['channel' => 'snapshot', 'version' => '20210309-1.16.5'], MappingFiles::mcpVersion($stable, $snapshot, '1.16.4'));
        $this->assertNull(MappingFiles::mcpVersion($stable, $snapshot, '1.7.10'));
        $this->assertNull(MappingFiles::mcpVersion($stable, $snapshot, '1.1'));
    }
}
