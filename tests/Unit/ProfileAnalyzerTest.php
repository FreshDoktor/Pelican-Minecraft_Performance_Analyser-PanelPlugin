<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Services\ModPackageIndex;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ProfileAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * profile-done.json and profile-done-no-samples.json are the examples of the contract (vanilla test
 * server, constructed from its thread dump). The recorded profiles with mods are in tests/fixtures (cut
 * down to the Server thread, PLAN.md section 8); smaller cases are built by hand.
 */
class ProfileAnalyzerTest extends TestCase
{
    protected const RUN = 'java/lang/Thread.run;net/minecraft/server/MinecraftServer.runServer';

    protected const TICK = self::RUN.';net/minecraft/server/MinecraftServer.tickServer;net/minecraft/server/MinecraftServer.tickChildren;net/minecraft/server/level/ServerLevel.tick';

    public function test_the_vanilla_example_only_waited_between_ticks(): void
    {
        $analysis = (new ProfileAnalyzer)->analyze(DiagnosticsTest::example('profile-done'), new ModPackageIndex);

        // All 201 samples of the Server thread are in waitUntilNextTick: paused or no load.
        $this->assertSame(ProfileAnalyzer::STATE_IDLE, $analysis['state']);
        $this->assertSame(201, $analysis['server_thread']['samples']);
        $this->assertSame(201, $analysis['server_thread']['idle']);
        $this->assertSame(0, $analysis['server_thread']['work']);
        $this->assertSame(0.0, $analysis['server_thread']['tick_load_percent']);
        // 201 × 50 ms
        $this->assertSame(10050.0, $analysis['server_thread']['idle_ms']);
        $this->assertSame([], $analysis['owners']);
        $this->assertSame(60, $analysis['server_thread']['tid']);

        // The other threads are listed, the flamegraph has the two idle stacks.
        $this->assertSame('Server Watchdog', $analysis['other_threads'][0]['name']);
        $this->assertCount(2, $analysis['flame']['stacks']);
        $this->assertTrue($analysis['flame']['stacks'][0][2]);

        $this->assertSame(1, $analysis['gc']['count']);
        $this->assertSame([['kind' => 'young', 'start_offset_ms' => 4183.211, 'duration_ms' => 20.052]], $analysis['gc']['pauses']);
        $this->assertSame(0.4, $analysis['cpu_percent']);
    }

    public function test_no_samples_is_reported_as_such(): void
    {
        $analysis = (new ProfileAnalyzer)->analyze(DiagnosticsTest::example('profile-done-no-samples'), new ModPackageIndex);

        $this->assertSame(ProfileAnalyzer::STATE_NO_SAMPLES, $analysis['state']);
        $this->assertSame(['no_samples'], $analysis['warnings']);
        $this->assertNull($analysis['server_thread']);
    }

    public function test_without_a_server_thread(): void
    {
        $profile = self::profile([['name' => 'Velocity Main', 'stacks' => [['stack' => 'java/lang/Thread.run', 'count' => 5]]]]);

        $this->assertSame(ProfileAnalyzer::STATE_NO_SERVER_THREAD, (new ProfileAnalyzer)->analyze($profile, new ModPackageIndex)['state']);
    }

