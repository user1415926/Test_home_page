<?php
/**
 * Plugin Name: Audio Waveform Visualizer
 * Description: Shortcode for eight audio tracks with waveform regions and playback scripting.
 * Version: 1.0.0
 * Author: GPT-5 Codex
 * Text Domain: audio-waveform-visualizer
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Audio_Waveform_Visualizer {
    const SLUG = 'audio-waveform-visualizer';
    const VERSION = '1.0.0';

    /** @var Audio_Waveform_Visualizer|null */
    private static $instance = null;

    private function __construct() {
        add_action('init', [$this, 'register_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function register_shortcode(): void {
        add_shortcode('audio_waveform', [$this, 'render_shortcode']);
    }

    public function register_assets(): void {
        $plugin_url = plugin_dir_url(__FILE__);

        wp_register_style(
            self::SLUG,
            $plugin_url . 'assets/css/audio-waveform.css',
            [],
            self::VERSION
        );

        wp_register_script(
            self::SLUG . '-wavesurfer',
            'https://unpkg.com/wavesurfer.js@6/dist/wavesurfer.min.js',
            [],
            '6.7.4',
            true
        );

        wp_register_script(
            self::SLUG . '-wavesurfer-regions',
            'https://unpkg.com/wavesurfer.js@6/dist/plugin/wavesurfer.regions.min.js',
            [self::SLUG . '-wavesurfer'],
            '6.7.4',
            true
        );

        wp_register_script(
            self::SLUG,
            $plugin_url . 'assets/js/audio-waveform.js',
            ['jquery', self::SLUG . '-wavesurfer-regions'],
            self::VERSION,
            true
        );

        wp_localize_script(
            self::SLUG,
            'AWVSettings',
            [
                'strings' => [
                    'selectAudio'            => __('Select audio', 'audio-waveform-visualizer'),
                    'uploadFile'            => __('Upload file', 'audio-waveform-visualizer'),
                    'changeAudio'           => __('Replace audio', 'audio-waveform-visualizer'),
                    'play'                  => __('Play', 'audio-waveform-visualizer'),
                    'pause'                 => __('Pause', 'audio-waveform-visualizer'),
                    'playRegion'            => __('Play region', 'audio-waveform-visualizer'),
                    'stop'                  => __('Stop', 'audio-waveform-visualizer'),
                    'clearRegion'           => __('Clear regions', 'audio-waveform-visualizer'),
                    'loading'               => __('Loading...', 'audio-waveform-visualizer'),
                    'noRegion'              => __('Create a region on the track first.', 'audio-waveform-visualizer'),
                    'instructions'          => __('Drag on the waveform to create a region.', 'audio-waveform-visualizer'),
                    'addCommand'            => __('Add command', 'audio-waveform-visualizer'),
                    'commandPanelTitle'     => __('Command panel', 'audio-waveform-visualizer'),
                    'commandPanelHelp'      => __('Select a region and add a command with start time and playback speed.', 'audio-waveform-visualizer'),
                    'commandTableTitle'     => __('Command', 'audio-waveform-visualizer'),
                    'commandTableTrack'     => __('Track', 'audio-waveform-visualizer'),
                    'commandTableSegment'   => __('Segment', 'audio-waveform-visualizer'),
                    'commandTableSpeed'     => __('Speed', 'audio-waveform-visualizer'),
                    'commandTableStart'     => __('Start (s)', 'audio-waveform-visualizer'),
                    'commandTableActions'   => __('Actions', 'audio-waveform-visualizer'),
                    'commandPlay'           => __('Play', 'audio-waveform-visualizer'),
                    'commandRemove'         => __('Remove', 'audio-waveform-visualizer'),
                    'scenarioPlay'          => __('Run scenario', 'audio-waveform-visualizer'),
                    'scenarioStop'          => __('Stop scenario', 'audio-waveform-visualizer'),
                    'scenarioClear'         => __('Clear scenario', 'audio-waveform-visualizer'),
                    'scenarioEmpty'         => __('No commands yet.', 'audio-waveform-visualizer'),
                    'promptStart'           => __('Enter start offset (seconds)', 'audio-waveform-visualizer'),
                    'promptSpeed'           => __('Enter playback speed (for example 1 or 0.75)', 'audio-waveform-visualizer'),
                    'commandTextHeader'     => __('Command script', 'audio-waveform-visualizer'),
                    'commandTextPlaceholder'=> __('Add commands to populate the script.', 'audio-waveform-visualizer'),
                    'noAudioLoaded'         => __('Load audio on this track first.', 'audio-waveform-visualizer'),
                    'commandAdded'          => __('Command added.', 'audio-waveform-visualizer'),
                    'commandRemoved'        => __('Command removed.', 'audio-waveform-visualizer'),
                    'commandFailed'         => __('Failed to add command.', 'audio-waveform-visualizer'),
                    'scenarioStarted'       => __('Scenario started.', 'audio-waveform-visualizer'),
                    'scenarioStopped'       => __('Scenario stopped.', 'audio-waveform-visualizer'),
                    'scenarioCompleted'     => __('Scenario completed.', 'audio-waveform-visualizer'),
                    'errorPlayback'         => __('Unable to play the segment.', 'audio-waveform-visualizer'),
                    'trackLabel'            => __('Track %d', 'audio-waveform-visualizer'),
                ],
            ]
        );
    }

    private function enqueue_assets(): void {
        wp_enqueue_style(self::SLUG);
        wp_enqueue_script(self::SLUG);
        wp_enqueue_media();
    }

    public function render_shortcode(array $atts = [], string $content = ''): string {
        $this->enqueue_assets();

        $atts = shortcode_atts(
            [
                'height' => 160,
            ],
            $atts,
            'audio_waveform'
        );

        $instance_id = 'awv-' . wp_generate_uuid4();
        $height = absint($atts['height']);

        ob_start();
        ?>
        <div class="awv-workspace" data-instance="<?php echo esc_attr($instance_id); ?>" data-height="<?php echo esc_attr($height); ?>">
            <div class="awv-tracks">
                <?php for ($i = 1; $i <= 8; $i++) :
                    $track_id = sprintf('%s-track-%d', $instance_id, $i);
                    ?>
                    <div class="awv-track" data-track="<?php echo esc_attr($i); ?>" data-wave-id="<?php echo esc_attr($track_id); ?>" data-height="<?php echo esc_attr($height); ?>">
                        <div class="awv-track-header">
                            <span class="awv-track-title">
                                <?php echo esc_html(sprintf(__('Track %d', 'audio-waveform-visualizer'), $i)); ?>
                            </span>
                            <div class="awv-track-buttons">
                                <button type="button" class="button awv-button awv-select" aria-controls="<?php echo esc_attr($track_id); ?>">
                                    <?php esc_html_e('Select audio', 'audio-waveform-visualizer'); ?>
                                </button>
                                <button type="button" class="button awv-button awv-upload">
                                    <?php esc_html_e('Upload file', 'audio-waveform-visualizer'); ?>
                                </button>
                                <input type="file" class="awv-file-input" accept="audio/*" hidden>
                            </div>
                        </div>
                        <div class="awv-track-controls">
                            <button type="button" class="button awv-button awv-play" disabled>
                                <?php esc_html_e('Play', 'audio-waveform-visualizer'); ?>
                            </button>
                            <button type="button" class="button awv-button awv-stop" disabled>
                                <?php esc_html_e('Stop', 'audio-waveform-visualizer'); ?>
                            </button>
                            <button type="button" class="button awv-button awv-play-region" disabled>
                                <?php esc_html_e('Play region', 'audio-waveform-visualizer'); ?>
                            </button>
                            <button type="button" class="button awv-button awv-clear-region" disabled>
                                <?php esc_html_e('Clear regions', 'audio-waveform-visualizer'); ?>
                            </button>
                            <button type="button" class="button awv-button awv-add-command" disabled>
                                <?php esc_html_e('Add command', 'audio-waveform-visualizer'); ?>
                            </button>
                        </div>
                        <div class="awv-track-tuning">
                            <label class="awv-tuning-item">
                                <span><?php esc_html_e('Zoom', 'audio-waveform-visualizer'); ?></span>
                                <input type="range" class="awv-zoom" min="40" max="600" step="10" value="150">
                            </label>
                            <label class="awv-tuning-item">
                                <span><?php esc_html_e('Speed', 'audio-waveform-visualizer'); ?></span>
                                <input type="number" class="awv-speed" min="0.25" max="3" step="0.05" value="1">
                            </label>
                            <button type="button" class="button awv-reset-speed"><?php esc_html_e('Reset speed', 'audio-waveform-visualizer'); ?></button>
                        </div>
                        <div class="awv-file-info" hidden></div>
                        <div class="awv-wave-wrapper">
                            <div class="awv-waveform" id="<?php echo esc_attr($track_id); ?>"></div>
                            <div class="awv-progress" role="status" aria-live="polite"></div>
                        </div>
                        <div class="awv-region-info" hidden>
                            <span class="awv-region-label"></span>
                        </div>
                        <p class="awv-instructions">
                            <?php esc_html_e('Drag on the waveform to create a region.', 'audio-waveform-visualizer'); ?>
                        </p>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="awv-command-panel">
                <div class="awv-command-header">
                    <h3 class="awv-command-title"><?php esc_html_e('Command panel', 'audio-waveform-visualizer'); ?></h3>
                    <div class="awv-command-actions">
                        <button type="button" class="button button-primary awv-start-scenario" disabled>
                            <?php esc_html_e('Run scenario', 'audio-waveform-visualizer'); ?>
                        </button>
                        <button type="button" class="button awv-stop-scenario" disabled>
                            <?php esc_html_e('Stop scenario', 'audio-waveform-visualizer'); ?>
                        </button>
                        <button type="button" class="button awv-clear-commands" disabled>
                            <?php esc_html_e('Clear scenario', 'audio-waveform-visualizer'); ?>
                        </button>
                    </div>
                </div>
                <p class="awv-command-instructions">
                    <?php esc_html_e('Select a region on any track, then add a command with start time and playback speed.', 'audio-waveform-visualizer'); ?>
                </p>
                <div class="awv-command-status" hidden></div>
                <div class="awv-command-table-wrapper">
                    <table class="awv-command-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Command', 'audio-waveform-visualizer'); ?></th>
                                <th><?php esc_html_e('Track', 'audio-waveform-visualizer'); ?></th>
                                <th><?php esc_html_e('Segment', 'audio-waveform-visualizer'); ?></th>
                                <th><?php esc_html_e('Speed', 'audio-waveform-visualizer'); ?></th>
                                <th><?php esc_html_e('Start (s)', 'audio-waveform-visualizer'); ?></th>
                                <th><?php esc_html_e('Actions', 'audio-waveform-visualizer'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="awv-command-body">
                            <tr class="awv-command-empty">
                                <td colspan="6"><?php esc_html_e('No commands yet.', 'audio-waveform-visualizer'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="awv-command-text">
                    <label for="<?php echo esc_attr($instance_id); ?>-command-text">
                        <?php esc_html_e('Command script', 'audio-waveform-visualizer'); ?>
                    </label>
                    <textarea id="<?php echo esc_attr($instance_id); ?>-command-text" class="awv-command-log" rows="6" readonly><?php esc_html_e('Add commands to populate the script.', 'audio-waveform-visualizer'); ?></textarea>
                </div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}

Audio_Waveform_Visualizer::get_instance();
