# AKAI MPC1000 Sampler Sequencer for WordPress

## Overview

This plugin brings a performance-oriented sampler and step sequencer inspired by the AKAI MPC1000 into WordPress. It offers a 4x4 bank pad surface, deep sample shaping tools, a 64-track step sequencer, and song chaining so you can craft beats directly from the WordPress dashboard or embed interactive grooves on public pages.

## Core Features

- **Four pad banks (A-D) with velocity-sensitive playback simulation** using the Web Audio API.
- **Per-pad sample editor** with start/end trimming, gain, pan, tuning, ADSR envelope, and choke groups.
- **Sample capture pipeline** that covers drag-and-drop uploads and direct microphone sampling (10s per take by default).
- **Precision sequencer** supporting up to 8 bars per sequence, swing, real-time recording, and per-track program tagging.
- **Song mode** to chain sequences with repeat counts for live sets.
- **Project management suite** with custom post type storage, REST API CRUD, and admin launcher.
- **Frontend shortcode** (`[akai_mpc1000]`) that renders the full MPC experience on any page or post.

## Installation

1. Copy the `akai-mpc1000-sampler-sequencer` folder into your WordPress instance under `wp-content/plugins/`.
2. Log into the WordPress admin dashboard and activate **AKAI MPC1000 Sampler Sequencer**.
3. Access the control-center from the new **AKAI MPC1000** menu entry.

## Usage

### Creating Projects

1. Open **AKAI MPC1000** in the dashboard and click **New Project**.
2. Load or record samples for pads via the pad editor.
3. Lay down your beat by either recording in real time (enable *Rec* and tap pads) or toggling steps within the sequencer grid.
4. Use **Song Mode** to chain sequences for full arrangements.
5. Save your work with **Save Project** (stored in the `akai_mpc_project` custom post type).

### Embedding on Pages

Use the shortcode anywhere in your content:

```
[akai_mpc1000 project_id="123"]
```

Parameters:

- `project_id` *(optional)*: An existing MPC project ID. If omitted, a fresh default rig loads.
- `mode` *(optional)*: Future hint for performance vs. editing contexts.

### Keyboard Shortcuts (Desktop)

- `Space`: Play/stop transport.
- `Shift + Space`: Toggle record (when transport is stopped).
- `Arrow Keys`: Navigate pads.
- `Enter`: Trigger selected pad.

## REST API

All endpoints live under `wp-json/akai-mpc/v1/` and require an `X-WP-Nonce` header.

- `GET /projects` - list up to 100 MPC projects ordered by last modification time.
- `POST /projects` - create a project. Body: `{ "title": "My Beat", "project_state": {...}, "performance_notes": "optional" }`
- `GET /projects/{id}` - retrieve a single project with full state payload.
- `POST /projects/{id}` - update title, state, and/or performance notes.

## Data Model

- **Custom Post Type:** `akai_mpc_project`
- **Meta:**
  - `_akai_mpc_state` (JSON string) - persistent snapshot of the project (banks, sequences, song, global settings)
  - `_akai_mpc_performance` (string) - freeform notes for live use

## Limitations & Roadmap Ideas

- Browser-based timing may drift; for tight sync consider routing transport to a DAW via MIDI (future enhancement).
- Projects store embedded data URLs for samples. Large libraries should rely on WordPress media items and adapt `setPadSample` accordingly.
- No native MIDI out or pad velocity from hardware controllers yet.
- Step editor currently captures fixed velocity hits (1.0); velocity lanes are a planned upgrade.

## Development

- **Main bootstrap:** `akai-mpc1000-sampler-sequencer.php`
- **PHP core:** `includes/class-akai-mpc1000.php`
- **Frontend app:** `assets/js/akai-mpc1000.js`
- **Styles:** `assets/css/akai-mpc1000.css`

Run the following if you need to rebuild asset timestamps after edits:

```
touch assets/js/akai-mpc1000.js assets/css/akai-mpc1000.css
```

## License

GPLv2 or later.