    public function test_shares_per_mod_with_self_and_inclusive_time(): void
    {
        $profile = self::profile([self::serverThread([
            // Idle between ticks: not counted.
            [self::RUN.';net/minecraft/server/MinecraftServer.waitUntilNextTick;jdk/internal/misc/Unsafe.park;Unsafe_Park', 60],
            // Create's belts, ending in a HashMap: Create's own time.
            [self::TICK.';com/simibubi/create/content/kinetics/belt/BeltBlockEntity.tick;java/util/HashMap.get', 20],
            // Create calls back into Minecraft: Minecraft's own time, Create's inclusive.
            [self::TICK.';com/simibubi/create/content/kinetics/belt/BeltBlockEntity.tick;net/minecraft/world/level/Level.getBlockState', 10],
            // A Lithium mixin in Level.tick.
            [self::TICK.';net/minecraft/world/level/Level.handler$zza000$lithium$onTick;me/jellysquid/mods/lithium/common/ai/Pathing.find', 5],
            // Plain Minecraft.
            [self::TICK.';net/minecraft/world/entity/Entity.tick', 5],
        ])]);

        $analysis = (new ProfileAnalyzer)->analyze($profile, ModPackageIndexTest::index());
        $server = $analysis['server_thread'];

        $this->assertSame(ProfileAnalyzer::STATE_OK, $analysis['state']);
        $this->assertSame(100, $server['samples']);
        $this->assertSame(60, $server['idle']);
        $this->assertSame(40, $server['work']);
        $this->assertSame(40.0, $server['tick_load_percent']);
        $this->assertSame(2000.0, $server['work_ms']);

        $owners = [];
        foreach ($analysis['owners'] as $owner) {
            $owners[$owner['key']] = [$owner['self'], $owner['inclusive'], $owner['self_percent'], $owner['inclusive_percent']];
        }

        $create = 'jar:create-1.21.1-6.0.0.jar';
        $lithium = 'jar:lithium-fabric-0.13.0.jar';

        // Self times add up to the tick work (40), percentages are of the tick work.
        $this->assertSame([20, 30, 50.0, 75.0], $owners[$create]);
        $this->assertSame([15, 40, 37.5, 100.0], $owners[ModPackageIndex::MINECRAFT]);
        $this->assertSame([5, 5, 12.5, 12.5], $owners[$lithium]);
        // The JVM is never the self owner while a caller of another owner is in the stack, but inclusive.
        $this->assertSame([0, 40, 0.0, 100.0], $owners[ModPackageIndex::JVM]);
        $this->assertSame(40, array_sum(array_column($analysis['owners'], 'self')));

        // Sorted by self time.
        $this->assertSame($create, $analysis['owners'][0]['key']);
        $this->assertSame('Create', $analysis['owners'][0]['name']);

        // Top methods: the deepest frame outside the JVM.
        $top = $analysis['top_methods'][0];
        $this->assertSame('com/simibubi/create/content/kinetics/belt/BeltBlockEntity.tick', $top['frame']);
        $this->assertSame($create, $top['owner']);
        $this->assertSame(20, $top['self']);
        $this->assertSame(30, $top['inclusive']);

        // Flamegraph: every stack with its frames, the frames with their owners.
        $this->assertCount(5, $analysis['flame']['stacks']);
        $frames = array_column($analysis['flame']['frames'], 0);
        $mixin = array_search('net/minecraft/world/level/Level.handler$zza000$lithium$onTick', $frames, true);
        $this->assertNotFalse($mixin);
        $this->assertSame($lithium, $analysis['flame']['owners'][$analysis['flame']['frames'][$mixin][1]]['key']);
    }

    public function test_waiting_inside_a_tick_is_lag_not_idle(): void
    {
        $analyzer = new ProfileAnalyzer;

        // The example's idle stack.
        $this->assertTrue($analyzer->isIdle(explode(';', 'java/lang/Thread.run;net/minecraft/server/MinecraftServer.runServer;net/minecraft/server/MinecraftServer.waitUntilNextTick;net/minecraft/util/thread/BlockableEventLoop.managedBlock;net/minecraft/server/MinecraftServer.waitForTasks;jdk/internal/misc/Unsafe.park')));
        // Waiting for a chunk inside a tick.
        $this->assertFalse($analyzer->isIdle(explode(';', self::TICK.';net/minecraft/server/level/ServerChunkCache.getChunk;net/minecraft/server/MinecraftServer.waitForTasks;jdk/internal/misc/Unsafe.park')));
        $this->assertFalse($analyzer->isIdle(explode(';', self::TICK)));
    }

