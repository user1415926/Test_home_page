<?php
/**
 * Plugin Name:       AKAI MPC1000 Sampler Sequencer
 * Plugin URI:        https://example.com/plugins/akai-mpc1000-sampler-sequencer
 * Description:       An advanced sampler and step sequencer that emulates the iconic AKAI MPC1000 workflow inside WordPress.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            GPT-5 Codex
 * Author URI:        https://openai.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       akai-mpc1000
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKAI_MPC1000_VERSION' ) ) {
	define( 'AKAI_MPC1000_VERSION', '0.1.0' );
}

if ( ! defined( 'AKAI_MPC1000_PLUGIN_FILE' ) ) {
	define( 'AKAI_MPC1000_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'AKAI_MPC1000_PLUGIN_DIR' ) ) {
	define( 'AKAI_MPC1000_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'AKAI_MPC1000_PLUGIN_URL' ) ) {
	define( 'AKAI_MPC1000_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

require_once AKAI_MPC1000_PLUGIN_DIR . 'includes/class-akai-mpc1000.php';

/**
 * Returns the main plugin instance.
 *
 * @return Akai_MPC1000
 */
function akai_mpc1000() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Akai_MPC1000();
	}

	return $instance;
}

akai_mpc1000();
