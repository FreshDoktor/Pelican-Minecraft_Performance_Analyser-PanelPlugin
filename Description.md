> **Heads up:** this plugin does nothing on its own. It needs the [MPA Bridge](https://github.com/FreshDoktor/Pelican-Minecraft_Performance_Analyser-WingPlugin) installed on every Wings node that runs Minecraft servers. One script, one question, done.

# Minecraft Performance Analyser

Server lagging and nobody knows why? This plugin looks under the hood of a Minecraft server, right in the panel. It shows how the server is doing, finds out which mod eats the tick time, and points out settings that hold the server back. No extra mods or plugins on the server, nothing to set up in the game, and the server barely notices it's being watched.

## Features

- **TPS and MSPT under the console**, with a 10-minute history next to CPU and memory
- **Lag analysis**: record the server for 10 to 60 seconds and see which mod costs how much, with a flamegraph to dig deeper
- **Memory per mod**: what fills the RAM, grouped by mod
- **Thread dump**: what every thread is doing right now, deadlocks included
- **Diagnosis**: hints like "Java too old for this Minecraft version" or "RAM limit too tight for Xmx"
- **Live heap and garbage collection** at a glance
- Works with **Vanilla, Paper, Fabric, Forge and NeoForge**, readable method names included
- **Subuser permissions**, activity log, English and German
