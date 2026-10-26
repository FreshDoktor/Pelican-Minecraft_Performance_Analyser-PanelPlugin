# Minecraft Performance Analyser

A plugin for the [Pelican Panel](https://pelican.dev) that looks into the JVM of a Minecraft server: heap, garbage collection, threads, lag analysis, memory **per mod**, and TPS and MSPT under the console. The data comes from the [MPA Bridge](https://github.com/FreshDoktor/Pelican-Minecraft_Performance_Analyser-WingPlugin), a small service on the node, as a panel plugin can't reach the Java process itself.

## Features

- **Overview**: live heap (young and old generation) and GC time per minute, the JVM at a glance (Java and Minecraft version, GC, Xmx compared to the RAM limit of the container, uptime, threads) and a diagnosis with hints on what to change
- **Lag analysis**: a profile of 10, 30 or 60 seconds, the share of the tick time per mod, the top methods with readable names and a flamegraph
- **Memory**: a class histogram grouped by mod, down to the class
- **Threads**: a thread dump with a filter by state, deadlocks and the CPU time per thread
- **TPS and MSPT under the console**: ticks per second and time per tick with a history of 10 minutes, below Pelican's CPU and memory charts
- **Light on the server**: the bridge reads the JVM's own counters from the host and starts no process in the container
- **Only what works**: a tab, chart or button only shows up where the server can deliver its data; otherwise it is hidden, or greyed out with the reason
- **Subuser permissions** and entries in the server's **activity log**
- **Multilingual**: English and German

## Requirements

- Pelican Panel with plugin support
- The [MPA Bridge](https://github.com/FreshDoktor/Pelican-Minecraft_Performance_Analyser-WingPlugin) on every node with Minecraft servers. Without it the page tells how to install it
- A Java Edition server or proxy: the egg needs the `minecraft` tag (not `bedrock`), or `performance_analyser` in its features
- A running queue worker (`php artisan queue:work`, which every Pelican installation needs anyway): the jars of the server are read in the background to tell which mod a frame or a class belongs to, memory histograms are created there, and the names for the lag analysis are downloaded there

## Installation

1. **Bridge:** install the [MPA Bridge](https://github.com/FreshDoktor/Pelican-Minecraft_Performance_Analyser-WingPlugin#installation) on each node, as root:

   ```bash
   curl -fsSLO https://github.com/FreshDoktor/Pelican-Minecraft_Performance_Analyser-WingPlugin/releases/latest/download/install.sh
   bash install.sh
   ```

   It asks for the IP of your panel, the only address allowed to connect.

2. **Plugin:** download `minecraft-performance-analyser.zip` from the [latest release](https://github.com/FreshDoktor/Pelican-Minecraft_Performance_Analyser-PanelPlugin/releases/latest) and import it in the admin area under _Plugins_, then install and enable it there. Or clone the repository into the `plugins` folder of your panel, named like the plugin id:

   ```bash
   cd /var/www/pelican/plugins
   git clone https://github.com/FreshDoktor/Pelican-Minecraft_Performance_Analyser-PanelPlugin.git minecraft-performance-analyser
   ```

   Installing creates the table `minecraft_performance_analyser_bridges`.

3. **Restart the queue worker**, it only knows the code it was started with: `php artisan queue:restart` in the panel folder. Do this after every update of the plugin too.

4. **Bridge address:** the panel talks to the bridge itself, never the browser, with the token of the node (the one Wings uses). By default it reaches the bridge under the FQDN of the node with port 8090, e.g. `https://node.example.com:8090`. If that doesn't work, e.g. because a proxy in front of the node only forwards the Wings port, set the address in the admin area under _Nodes_ → the node → tab _MPA Bridge_, e.g. `http://10.0.0.5:8090` in the internal network. _Test connection_ checks that the bridge answers, is the bridge of this node and takes its token. The certificate is only checked for `https` addresses.

Then open a Minecraft server: the page _Performance_ is in its navigation, TPS and MSPT appear under the console.

### Update

Import the zip of the new release (or `git pull` in the plugin folder), then `php artisan queue:restart`. Update the bridge with `bash install.sh` on the node. Plugin and bridge talk over a versioned API: a newer plugin works with an older bridge, it just leaves out what the bridge can't do yet.

## Lag analysis

_Start_ records the `Server thread` for 10, 30 or 60 seconds with async-profiler on the node (mode `wall`: every 50 ms, whether it works or waits). The result:

- **Tick load**: share of the time the Server thread worked on ticks. Samples in the waiting between two ticks don't count, unless a tick is above them (waiting for a chunk inside a tick is lag). Close to 100 % the server can't keep 20 TPS. This works with Mojang's names (Vanilla from 26.1, Paper from 1.20.5, current NeoForge and Forge) as well as Fabric's intermediary and Forge's SRG (1.17 to 1.20) and MCP names (up to 1.16). On a server without them (obfuscated Vanilla before 26.1, Spigot and Paper before 1.20.5) everything counts as tick work, and a hint says so
- **Share per mod**, of the tick work only. _Self_ is the time in the mod's own code (time in Java's own classes counts for their caller, waiting inside a tick for the deepest mod in the stack), _inclusive_ the time with the mod anywhere in the call stack
- **Top methods**, a **flamegraph** (click a mod in the table to filter it), the **GC pauses** during the profile and the **raw profile** to download
- A server that only waited (nobody online, or paused with `pause-when-empty-seconds`) is reported as such

**Readable names:** Fabric and Forge servers run with ids instead of names (`class_3218.method_18765`, `m_8793_`, `func_72835_b`). The first time a profile needs them, the panel downloads the mappings of the server's Minecraft version on the queue (Fabric's intermediary with Mojang's server mappings, Forge 1.17 to 1.20 MCPConfig with Mojang's server mappings, Forge up to 1.16 MCP's names) from Mojang, maven.fabricmc.net and maven.minecraftforge.net, and keeps only the names on its local disk, once per Minecraft version for all servers. The top methods and the flamegraph then show `ServerLevel.tick`; hovering a method shows the name the server runs it as. Turn it off with `MPA_READABLE_NAMES=false` on panels without internet access.

**Mods:** frames are mapped to mods by the Java packages of the jars in `mods/` and `plugins/`, read in the background and cached per jar (size and modification time). Fixed: `net/minecraft` and `com/mojang` are Minecraft; `net/fabricmc`, `org/quiltmc`, `net/neoforged`, `net/minecraftforge`, `cpw/mods`, `org/spongepowered/asm`, `io/papermc`, `com/destroystokyo/paper`, `org/bukkit`, `org/spigotmc` and `com/velocitypowered` the loader/server; `java`, `javax`, `jdk`, `sun` and `com/sun` the JVM. Mixin handlers in Minecraft's classes (`handler$zza000$<modid>$…`) count for the mod that injects them. Jars inside other jars (jar-in-jar) are not read, their classes show as _Other_ or _Libraries_.

The newest 5 lag analyses of a server are kept on the panel under `storage/app/private/minecraft-performance-analyser/profiles/<server uuid>/`, the bridge forgets them after 15 minutes. The last histogram and thread dump are in `…/snapshots/<server uuid>/`. All of them are deleted with the server.

## TPS and MSPT under the console

Below Pelican's CPU, memory and network charts the console shows two more: **TPS** and **MSPT** (milliseconds per tick), with the history of the last 10 minutes, updated every 5 seconds while the console is open.

- The bridge's tick monitor starts with the first look at the console and stops a minute after it was closed. It counts the ticks of the Server thread, so the TPS are exact, also above 20 while the server catches up after a lag.
- MSPT is the time the Server thread works on a tick, waiting for a CPU (the container's CPU limit) and GC pauses included. The hint under TPS says how busy the Server thread is.
- Below 18 TPS the values turn yellow, below 15 red. A server with hardly anything to do (nobody online, or paused) is shown as _idle_.
- While a lag analysis runs the monitor pauses; the gap in the chart is bridged by a dashed line over a hatched area.
- Only shown where values are possible: a bridge with the tick monitor on the node, a running server the bridge may attach to (not with `-XX:+DisableAttachMechanism` or `-Xrs`), not a proxy, and the permission `performance.read`. A server that runs for 10 minutes without a tick loop the bridge knows (an obfuscated Minecraft version) doesn't get them again until it restarts. Whether a node has a bridge with the tick monitor is kept for 5 minutes (30 seconds when no bridge answered); saving the bridge address of the node asks again.

## Memory

_Create histogram_ asks the JVM for a class histogram (`GC.class_histogram -all`). The JVM walks the whole heap for it and **the server stands still meanwhile**, usually under a second; the tab warns and asks first. The histogram is created on the queue, the bridge allows it up to 90 s, the panel waits up to 80 s.

The classes are grouped by mod like the frames of a lag analysis, arrays by their element type (`[Lnet.minecraft…StateHolder;` counts for Minecraft, `[B` for the JVM). Each group opens down to its classes. The bridge lists the 2000 largest classes, the rest only counts in the total. Unreachable objects count too (`-all`). The last histogram per server is kept on the panel and shown again after a reload.

## Threads

_Create thread dump_ shows what every thread is doing (`Thread.print -l`, a very short stop): sorted by CPU time with the share of its lifetime, filtered by state, the mod a thread works for, which thread holds the lock it waits for, deadlocks first and highlighted, each stack to open, and the raw text to copy. The last dump per server is kept on the panel.

## Diagnosis

The overview checks the JVM against these rules:

| Rule | When |
| --- | --- |
| Java too old | The server's Java is older than its Minecraft version needs (16 from 1.17, 17 from 1.18, 21 from 1.20.5, 25 from 26.1). The Minecraft version comes from the start command, the egg variables (`MC_VERSION` and the like) or the folders the server installed |
| Xmx too close to the limit | Max heap + metaspace + about 300 MB (code cache, thread stacks, network buffers) is more than the memory limit of the container: once the heap is used up, the container gets killed instead of Java collecting garbage |
| Old generation stays full | After a GC the old generation holds more than 90 % of its maximum |
| High GC share | More than 5 % of the time spent in GC pauses while the page is open (judged after a minute) |
| Serial GC | The JVM uses the Serial GC, with the reason: it sees fewer than 2 CPUs or less than 1792 MB, or the startup command sets it |
| No Xmx | Neither `-Xmx` nor `-XX:MaxRAMPercentage` is set, the JVM takes only 25 % of the container's memory |

The heap values are the state after the last garbage collection, which is what the JVM shares without being stopped; the charts say so.

## Permissions

| Subuser permission | Allows |
| --- | --- |
| `performance.read` | Opening the page, the overview, the diagnosis, past lag analyses, TPS and MSPT under the console |
| `performance.profile` | Starting a lag analysis |
| `performance.memory` | Creating a memory histogram, which pauses the server for a moment |
| `performance.threads` | Creating a thread dump |

The owner of a server and admins who may change it have all of them.

## Configuration

Optional, in the `.env` of the panel (run `php artisan config:clear` after a change):

| Env | Default | Description |
| --- | --- | --- |
| `MPA_BRIDGE_PORT` | `8090` | Port of the bridge for nodes without an address of their own |
| `MPA_BRIDGE_VERIFY_TLS` | `true` | Whether the certificate of a bridge reached over `https` is checked |
| `MPA_POLL_INTERVAL` | `3` | Seconds between two updates of the live values on the overview (2 to 5) |
| `MPA_LIVE_INTERVAL` | `30` | Seconds between two readings of the live heap on the overview (two attach commands, one with a short safepoint); `0` turns them off |
| `MPA_MAX_JAR_SIZE_MB` | `64` | Largest jar of `mods/` or `plugins/` that is read for the share per mod |
| `MPA_READABLE_NAMES` | `true` | Download mappings for readable method names in the lag analysis |

## Troubleshooting

- **"No MPA Bridge found on this node"**: the bridge isn't installed, not running (`systemctl status mpa-bridge` on the node), or the panel can't reach its port. Set the address under _Nodes_ → tab _MPA Bridge_ and use _Test connection_, it names the problem
- **A tab or button is greyed out**: the reason is next to it, e.g. the server is stopped, has just started, or its start arguments contain `-XX:+DisableAttachMechanism`
- **No TPS and MSPT under the console**: see the conditions in [TPS and MSPT under the console](#tps-and-mspt-under-the-console). A proxy (Velocity, BungeeCord) has no ticks
- **The share per mod is all _Other_, histograms never finish, names stay ids**: the queue worker doesn't run or runs old code, `php artisan queue:restart`

## License

Copyright (C) 2026 FreshDoktor. Licensed under the [GNU General Public License v3.0](LICENSE).
