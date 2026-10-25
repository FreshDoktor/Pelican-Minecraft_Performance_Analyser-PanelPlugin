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

    // Whether the certificate of a bridge reached over https is checked. Bridges reached over http have none.
    'verify_tls' => (bool) env('MPA_BRIDGE_VERIFY_TLS', true),

    // Development only: answer every request with the examples in resources/fake-bridge instead of asking
    // the bridge, so the page can be looked at before a bridge runs on the node.
    'fake_bridge' => (bool) env('MPA_FAKE_BRIDGE', false),
];
