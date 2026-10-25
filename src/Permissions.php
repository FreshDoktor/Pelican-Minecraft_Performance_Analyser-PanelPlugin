<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser;

use App\Models\Server;
use App\Models\Subuser;

/**
 * Subuser permissions of the plugin, the group "performance" in the subuser form of a server.
 *
 * Subuser::allPermissionKeys() joins group and permission with a dot, so the keys are performance.read
 * and so on. User::can() checks them like Pelican's own ones once they are registered: owners and
 * admins who may update the server always, subusers if the key is in their permissions.
 *
 * Plain strings on purpose: User::can() only takes strings and SubuserPermission, any other enum
 * would not reach the subuser check.
 */
final class Permissions
{
    public const GROUP = 'performance';

    /** The page, the overview and the diagnosis. */
    public const READ = 'performance.read';

    /** Starting a lag analysis (profile). */
    public const PROFILE = 'performance.profile';

    /** A class histogram, which pauses the server for a moment. */
    public const MEMORY = 'performance.memory';

    /** A thread dump. */
    public const THREADS = 'performance.threads';

    public static function register(): void
    {
        // The icon has to be given: allPermissionData() reads it without a fallback for groups of its own.
        Subuser::registerCustomPermissions(
            self::GROUP,
            ['read', 'profile', 'memory', 'threads'],
            MinecraftPerformanceAnalyserPlugin::ID.'::strings.permissions',
            'tabler-activity-heartbeat',
            false,
        );
    }

    public static function check(string $permission, Server $server): bool
    {
        return user()?->can($permission, $server) ?? false;
    }
}
