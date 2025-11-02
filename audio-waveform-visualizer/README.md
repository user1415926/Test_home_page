# Audio Waveform Visualizer

WordPress plugin that renders up to eight waveform tracks, lets you mark regions, and build a text-based playback script with timing and speed controls.

## Features

- eight independent tracks powered by WaveSurfer.js regions;
- region selection with the mouse and instant playback of any highlighted fragment;
- scenario editor that stores start offsets and playback speeds per region;
- one-click scenario execution and manual controls for each command;
- responsive layout that supports multiple shortcodes on a single page.

## Installation

1. Copy the folder `audio-waveform-visualizer` into `wp-content/plugins/`.
2. Activate **Audio Waveform Visualizer** in the WordPress admin panel.

## Usage

Add the shortcode inside a post or page:

```
[audio_waveform]
```

You can optionally override the waveform height (in pixels):

```
[audio_waveform height="220"]
```

The shortcode renders two zones:

1. **Tracks (top)** - eight waveform slots.
   - Click **Select audio** to load a file from the media library.
   - Drag across the waveform to create a region, then use the per-track controls (play, stop, play region, clear, add command).
2. **Command panel (bottom)** - scenario builder.
   - Every command stores the track, region boundaries, playback speed, and start offset.
   - Commands can be played individually or removed.
   - The scenario toolbar runs, stops, or clears the full script.
   - The text area mirrors the command list so it can be copied or edited manually.

## Requirements

- WordPress 5.8 or newer with access to the media library (`wp_enqueue_media`).
- Internet access to load the CDN builds of `wavesurfer.js@7` and the `regions` plugin.
- Modern browsers with CSS Flexbox/Grid support.
