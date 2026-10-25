<?php

return [
    'plugin_name' => 'Minecraft Performance Analyser',

    'navigation_label' => 'Performance',
    'title' => 'Performance',

    'tabs' => [
        'overview' => 'Overview',
        'lag' => 'Lag analysis',
        'memory' => 'Memory',
        'threads' => 'Threads',
    ],

    // Tabs that come with a later version.
    'later' => [
        'lag' => 'The lag analysis comes with a later version: a profile of 10, 30 or 60 seconds, the share of the tick time per mod, the top methods and a flamegraph.',
        'memory' => 'The memory tab comes with a later version: a class histogram grouped by mod, down to the class.',
        'threads' => 'The threads tab comes with a later version: a thread dump with a filter by state, deadlocks and the CPU time per thread.',
    ],

    // Shown by Pelican in the subuser form, keys are <group>_title, <group>_desc and <group>_<permission>.
    'permissions' => [
        'performance_title' => 'Performance',
        'performance_desc' => 'Permissions for the performance page (Minecraft Performance Analyser).',
        'performance_read' => 'Allows to open the performance page and to see the overview, the diagnosis and past lag analyses.',
        'performance_profile' => 'Allows to start a lag analysis (profile).',
        'performance_memory' => 'Allows to create a memory histogram, which pauses the server for a moment.',
        'performance_threads' => 'Allows to create a thread dump.',
    ],

    // Tab on the edit page of a node in the admin area.
    'node' => [
        'tab' => 'MPA Bridge',
        'description' => 'The MPA Bridge runs on the node and gives the performance page of a Minecraft server its data. The panel always talks to it itself, with the token of the node, never the browser.',
        'url' => 'Bridge address',
        'url_helper' => 'Address the panel reaches the bridge under, e.g. http://10.0.0.5:8090 in the internal network when a proxy in front of the node only forwards the Wings port. Empty uses the FQDN of the node with port 8090. The certificate is only checked for https addresses.',
        'test' => 'Test connection',
        'test_ok' => 'The bridge answers',
        'test_ok_body' => 'MPA Bridge :version under :url, it takes the token of this node. Save the node to keep the address.',
        'test_failed' => 'The bridge does not answer',
    ],

    'errors' => [
        'unreachable' => 'The MPA Bridge of the node can\'t be reached under :url. Is it installed and running, and does the firewall let the panel through?',
        'unauthorized' => 'The MPA Bridge refused the token of the node. It reads the token from the Wings config, restart the bridge after the token changed.',
        'wrong_node' => 'Under :url answers something else than the MPA Bridge of this node. Check the bridge address of the node in the admin area.',
        'invalid_response' => 'The MPA Bridge sent an answer the plugin doesn\'t understand. Do the versions of plugin and bridge go together?',
        'bridge_error' => 'The MPA Bridge failed (HTTP :status), see its log on the node (journalctl -u mpa-bridge).',
        'unexpected' => 'Something went wrong, see the log of the panel.',

        // Error codes of the bridge, see docs/API.md of the bridge. Unknown codes show the message of the bridge.
        'bridge' => [
            'server_not_running' => 'The server is not running.',
            'no_java_process' => 'No Java process found in the server\'s container.',
            'profile_running' => 'A lag analysis is running already for this server.',
        ],
    ],
];
