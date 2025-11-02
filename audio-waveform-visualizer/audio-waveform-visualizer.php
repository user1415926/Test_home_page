<?php
/**
 * Plugin Name: Audio Waveform Visualizer
 * Description: ????????? ??????? ??? ???????????? ???????? ?????? ? ??????? ????? ?????, ?????? ??????? ? ??? ???????????????.
 * Version: 1.0.0
 * Author: GPT-5 Codex
 * Text Domain: audio-waveform-visualizer
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Audio_Waveform_Visualizer {
    /**
     * Singleton instance.
     *
     * @var Audio_Waveform_Visualizer|null
     */
    private static $instance = null;

    /**
     * Plugin slug.
     */
    const SLUG = 'audio-waveform-visualizer';

    /**
     * Assets version.
     */
    const VERSION = '1.0.0';

    /**
     * Constructor.
     */
    private function __construct() {
        add_action('init', [$this, 'register_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    /**
     * Returns singleton instance.
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Registers plugin shortcode.
     */
    public function register_shortcode(): void {
        add_shortcode('audio_waveform', [$this, 'render_shortcode']);
    }

    /**
     * Registers scripts and styles.
     *
     * They will be enqueued later on demand via `render_shortcode`.
     */
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
                    'selectAudio'   => __('??????? ?????', 'audio-waveform-visualizer'),
                    'changeAudio'   => __('???????? ?????', 'audio-waveform-visualizer'),
                    'play'          => __('?????????????', 'audio-waveform-visualizer'),
                    'pause'         => __('?????', 'audio-waveform-visualizer'),
                    'playRegion'    => __('????????????? ?????????', 'audio-waveform-visualizer'),
                    'stop'          => __('??????????', 'audio-waveform-visualizer'),
                    'clearRegion'   => __('???????? ?????????', 'audio-waveform-visualizer'),
                    'loading'       => __('?????????', 'audio-waveform-visualizer'),
                    'noRegion'      => __('??????? ???????? ??????? ?? ?????.', 'audio-waveform-visualizer'),
                    'instructions'  => __('?????????? ???????? ?? ?????, ????? ???????? ???????.', 'audio-waveform-visualizer'),
                ],
            ]
        );
    }

    /**
     * Enqueues assets required for the shortcode output.
     */
    private function enqueue_assets(): void {
        wp_enqueue_style(self::SLUG);
        wp_enqueue_script(self::SLUG);
        wp_enqueue_media();
    }

    /**
     * Shortcode renderer.
     */
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
        <div class="awv-player" data-instance="<?php echo esc_attr($instance_id); ?>" data-height="<?php echo esc_attr($height); ?>">
            <div class="awv-controls">
                <button type="button" class="button awv-button awv-select" aria-controls="<?php echo esc_attr($instance_id); ?>">
                    <?php esc_html_e('??????? ?????', 'audio-waveform-visualizer'); ?>
                </button>
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
            </div>
            <div class="awv-file-info" hidden></div>
            <div class="awv-wave-wrapper">
                <div class="awv-waveform" id="<?php echo esc_attr($instance_id); ?>"></div>
                <div class="awv-progress" role="status" aria-live="polite"></div>
            </div>
            <div class="awv-region-info" hidden>
                <span class="awv-region-label"></span>
            </div>
            <p class="awv-instructions">
                <?php esc_html_e('?????????? ???????? ?? ?????, ????? ???????? ???????.', 'audio-waveform-visualizer'); ?>
            </p>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}

Audio_Waveform_Visualizer::get_instance();
