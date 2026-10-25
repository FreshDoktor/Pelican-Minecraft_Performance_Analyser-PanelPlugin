<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Services\HistogramAnalyzer;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ModPackageIndex;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ThreadDumpAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * histogram.json and threads.json are the contract's examples from the vanilla test server (wing 20f2692).
 * Mods are added by hand where needed, there are no fixtures of a modded server yet.
 */
class HistogramAndThreadsTest extends TestCase
{
    public function test_histogram_is_grouped_by_the_owner_of_the_element_type(): void
    {
        $analysis = (new HistogramAnalyzer)->analyze(DiagnosticsTest::example('histogram'), new ModPackageIndex);

        $this->assertSame(['classes' => 16670, 'instances' => 3594114, 'bytes' => 150843200], $analysis['total']);
        $this->assertSame(16570, $analysis['omitted']['classes']);
        $this->assertSame(18776752, $analysis['omitted']['bytes']);
        $this->assertSame(100, $analysis['limit']);

        $owners = array_column($analysis['owners'], null, 'key');

        // [B, [J, [I … are the JVM's, [Lnet.minecraft…StateHolder; Minecraft's, fastutil a library.
        $this->assertArrayHasKey(ModPackageIndex::JVM, $owners);
        $this->assertArrayHasKey(ModPackageIndex::MINECRAFT, $owners);
        $this->assertArrayHasKey(ModPackageIndex::LIBRARIES, $owners);

        $jvmClasses = array_column($owners[ModPackageIndex::JVM]['classes'], 'readable');
        $this->assertContains('byte[]', $jvmClasses);
        $this->assertContains('java.lang.Object[]', $jvmClasses);

        $minecraftClasses = array_column($owners[ModPackageIndex::MINECRAFT]['classes'], 'readable');
        $this->assertContains('net.minecraft.world.level.block.state.StateHolder[][]', $minecraftClasses);
        $this->assertContains('com.mojang.brigadier.context.CommandContext', $minecraftClasses);

        // Listed classes plus the omitted ones make up the total.
        $listed = array_sum(array_column($analysis['owners'], 'bytes'));
        $this->assertSame(150843200, $listed + $analysis['omitted']['bytes']);
        $this->assertSame(100, array_sum(array_column($analysis['owners'], 'class_count')));

        // Largest owner first, byte arrays alone make the JVM the largest.
        $this->assertSame(ModPackageIndex::JVM, $analysis['owners'][0]['key']);
        $this->assertSame(32965352, $owners[ModPackageIndex::JVM]['classes'][0]['bytes']);
    }

    public function test_histogram_classes_of_mods_go_to_their_jar(): void
    {
        $histogram = DiagnosticsTest::example('histogram');
        $histogram['classes'][] = ['rank' => 101, 'name' => '[Lcom.simibubi.create.content.kinetics.belt.BeltBlockEntity;', 'element_type' => 'com.simibubi.create.content.kinetics.belt.BeltBlockEntity', 'array_depth' => 1, 'module' => null, 'instances' => 10, 'bytes' => 4096];
        $histogram['classes'][] = ['rank' => 102, 'name' => 'me.jellysquid.mods.lithium.common.ai.Pathing', 'element_type' => 'me.jellysquid.mods.lithium.common.ai.Pathing', 'array_depth' => 0, 'module' => null, 'instances' => 3, 'bytes' => 96];

        $owners = array_column((new HistogramAnalyzer)->analyze($histogram, ModPackageIndexTest::index())['owners'], null, 'key');

        $this->assertSame(4096, $owners['jar:create-1.21.1-6.0.0.jar']['bytes']);
        $this->assertSame('com.simibubi.create.content.kinetics.belt.BeltBlockEntity[]', $owners['jar:create-1.21.1-6.0.0.jar']['classes'][0]['readable']);
        $this->assertSame(96, $owners['jar:lithium-fabric-0.13.0.jar']['bytes']);
    }

    public function test_class_names_of_the_jvm(): void
    {
        $index = new ModPackageIndex;

        $this->assertSame(ModPackageIndex::JVM, $index->ownerOfClass('byte'));
        $this->assertSame(ModPackageIndex::JVM, $index->ownerOfClass('java.lang.String'));
        $this->assertSame(ModPackageIndex::MINECRAFT, $index->ownerOfClass('net.minecraft.server.MinecraftServer$$Lambda/0x0000000010f1aa00'));
        $this->assertSame(ModPackageIndex::OTHER, $index->ownerOfClass('NoPackage'));
        $this->assertSame('net/minecraft/server/MinecraftServer$$Lambda/0x0000000010f1aa00.run', ModPackageIndex::frameFromDotted('net.minecraft.server.MinecraftServer$$Lambda/0x0000000010f1aa00.run'));
    }

