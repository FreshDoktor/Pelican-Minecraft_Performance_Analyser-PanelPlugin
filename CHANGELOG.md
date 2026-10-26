# Changelog

## 0.3.0 - 2026-10-26

### Changed

- TPS and MSPT under the console are a continuous line. With MPA Bridge 0.3.0 the ticks are counted all the time instead of only while the server falls behind, so the TPS are exact, also above 20 while the server catches up after a lag. MSPT is the time the Server thread works on a tick, waiting for a CPU (the container's CPU limit) and GC pauses included. The hint under TPS says how busy the Server thread is. Before, switching between estimating and counting left gaps of up to 30 seconds
- A paused server (nobody online) keeps its line in the chart, the heading still says it is paused

## 0.2.3 - 2026-10-26

### Fixed

- TPS and MSPT under the console no longer stay away for minutes after the bridge was restarted. When no bridge answered, the panel remembered that for 5 minutes, now for 30 seconds
- A single "unsupported" answer of a tick monitor that had just started no longer hides TPS and MSPT for a day. The monitor must have looked for at least a minute first (and the JVM must still be older than 10 minutes, as before)

## 0.2.2 - 2026-10-26

### Added

- The diagnosis warns when the server's Java is older than its Minecraft version needs (Java 16 from 1.17, 17 from 1.18, 21 from 1.20.5, 25 from 26.1), with the Java to pick in the Docker image. The overview shows the Minecraft version next to Java. It is taken from the start command (Forge and NeoForge from 1.17, server jars named after their version), else from the egg variables such as `MC_VERSION` unless they say `latest`, else from the folders the server installed (`libraries`, `versions`). Newer Java than an old Minecraft version was built for is not reported, patched loaders run it fine

## 0.2.1 - 2026-10-26

### Changed

- The flamegraph of the lag analysis stays smooth with large profiles, such as a big Forge modpack with stacks over 300 frames deep: drawing, filtering and hovering are about ten times faster. It now scrolls inside its own box instead of making the page several screens long, shows up to 400 levels (before 200, cut off without a word) and says how many deeper levels are hidden. Very deep graphs are drawn at a lower resolution, so browsers with a limit on the canvas size (Safari) still show them

## 0.2.0 - 2026-10-25

### Added

- TPS and MSPT under the console, below Pelican's CPU, memory and network charts: approximate ticks per second and milliseconds per tick with the history of the last 10 minutes, updated every 5 seconds while the console is open. Yellow below 18 TPS, red below 15. A paused server, a starting JVM and a running lag analysis show as such instead of numbers. Needs MPA Bridge 0.2.0 (tick monitor) on the node, and only shows for running Java servers the bridge may attach to, not for proxies

## 0.1.1 - 2026-10-25

### Changed

- The performance page only shows what can deliver data. The tabs _Lag analysis_, _Memory_ and _Threads_ are left out when the node has no MPA Bridge, when its bridge can't do that yet, or (lag analysis) when the server has no Server thread, such as a Velocity or BungeeCord proxy. They stay when there is a stored result to look at
- When the server is stopped, Java is still starting, or the startup command has `-XX:+DisableAttachMechanism` or `-Xrs`, the buttons for a lag analysis, a histogram and a thread dump are disabled up front with the reason, instead of failing after the click. They come back by themselves once the server is ready
- The overview no longer shows the live heap where it can't be read

## 0.1.0

- First development version: overview with live JVM values and diagnosis, lag analysis with the share of tick time per mod and a flamegraph, memory histogram per mod, thread dump