    public function test_tick_and_idle_methods_of_every_mapping(): void
    {
        $analyzer = new ProfileAnalyzer;
        $classify = fn (string $stack) => $analyzer->classify(explode(';', $stack));
        $server = 'net/minecraft/server/MinecraftServer.';

        // Fabric (intermediary): BlockableEventLoop is class_1255, waitForTasks is its method.
        $this->assertSame(['idle', 'intermediary'], $classify('java/lang/Thread.run;'.$server.'method_29741;'.$server.'method_16208;net/minecraft/class_1255.method_18857;net/minecraft/class_1255.method_20813;java/util/concurrent/locks/LockSupport.parkNanos'));
        $this->assertSame(['tick', 'intermediary'], $classify($server.'method_29741;'.$server.'method_3748;net/minecraft/class_3176.method_3813'));
        // Forge 1.17 to 1.20 (srg): classes in Mojmap, methods in srg.
        $this->assertSame(['idle', 'srg'], $classify($server.'m_130011_;'.$server.'m_130012_;net/minecraft/util/thread/BlockableEventLoop.m_18701_;net/minecraft/util/thread/BlockableEventLoop.m_5667_'));
        $this->assertSame(['tick', 'srg'], $classify($server.'m_130011_;'.$server.'m_5705_;net/minecraft/server/dedicated/DedicatedServer.m_5703_;'.$server.'m_5703_'));
        // Forge 1.14 to 1.16 (mcp).
        $this->assertSame(['idle', 'mcp'], $classify($server.'func_240802_v_;'.$server.'func_213202_o;net/minecraft/util/concurrent/ThreadTaskExecutor.func_213161_c'));
        $this->assertSame(['tick', 'mcp'], $classify($server.'func_240802_v_;'.$server.'func_71217_p'));
        // Up to 1.13: Thread.sleep right in MinecraftServer.run, not below a tick.
        $this->assertSame(['idle', 'mcp'], $classify('java/lang/Thread.run;'.$server.'run;java/lang/Thread.sleep;java/lang/Thread.sleepNanos'));
        $this->assertSame(['tick', 'mcp'], $classify($server.'run;'.$server.'func_71217_p;java/lang/Thread.sleep'));
        // Obfuscated vanilla before 26.1, or a stack the profiler couldn't walk.
        $this->assertSame([null, null], $classify($server.'w;'.$server.'a;net/minecraft/aqx.a'));
        $this->assertSame([null, null], $classify('[unknown_Java];I2C/C2I adapters'));
    }

    public function test_without_known_tick_methods_the_tick_load_is_unknown(): void
    {
        $profile = self::profile([self::serverThread([
            ['java/lang/Thread.run;net/minecraft/server/MinecraftServer.w;net/minecraft/server/MinecraftServer.y;jdk/internal/misc/Unsafe.park', 60],
            ['java/lang/Thread.run;net/minecraft/server/MinecraftServer.w;net/minecraft/server/MinecraftServer.a;net/minecraft/aqx.a', 40],
        ])]);

        $analysis = (new ProfileAnalyzer)->analyze($profile, new ModPackageIndex);

        // The waiting can't be told apart: everything is work, but no tick load is claimed.
        $this->assertSame(ProfileAnalyzer::STATE_OK, $analysis['state']);
        $this->assertNull($analysis['server_thread']['mapping']);
        $this->assertSame(100, $analysis['server_thread']['work']);
        $this->assertNull($analysis['server_thread']['tick_load_percent']);
    }

    public function test_fabric_without_players_mostly_waits(): void
    {
        $analysis = (new ProfileAnalyzer)->analyze(self::fixture('profile-fabric-idle'), new ModPackageIndex);
        $server = $analysis['server_thread'];

        $this->assertSame('intermediary', $server['mapping']);
        $this->assertSame(599, $server['samples']);
        // 520 samples below method_16208 (waitUntilNextTick): 86.8 % idle.
        $this->assertSame(520, $server['idle']);
        $this->assertSame(13.19, $server['tick_load_percent']);
    }