    public function test_thread_dump_of_the_example(): void
    {
        $analysis = (new ThreadDumpAnalyzer)->analyze(DiagnosticsTest::example('threads'), new ModPackageIndex);

        $this->assertSame(28, $analysis['total']);
        $this->assertSame(['RUNNABLE' => 13, 'BLOCKED' => 0, 'WAITING' => 1, 'TIMED_WAITING' => 12, 'NEW' => 0, 'TERMINATED' => 0, 'VM' => 2], $analysis['states']);
        $this->assertSame([], $analysis['deadlocks']);
        $this->assertStringStartsWith('2026-10-25 13:09:43', $analysis['raw']);

        // The busiest first: C2 CompilerThread0 with 7884 ms.
        $this->assertSame('C2 CompilerThread0', $analysis['threads'][0]['name']);

        $byName = array_column($analysis['threads'], null, 'name');

        // The State line counts, not the header ("runnable").
        $server = $byName['Server thread'];
        $this->assertSame('TIMED_WAITING', $server['state']);
        $this->assertSame('parking', $server['state_detail']);
        $this->assertSame(656.31, $server['cpu_ms']);
        $this->assertEqualsWithDelta(656.31 / 19430 * 100, $server['cpu_percent'], 0.01);
        $this->assertSame(ModPackageIndex::MINECRAFT, $server['owner']['key']);
        $this->assertSame('at jdk.internal.misc.Unsafe.park(java.base@25.0.4.1/Native Method)', $server['stack'][0]);
        $this->assertSame('  - parking <0x00000000b82a7ff0> (a java.lang.String)', $server['stack'][1]);
        $this->assertSame('java.lang.String <0x00000000b82a7ff0>', $server['waiting_for']);

        // The Finalizer waits on the monitor it holds itself (Object.wait), no other thread holds it.
        $this->assertNull($byName['Finalizer']['held_by']);

        // VM threads have no stack and no state.
        $this->assertSame(ThreadDumpAnalyzer::VM, $byName['VM Thread']['state']);
        $this->assertSame([], $byName['VM Thread']['stack']);
        $this->assertNull($byName['VM Thread']['owner']);

        $this->assertSame(ModPackageIndex::LIBRARIES, $byName['Netty Epoll IO #0']['owner']['key']);
    }

    public function test_deadlocks_come_first_and_name_the_holder(): void
    {
        $lockA = ['type' => 'locked', 'address' => '0x1', 'class' => 'java.lang.Object'];
        $lockB = ['type' => 'locked', 'address' => '0x2', 'class' => 'java.lang.Object'];

        $dump = [
            'sampled_at' => 1,
            'duration_ms' => 2,
            'threads' => [
                self::thread('Busy', 'RUNNABLE', 5000.0, []),
                self::thread('Worker A', 'BLOCKED', 10.0, [
                    ['method' => 'com.simibubi.create.Foo.a', 'locks' => [['type' => 'waiting_to_lock', 'address' => '0x2', 'class' => 'java.lang.Object']]],
                    ['method' => 'com.simibubi.create.Foo.run', 'locks' => [$lockA]],
                ]),
                self::thread('Worker B', 'BLOCKED', 20.0, [
                    ['method' => 'com.simibubi.create.Foo.b', 'locks' => [['type' => 'waiting_to_lock', 'address' => '0x1', 'class' => 'java.lang.Object']]],
                    ['method' => 'com.simibubi.create.Foo.run', 'locks' => [$lockB]],
                ]),
            ],
            'deadlocks' => [[
                'threads' => [
                    ['name' => 'Worker A', 'waiting_for' => 'waiting to lock monitor 0x2', 'held_by' => 'Worker B'],
                    ['name' => 'Worker B', 'waiting_for' => 'waiting to lock monitor 0x1', 'held_by' => 'Worker A'],
                ],
            ]],
            'raw' => '',
        ];

        $analysis = (new ThreadDumpAnalyzer)->analyze($dump, ModPackageIndexTest::index());

        $this->assertSame(['Worker B', 'Worker A', 'Busy'], array_column($analysis['threads'], 'name'));
        $this->assertTrue($analysis['threads'][0]['deadlocked']);
        $this->assertSame('Worker A', $analysis['threads'][0]['held_by']);
        $this->assertSame('Worker B', $analysis['threads'][1]['held_by']);
        $this->assertSame('jar:create-1.21.1-6.0.0.jar', $analysis['threads'][0]['owner']['key']);
        $this->assertSame(2, $analysis['states']['BLOCKED']);
        $this->assertCount(1, $analysis['deadlocks']);
    }

    /**
     * @param  list<array<string, mixed>>  $stack
     * @return array<string, mixed>
     */
    protected static function thread(string $name, string $state, float $cpu, array $stack): array
    {
        return [
            'name' => $name,
            'java_id' => 1,
            'os_id' => 1,
            'vm_thread' => false,
            'daemon' => false,
            'cpu_ms' => $cpu,
            'elapsed_ms' => 60000.0,
            'state' => $state,
            'state_detail' => null,
            'stack' => array_map(fn (array $frame) => [...$frame, 'module' => null, 'file' => 'Foo.java', 'line' => 1, 'native' => false], $stack),
            'locked_synchronizers' => [],
        ];
    }
}
