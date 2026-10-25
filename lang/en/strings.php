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

    'overview' => [
        'loading' => 'Asking the MPA Bridge…',
        'retry' => 'Try again',
        'error' => 'The MPA Bridge failed',
        'span' => 'last :span',
        'fake' => [
            'title' => 'Fake mode',
            'body' => 'MPA_FAKE_BRIDGE is on: the values are the example answers of the API contract, not this server\'s. For development only.',
        ],
        'bridge_missing' => [
            'title' => 'No MPA Bridge found on this node',
            'intro' => 'The performance data comes from the MPA Bridge, a small service on the node. To set it up:',
            'step_install' => 'Install the bridge on the node as described in its <a href=":url" target="_blank" rel="noopener" class="underline">README</a>. It runs as root and uses the token of Wings, no second secret is needed.',
            'step_firewall' => 'Let the panel reach port 8090 of the node, and only the panel: put its IP into allowed_ips in /etc/pelican/mpa-bridge.yml.',
            'step_address' => 'The panel asks the bridge under :url. If it is reachable under another address (e.g. in the internal network because a proxy only forwards the Wings port), an admin sets it on the node in the admin area, tab MPA Bridge.',
            'step_retry' => 'Then try again.',
            'node_settings' => 'Bridge address of the node',
        ],
        'not_ready' => [
            'server_not_running' => [
                'title' => 'The server is not running',
                'body' => 'Start it to see its JVM. This tab looks again every few seconds.',
            ],
            'no_java_process' => [
                'title' => 'No Java process yet',
                'body' => 'The server\'s container runs, but Java isn\'t up (yet). This tab looks again every few seconds.',
            ],
            'hsperf_unavailable' => [
                'title' => 'The JVM shares no counters',
                'body' => 'Java runs, but has no readable performance counters (hsperfdata). Either it is just starting, or the startup command has -XX:-UsePerfData or -XX:+PerfDisableSharedMem: remove those.',
            ],
        ],
        'no_findings' => 'No problems found',
        'no_findings_body' => 'The share of time spent in GC is judged once this tab has been open for a minute.',
        'vm_failed' => 'The JVM details could not be read',
        'heap' => [
            'title' => 'Heap',
            'after_gc' => 'After GC: the JVM updates these values only when it collects garbage, in between they stay as they were.',
            'young' => 'Young (after GC)',
            'old' => 'Old (after GC)',
            'max' => 'Max',
        ],
        'gc' => [
            'title' => 'GC time per minute',
            'subtitle' => 'Time the server was stopped for garbage collection, per minute.',
            'now' => 'Now: :value per minute',
            'share' => 'Share of the time: :share',
            'top' => 'Top of the chart: :value',
            'waiting' => 'Shown after the second reading.',
        ],
        'jvm' => 'JVM',
        'java' => 'Java',
        'gc_label' => 'Garbage collector',
        'gc_types' => [
            'serial' => 'Serial',
            'parallel' => 'Parallel',
            'g1' => 'G1',
            'zgc' => 'ZGC',
            'shenandoah' => 'Shenandoah',
            'unknown' => 'Unknown',
        ],
        'gc_selected_by' => [
            'flag' => 'set in the startup command',
            'ergonomics' => 'chosen by the JVM',
        ],
        'heap_max' => 'Max heap (Xmx)',
        'no_xmx' => 'no -Xmx set',
        'memory_limit' => 'Memory limit of the container',
        'unlimited' => 'Unlimited',
        'heap_of_limit' => 'heap is :percent of it',
        'cpus' => 'CPUs',
        'host_cpus' => 'of :count on the node',
        'uptime' => 'Uptime',
        'threads' => 'Threads',
        'threads_hint' => ':daemon daemon, at most :peak',
        'metaspace' => 'Metaspace',
        'classes' => ':count classes loaded',
        'vm_args' => 'JVM arguments',
    ],

    // Rules of Services\Diagnostics. Each has a title and one or more bodies.
    'diagnostics' => [
        'serial_gc' => [
            'title' => 'The server uses the Serial GC',
            'body_cpu' => 'The JVM picked the Serial GC itself because it only sees :cpus CPU (the CPU limit of the server). Serial collects garbage with one thread and stops the server for it, which shows as lag spikes. Give the server at least 2 CPUs (200 %) or set -XX:+UseG1GC in the startup command.',
            'body_memory' => 'The JVM picked the Serial GC itself because the server has only :memory of memory. Serial collects garbage with one thread and stops the server for it, which shows as lag spikes. Set -XX:+UseG1GC in the startup command or give the server more memory.',
            'body_cpu_memory' => 'The JVM picked the Serial GC itself because it only sees :cpus CPU and :memory of memory. Serial collects garbage with one thread and stops the server for it, which shows as lag spikes. Give the server at least 2 CPUs (200 %) or set -XX:+UseG1GC in the startup command.',
            'body_unknown' => 'Serial collects garbage with one thread and stops the server for it, which shows as lag spikes. Set -XX:+UseG1GC in the startup command.',
            'body_flag' => 'The startup command selects the Serial GC (-XX:+UseSerialGC). It collects garbage with one thread and stops the server for it. Use -XX:+UseG1GC instead.',
        ],
        'no_xmx' => [
            'title' => 'No maximum heap set',
            'body' => 'Neither -Xmx nor -XX:MaxRAMPercentage is in the startup command, so the JVM only takes :percent % of the container\'s memory as heap (:heap of :limit). Set -Xmx.',
            'body_unlimited' => 'Neither -Xmx nor -XX:MaxRAMPercentage is in the startup command, so the JVM takes :percent % of the node\'s memory as heap (:heap). Set -Xmx.',
        ],
        'xmx_near_limit' => [
            'title' => 'Xmx is too close to the memory limit',
            'body' => 'Heap :xmx + metaspace :metaspace + about :overhead for code cache, thread stacks and network buffers come to :total, more than the container\'s limit of :limit. Once the heap is used up, the container gets killed for running out of memory instead of Java collecting garbage. Lower -Xmx to about :suggested, or give the server more memory.',
        ],
        'high_gc_share' => [
            'title' => 'Garbage collection takes :percent of the time',
            'body' => 'In the last :minutes minutes the server was stopped :percent of the time for garbage collection, more than :limit. Give it more memory, or look for what fills it (tab Memory).',
        ],
        'old_gen_full' => [
            'title' => 'The old generation stays full',
            'body' => 'Even after the last garbage collection the old generation holds :used of at most :max (:percent). That points to a memory leak or a heap that is too small.',
        ],
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
        'test_ok_body' => 'MPA Bridge :version under :url takes the token of this node. Features: :features. Save the node to keep the address.',
        'test_api_mismatch' => 'The bridge speaks another API version',
        'test_api_mismatch_body' => 'MPA Bridge :version under :url speaks API version :api, this plugin needs version :expected. Update the plugin or the bridge.',
        'test_failed' => 'The bridge does not answer',
    ],

    'errors' => [
        'unreachable' => 'The MPA Bridge of the node can\'t be reached under :url. Is it installed and running, and does the firewall let the panel through?',
        'wrong_node' => 'Under :url answers something else than the MPA Bridge of this node. Check the bridge address of the node in the admin area.',
        'invalid_response' => 'The MPA Bridge sent an answer the plugin doesn\'t understand. Do the versions of plugin and bridge go together?',
        'bridge_error' => 'The MPA Bridge failed (HTTP :status), see its log on the node (journalctl -u mpa-bridge).',
        'unexpected' => 'Something went wrong, see the log of the panel.',

        // Error codes of the bridge (docs/API.md, "Errors"). The details of an error fill the placeholders.
        // Unknown codes show the English message of the bridge.
        'bridge' => [
            'invalid_request' => 'The bridge refused the request: :field :reason.',
            'unauthorized' => 'The MPA Bridge refused the token of the node. It reads the token from the Wings config, so both have to be on the same node, and the panel\'s token has to match it.',
            'ip_not_allowed' => 'The MPA Bridge doesn\'t accept requests from the panel\'s IP. Add it to allowed_ips in /etc/pelican/mpa-bridge.yml on the node.',
            'not_found' => 'The MPA Bridge doesn\'t know this request. Is it older than the plugin?',
            'method_not_allowed' => 'The MPA Bridge doesn\'t know this request. Is it older than the plugin?',
            'server_not_found' => 'The MPA Bridge finds no container of this server on the node.',
            'server_not_running' => 'The server is not running.',
            'no_java_process' => 'No Java process runs in the server\'s container.',
            'hsperf_unavailable' => 'Java runs, but has no readable performance counters (hsperfdata): it is just starting, or -XX:-UsePerfData or -XX:+PerfDisableSharedMem is set.',
            'attach_unavailable' => 'The bridge can\'t attach to the JVM of this server.',
            'attach_failed' => 'The JVM refused the command of the bridge.',
            'attach_timeout' => 'The JVM didn\'t answer the bridge in time.',
            'output_too_large' => 'The answer of the JVM is larger than the bridge allows.',
            'profile_running' => 'A lag analysis is running already for this server.',
            'too_many_profiles' => 'Too many lag analyses run on this node right now (at most :limit), try again in a moment.',
            'profile_not_found' => 'This lag analysis is no longer known to the bridge, it keeps results for 15 minutes.',
            'insufficient_tmp_space' => 'The /tmp of the server\'s container is too full for the profiler (:free_bytes free, :required_bytes needed).',
            'not_implemented' => 'This MPA Bridge can\'t do that yet, update it.',
            'docker_unavailable' => 'The MPA Bridge can\'t reach Docker on the node.',
            'internal_error' => 'The MPA Bridge failed, see its log on the node (journalctl -u mpa-bridge).',
        ],

        // details.reason of attach_unavailable.
        'attach_reasons' => [
            'disabled' => 'The startup command has -XX:+DisableAttachMechanism.',
            'xrs' => 'The startup command has -Xrs: starting the attach listener would stop the server.',
            'jvm_starting' => 'The JVM is just starting, try again in half a minute.',
            'unknown' => 'Whether it is safe can\'t be checked for this JVM.',
        ],
    ],
];
