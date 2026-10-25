<?php

return [
    // Port of the MPA Bridge, used for nodes without a bridge address of their own (admin area → node →
    // tab MPA Bridge). The address is then <scheme of the node>://<FQDN of the node>:<port>.
    'default_port' => (int) env('MPA_BRIDGE_PORT', 8090),

    // Seconds between two updates of the live values while the overview is open, 2 to 5.
    'poll_interval' => (int) env('MPA_POLL_INTERVAL', 3),

    // Seconds to wait for the bridge. Thread dumps and histograms walk the JVM and get more time, see BridgeClient.
    'timeout' => 10,
    'connect_timeout' => 3,

    // Finished lag analyses kept per server on the panel's local disk, for downloading and comparing.
    'keep_profiles' => 5,

    // Classes of a memory histogram the bridge lists one by one (the largest), the rest only in the total.
    'histogram_limit' => 2000,

    // Largest jar of mods/ or plugins/ (in MB) that is read to tell which mod a frame of a profile belongs to.
    'max_jar_size_mb' => (int) env('MPA_MAX_JAR_SIZE_MB', 64),

    // Whether the certificate of a bridge reached over https is checked. Bridges reached over http have none.
    'verify_tls' => (bool) env('MPA_BRIDGE_VERIFY_TLS', true),

    // Development only: answer every request with the examples in resources/fake-bridge instead of asking
    // the bridge, so the page can be looked at before a bridge runs on the node. true plays a bridge with
    // every feature, m1, m2 or m3 an older bridge (see FakeBridge).
    'fake_bridge' => env('MPA_FAKE_BRIDGE', false),

    // Development only, with fake_bridge: the state GET /ticks plays for the widget under the console,
    // empty for normal values, else overloaded, paused, starting, suspended, unsupported or jvm-starting.
    'fake_ticks' => env('MPA_FAKE_TICKS'),

    // Seconds between two readings of the live heap (GET /metrics?live=1, two attach commands, one with a
    // short safepoint) while the overview is open. 0 turns them off.
    'live_interval' => (int) env('MPA_LIVE_INTERVAL', 30),
];
