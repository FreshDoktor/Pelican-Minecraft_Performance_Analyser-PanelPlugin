<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Support\MinecraftVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MinecraftVersionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function requirements(): array
    {
        return [
            '1.12.2' => ['1.12.2', 8],
            '1.16.5' => ['1.16.5', 8],
            '1.17' => ['1.17', 16],
            '1.17.1' => ['1.17.1', 16],
            '1.18' => ['1.18', 17],
            '1.20.4' => ['1.20.4', 17],
            '1.20.5' => ['1.20.5', 21],
            '1.21.10' => ['1.21.10', 21],
            '26.1, the test server' => ['26.1', 25],
            '26.2' => ['26.2', 25],
        ];
    }

    #[DataProvider('requirements')]
    public function test_required_java(string $minecraft, int $java): void
    {
        $this->assertSame($java, MinecraftVersion::requiredJava($minecraft));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string|null}>
     */
    public static function launches(): array
    {
        return [
            'vanilla, the test server' => ['server.jar', 'server.jar', null],
            'forge 1.20.1' => [
                'cpw.mods.bootstraplauncher.BootstrapLauncher --launchTarget forgeserver --fml.forgeVersion 47.2.0 --fml.mcVersion 1.20.1 --fml.forgeGroup net.minecraftforge --fml.mcpVersion 20230612.114412 nogui',
                'libraries/cpw/mods/bootstraplauncher/1.1.2/bootstraplauncher-1.1.2.jar:libraries/org/apache/logging/log4j/log4j-api/2.19.0/log4j-api-2.19.0.jar',
                '1.20.1',
            ],
            'neoforge 1.21.1' => ['cpw.mods.bootstraplauncher.BootstrapLauncher --launchTarget forgeserver --fml.neoForgeVersion 21.1.77 --fml.mcVersion 1.21.1 nogui', null, '1.21.1'],
            'forge class path only' => [
                'cpw.mods.bootstraplauncher.BootstrapLauncher nogui',
                'libraries/org/ow2/asm/asm/9.5/asm-9.5.jar:libraries/net/minecraft/server/1.19.2-20220805.130853/server-1.19.2-20220805.130853-extra.jar',
                '1.19.2',
            ],
            'forge 1.12.2 universal jar' => ['forge-1.12.2-14.23.5.2860.jar nogui', 'forge-1.12.2-14.23.5.2860.jar', '1.12.2'],
            'fabric launcher named by the installer' => ['fabric-server-mc.1.21.1-loader.0.16.5-launcher.1.0.1.jar nogui', null, '1.21.1'],
            'fabric launcher renamed' => ['fabric-server-launch.jar nogui', 'fabric-server-launch.jar', null],
            'old vanilla jar' => ['minecraft_server.1.12.2.jar nogui', null, '1.12.2'],
            'paper with its version' => ['/home/container/paper-1.21.4-232.jar --nogui', null, '1.21.4'],
            'paper api is no server jar' => [null, 'libraries/paper-api-1.21.4.jar:libraries/log4j-core-2.19.0.jar', null],
            'libraries are no server jars' => [null, 'libraries/com/google/guava/guava/32.1.2-jre/guava-32.1.2-jre.jar', null],
            'nothing' => [null, null, null],
        ];
    }

    #[DataProvider('launches')]
    public function test_from_launch(?string $javaCommand, ?string $classPath, ?string $expected): void
    {
        $this->assertSame($expected, MinecraftVersion::fromLaunch($javaCommand, $classPath));
    }

    public function test_from_variable(): void
    {
        $this->assertSame('1.21.1', MinecraftVersion::fromVariable('1.21.1'));
        $this->assertSame('26.1', MinecraftVersion::fromVariable(' 26.1 '));
        $this->assertSame('1.20.1', MinecraftVersion::fromVariable('1.20.1-47.2.0'));
        $this->assertNull(MinecraftVersion::fromVariable('latest'));
        $this->assertNull(MinecraftVersion::fromVariable('recommended'));
        $this->assertNull(MinecraftVersion::fromVariable('25w45a'));
        $this->assertNull(MinecraftVersion::fromVariable(''));
        $this->assertNull(MinecraftVersion::fromVariable(null));
    }

    public function test_the_newest_installed_version_wins(): void
    {
        $this->assertSame('1.21.1', MinecraftVersion::newest(['1.20.1', '1.21.1', '1.9.4']));
        $this->assertSame('1.20.1', MinecraftVersion::newest(['1.19.2-20220805.130853', '1.20.1-20230612.114412']));
        $this->assertSame('26.1', MinecraftVersion::newest(['1.21.10', '26.1', '25w45a']));
        $this->assertNull(MinecraftVersion::newest(['snapshot', '.cache']));
        $this->assertNull(MinecraftVersion::newest([]));
    }
}
