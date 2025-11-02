# Audio Waveform Visualizer

WordPress plugin that renders eight waveform tracks, highlights regions, and builds playback scenarios with per-segment speed and start offsets.

## Features

- eight WaveSurfer.js tracks, each with region selection and playback controls;
- audio selection via the WordPress media library or direct upload from the page;
- immediate playback of the full track or the highlighted region;
- command panel with start offsets and playback speed; commands can be run individually or removed;
- scenario controls (run/stop/clear) and a live script log for copy/share.

## Installation

1. Copy `audio-waveform-visualizer` to `wp-content/plugins/`.
2. Activate **Audio Waveform Visualizer** inside the WordPress admin panel.

## Usage

Insert the shortcode:

```
[audio_waveform]
```

Optional height override (pixels):

```
[audio_waveform height="220"]
```

The workspace contains two areas:

1. **Tracks (top)** - eight waveform widgets.
   - Click **Select audio** to pick a file from the media library.
   - Click **Upload file** to load a local audio file without opening the library.
   - Drag across the waveform to create a region and use the per-track controls.
2. **Command panel (bottom)** - review and manage the scenario.
   - Each command stores track, region, speed, and start offset.
   - Commands can be run individually or deleted.
   - Use the scenario buttons to play, stop, or clear the entire script.

## Requirements

- WordPress 5.8 or newer with `wp_enqueue_media` available on the page;
- network access to the CDN versions of `wavesurfer.js@7` and the `regions` plugin;
- modern browser with CSS Flexbox and Grid support.
