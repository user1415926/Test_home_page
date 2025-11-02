<?php
/**
 * Plugin Name: Audio Waveform Visualizer
 * Description: ??????? ??? ??????????? ?????? ????????????, ????????? ???????? ? ?????????? ????????? ???????????????.
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
            'https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js',
            [],
            '7.7.6',
            true
        );

        wp_register_script(
            self::SLUG . '-wavesurfer-regions',
            'https://unpkg.com/wavesurfer.js@7/dist/plugins/regions.min.js',
            [self::SLUG . '-wavesurfer'],
            '7.7.6',
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
                    'selectAudio'            => __('??????? ?????', 'audio-waveform-visualizer'),
                    'uploadFile'            => __('????????? ????', 'audio-waveform-visualizer'),
                    'changeAudio'           => __('???????? ?????', 'audio-waveform-visualizer'),
                    'play'                  => __('?????????????', 'audio-waveform-visualizer'),
                    'pause'                 => __('?????', 'audio-waveform-visualizer'),
                    'playRegion'            => __('????????????? ?????????', 'audio-waveform-visualizer'),
                    'stop'                  => __('??????????', 'audio-waveform-visualizer'),
                    'clearRegion'           => __('???????? ?????????', 'audio-waveform-visualizer'),
                    'loading'               => __('????????...', 'audio-waveform-visualizer'),
                    'noRegion'              => __('??????? ???????? ??????? ?? ???????.', 'audio-waveform-visualizer'),
                    'instructions'          => __('????????? ???????? ?? ?????, ????? ???????? ?????????.', 'audio-waveform-visualizer'),
                    'addCommand'            => __('???????? ???????', 'audio-waveform-visualizer'),
                    'commandPanelTitle'     => __('?????? ??????', 'audio-waveform-visualizer'),
                    'commandPanelHelp'      => __('???????? ??????? ? ???????? ??????? ? ???????? ?????? ? ????????? ???????????????.', 'audio-waveform-visualizer'),
                    'commandTableTitle'     => __('???????', 'audio-waveform-visualizer'),
                    'commandTableTrack'     => __('???????', 'audio-waveform-visualizer'),
                    'commandTableSegment'   => __('???????', 'audio-waveform-visualizer'),
                    'commandTableSpeed'     => __('????????', 'audio-waveform-visualizer'),
                    'commandTableStart'     => __('?????, ?', 'audio-waveform-visualizer'),
                    'commandTableActions'   => __('????????', 'audio-waveform-visualizer'),
                    'commandPlay'           => __('?????????', 'audio-waveform-visualizer'),
                    'commandRemove'         => __('???????', 'audio-waveform-visualizer'),
                    'scenarioPlay'          => __('????????????? ????????', 'audio-waveform-visualizer'),
                    'scenarioStop'          => __('?????????? ????????', 'audio-waveform-visualizer'),
                    'scenarioClear'         => __('???????? ????????', 'audio-waveform-visualizer'),
                    'scenarioEmpty'         => __('?????? ?????? ????.', 'audio-waveform-visualizer'),
                    'promptStart'           => __('??????? ????? ??????? (? ????????)', 'audio-waveform-visualizer'),
                    'promptSpeed'           => __('??????? ???????? ??????????????? (????????, 1 ??? 0.75)', 'audio-waveform-visualizer'),
                    'commandTextHeader'     => __('????? ??????', 'audio-waveform-visualizer'),
                    'commandTextPlaceholder'=> __('???????? ???????, ????? ??????? ????????.', 'audio-waveform-visualizer'),
                    'noAudioLoaded'         => __('??????? ????????? ????? ??? ???? ???????.', 'audio-waveform-visualizer'),
                    'commandAdded'          => __('??????? ?????????.', 'audio-waveform-visualizer'),
                    'commandRemoved'        => __('??????? ???????.', 'audio-waveform-visualizer'),
                    'commandFailed'         => __('?? ??????? ???????? ???????.', 'audio-waveform-visualizer'),
                    'scenarioStarted'       => __('???????? ???????.', 'audio-waveform-visualizer'),
                    'scenarioStopped'       => __('???????? ??????????.', 'audio-waveform-visualizer'),
                    'scenarioCompleted'     => __('???????? ????????.', 'audio-waveform-visualizer'),
                    'errorPlayback'         => __('?? ??????? ????????????? ???????.', 'audio-waveform-visualizer'),
                    'trackLabel'            => __('??????? %d', 'audio-waveform-visualizer'),
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
                                <?php echo esc_html(sprintf(__('??????? %d', 'audio-waveform-visualizer'), $i)); ?>
                            </span>
                            <div class="awv-track-buttons">
                                <button type="button" class="button awv-button awv-select" aria-controls="<?php echo esc_attr($track_id); ?>">
                                    <?php esc_html_e('??????? ?????', 'audio-waveform-visualizer'); ?>
                                </button>
                                <button type="button" class="button awv-button awv-upload">
                                    <?php esc_html_e('????????? ????', 'audio-waveform-visualizer'); ?>
                                </button>
                                <input type="file" class="awv-file-input" accept="audio/*" hidden>
                            </div>
                        </div>
                        <div class="awv-track-controls">
                            <button type="button" class="button awv-button awv-play" disabled>
                                <?php esc_html_e('?????????????', 'audio-waveform-visualizer'); ?>
                            </button>
                            <button type="button" class="button awv-button awv-stop" disabled>
                                <?php esc_html_e('??????????', 'audio-waveform-visualizer'); ?>
                            </button>
                            <button type="button" class="button awv-button awv-play-region" disabled>
                                <?php esc_html_e('????????????? ?????????', 'audio-waveform-visualizer'); ?>
                            </button>
                            <button type="button" class="button awv-button awv-clear-region" disabled>
                                <?php esc_html_e('???????? ?????????', 'audio-waveform-visualizer'); ?>
                            </button>
                            <button type="button" class="button awv-button awv-add-command" disabled>
                                <?php esc_html_e('???????? ???????', 'audio-waveform-visualizer'); ?>
                            </button>
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
                            <?php esc_html_e('????????? ???????? ?? ?????, ????? ???????? ?????????.', 'audio-waveform-visualizer'); ?>
                        </p>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="awv-command-panel">
                <div class="awv-command-header">
                    <h3 class="awv-command-title"><?php esc_html_e('?????? ??????', 'audio-waveform-visualizer'); ?></h3>
                    <div class="awv-command-actions">
                        <button type="button" class="button button-primary awv-start-scenario" disabled>
                            <?php esc_html_e('????????????? ????????', 'audio-waveform-visualizer'); ?>
                        </button>
                        <button type="button" class="button awv-stop-scenario" disabled>
                            <?php esc_html_e('?????????? ????????', 'audio-waveform-visualizer'); ?>
                        </button>
                        <button type="button" class="button awv-clear-commands" disabled>
                            <?php esc_html_e('???????? ????????', 'audio-waveform-visualizer'); ?>
                        </button>
                    </div>
                </div>
                <p class="awv-command-instructions">
                    <?php esc_html_e('???????? ??????? ?? ???????, ????? ???????? ??????? ? ???????? ?????? ? ?????????.', 'audio-waveform-visualizer'); ?>
                </p>
                <div class="awv-command-status" hidden></div>
                <div class="awv-command-table-wrapper">
                    <table class="awv-command-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('???????', 'audio-waveform-visualizer'); ?></th>
                                <th><?php esc_html_e('???????', 'audio-waveform-visualizer'); ?></th>
                                <th><?php esc_html_e('???????', 'audio-waveform-visualizer'); ?></th>
                                <th><?php esc_html_e('????????', 'audio-waveform-visualizer'); ?></th>
                                <th><?php esc_html_e('?????, ?', 'audio-waveform-visualizer'); ?></th>
                                <th><?php esc_html_e('????????', 'audio-waveform-visualizer'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="awv-command-body">
                            <tr class="awv-command-empty">
                                <td colspan="6"><?php esc_html_e('?????? ?????? ????.', 'audio-waveform-visualizer'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="awv-command-text">
                    <label for="<?php echo esc_attr($instance_id); ?>-command-text">
                        <?php esc_html_e('????? ??????', 'audio-waveform-visualizer'); ?>
                    </label>
                    <textarea id="<?php echo esc_attr($instance_id); ?>-command-text" class="awv-command-log" rows="6" readonly><?php esc_html_e('???????? ???????, ????? ??????? ????????.', 'audio-waveform-visualizer'); ?></textarea>
                </div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}

Audio_Waveform_Visualizer::get_instance();