    public function test_immersive_snow_is_the_lag_of_the_mod(): void
    {
        $index = ModPackageIndex::fromJars([
            'immersivesnow-fabric-1.21-1.0.jar' => ModPackageIndexTest::jar('immersivesnow-fabric-1.21-1.0.jar', [
                'fabric.mod.json' => '{"id": "immersivesnow", "name": "Immersive Snow", "version": "1.0"}',
            ], ['straywave/minecraft/immersivesnow/ImmersiveSnowEvents.class']),
        ]);

        // The mod loads chunks in its world tick and waits for them (getChunkBlocking, then waitForTasks).
        foreach (['profile-fabric-immersive-snow-serial' => [97.67, 76.71, 81.91], 'profile-fabric-immersive-snow-g1' => [95.67, 70.56, 76.48]] as $fixture => [$load, $self, $inclusive]) {
            $analysis = (new ProfileAnalyzer)->analyze(self::fixture($fixture), $index);
            $mod = $analysis['owners'][0];

            $this->assertSame($load, $analysis['server_thread']['tick_load_percent'], $fixture);
            $this->assertSame('Immersive Snow', $mod['name'], $fixture);
            $this->assertSame($self, $mod['self_percent'], $fixture);
            $this->assertSame($inclusive, $mod['inclusive_percent'], $fixture);
            // The waiting goes to the mod's deepest frame, not to Minecraft's waitForTasks.
            $this->assertSame('straywave/minecraft/immersivesnow/Utils.setBlock', $analysis['top_methods'][0]['frame'], $fixture);
        }
    }

    public function test_waiting_inside_a_tick_without_a_mod_stays_minecraft(): void
    {
        $wait = ';net/minecraft/server/level/ServerChunkCache.getChunk;net/minecraft/util/thread/BlockableEventLoop.managedBlock;net/minecraft/util/thread/BlockableEventLoop.waitForTasks;jdk/internal/misc/Unsafe.park';
        $profile = self::profile([self::serverThread([
            [self::TICK.';com/simibubi/create/content/kinetics/belt/BeltBlockEntity.tick'.$wait, 30],
            [self::TICK.';net/minecraft/world/entity/Entity.tick'.$wait, 10],
        ])]);

        $owners = array_column((new ProfileAnalyzer)->analyze($profile, ModPackageIndexTest::index())['owners'], 'self', 'key');

        $this->assertSame(30, $owners['jar:create-1.21.1-6.0.0.jar']);
        $this->assertSame(10, $owners[ModPackageIndex::MINECRAFT]);
    }

    public function test_the_forge_1_12_modpack_is_overloaded(): void
    {
        $analysis = (new ProfileAnalyzer)->analyze(self::fixture('profile-forge-1.12-modpack'), new ModPackageIndex);
        $server = $analysis['server_thread'];

        $this->assertSame('mcp', $server['mapping']);
        // Only 17 of 1196 samples in Thread.sleep of MinecraftServer.run: it can't keep 20 TPS.
        $this->assertSame(17, $server['idle']);
        $this->assertSame(98.58, $server['tick_load_percent']);
    }

    public function test_cpu_profiles_have_no_tick_load(): void
    {
        $profile = self::profile([self::serverThread([[self::TICK.';net/minecraft/world/entity/Entity.tick', 10]])], 'cpu', 10);

        $analysis = (new ProfileAnalyzer)->analyze($profile, new ModPackageIndex);

        // Only CPU time is sampled, so the share of time the thread worked can't be told.
        $this->assertNull($analysis['server_thread']['tick_load_percent']);
        $this->assertSame(100.0, $analysis['server_thread']['work_ms']);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function fixture(string $name): array
    {
        $json = file_get_contents(dirname(__DIR__).'/fixtures/'.$name.'.json');

        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $stacks
     * @return array<string, mixed>
     */
    protected static function serverThread(array $stacks): array
    {
        return [
            'name' => ProfileAnalyzer::SERVER_THREAD,
            'tid' => 60,
            'count' => array_sum(array_column($stacks, 1)),
            'stacks' => array_map(fn (array $stack) => ['stack' => $stack[0], 'count' => $stack[1]], $stacks),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $threads
     * @return array<string, mixed>
     */
    protected static function profile(array $threads, string $mode = 'wall', int $interval = 50): array
    {
        $profile = DiagnosticsTest::example('profile-done');
        $profile['mode'] = $mode;
        $profile['interval_ms'] = $interval;
        $profile['result']['interval_ms'] = $interval;
        $profile['result']['threads'] = $threads;
        $profile['result']['total'] = array_sum(array_map(fn (array $thread) => array_sum(array_column($thread['stacks'], 'count')), $threads));

        return $profile;
    }
}
