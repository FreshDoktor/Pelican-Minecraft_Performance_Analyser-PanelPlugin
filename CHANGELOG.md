# Changelog

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
