<?php
/**
 * Core plugin functionality.
 *
 * @package Akai_MPC1000
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
class Akai_MPC1000 {
	const CPT_PROJECT      = 'akai_mpc_project';
	const META_STATE       = '_akai_mpc_state';
	const META_PERFORMANCE = '_akai_mpc_performance';

	/**
	 * Cached default project state.
	 *
	 * @var array
	 */
	protected $default_state = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_meta_fields' ] );
		add_action( 'init', [ $this, 'register_assets' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_public_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_shortcode( 'akai_mpc1000', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Registers custom post type for MPC projects.
	 */
	public function register_post_type() {
		$labels = [
			'name'               => __( 'MPC Projects', 'akai-mpc1000' ),
			'singular_name'      => __( 'MPC Project', 'akai-mpc1000' ),
			'add_new'            => __( 'Add New', 'akai-mpc1000' ),
			'add_new_item'       => __( 'Add New MPC Project', 'akai-mpc1000' ),
			'edit_item'          => __( 'Edit MPC Project', 'akai-mpc1000' ),
			'new_item'           => __( 'New MPC Project', 'akai-mpc1000' ),
			'all_items'          => __( 'All MPC Projects', 'akai-mpc1000' ),
			'view_item'          => __( 'View MPC Project', 'akai-mpc1000' ),
			'search_items'       => __( 'Search MPC Projects', 'akai-mpc1000' ),
			'not_found'          => __( 'No MPC projects found', 'akai-mpc1000' ),
			'not_found_in_trash' => __( 'No MPC projects found in Trash', 'akai-mpc1000' ),
			'menu_name'          => __( 'MPC Projects', 'akai-mpc1000' ),
		];

		$args = [
			'labels'             => $labels,
			'public'             => false,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'capability_type'    => 'post',
			'supports'           => [ 'title', 'author' ],
			'show_in_rest'       => false,
			'rewrite'            => false,
			'menu_icon'          => 'dashicons-controls-play',
		];

		register_post_type( self::CPT_PROJECT, $args );
	}

	/**
	 * Registers meta fields for projects.
	 */
	public function register_meta_fields() {
		register_post_meta(
			self::CPT_PROJECT,
			self::META_STATE,
			[
				'show_in_rest'       => true,
				'single'            => true,
				'type'              => 'string',
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
				'sanitize_callback' => [ $this, 'sanitize_state' ],
			]
		);

		register_post_meta(
			self::CPT_PROJECT,
			self::META_PERFORMANCE,
			[
				'single'            => true,
				'type'              => 'string',
				'show_in_rest'      => true,
				'auth_callback'     => function() {
					return current_user_can( 'edit_posts' );
				},
				'sanitize_callback' => 'sanitize_textarea_field',
			]
		);
	}

	/**
	 * Sanitize project state payload.
	 *
	 * @param mixed $value Raw meta value.
	 *
	 * @return string
	 */
	public function sanitize_state( $value ) {
		if ( empty( $value ) ) {
			return wp_json_encode( $this->get_default_project_state() );
		}

		if ( is_array( $value ) ) {
			$value = wp_json_encode( $value );
		}

		if ( is_string( $value ) ) {
			$json = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return wp_json_encode( $json );
			}
		}

		return wp_json_encode( $this->get_default_project_state() );
	}

	/**
	 * Registers plugin assets.
	 */
	public function register_assets() {
		$script_path = AKAI_MPC1000_PLUGIN_DIR . 'assets/js/akai-mpc1000.js';
		$style_path  = AKAI_MPC1000_PLUGIN_DIR . 'assets/css/akai-mpc1000.css';

		$script_version = file_exists( $script_path ) ? filemtime( $script_path ) : AKAI_MPC1000_VERSION;
		$style_version  = file_exists( $style_path ) ? filemtime( $style_path ) : AKAI_MPC1000_VERSION;

		wp_register_script(
			'akai-mpc1000-app',
			AKAI_MPC1000_PLUGIN_URL . 'assets/js/akai-mpc1000.js',
			[ 'wp-i18n' ],
			$script_version,
			true
		);

		wp_register_style(
			'akai-mpc1000-app',
			AKAI_MPC1000_PLUGIN_URL . 'assets/css/akai-mpc1000.css',
			[],
			$style_version
		);
	}

	/**
	 * Ensures the admin app assets are enqueued.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_akai-mpc1000' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'akai-mpc1000-app' );
		wp_enqueue_script( 'akai-mpc1000-app' );

		wp_localize_script(
			'akai-mpc1000-app',
			'akaiMPC1000AppConfig',
			[
				'context' => 'admin',
				'rest'    => [
					'root'  => esc_url_raw( rest_url( 'akai-mpc/v1' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				],
				'projects' => $this->prepare_projects_payload(),
				'settings' => [
					'userCanEdit' => current_user_can( 'edit_posts' ),
					'defaultState' => $this->get_default_project_state(),
				],
			]
		);
	}

	/**
	 * Registers the admin page.
	 */
	public function register_admin_page() {
		add_menu_page(
			__( 'AKAI MPC1000', 'akai-mpc1000' ),
			__( 'AKAI MPC1000', 'akai-mpc1000' ),
			'edit_posts',
			'akai-mpc1000',
			[ $this, 'render_admin_page' ],
			'dashicons-controls-play',
			58
		);
	}

	/**
	 * Output the admin application container.
	 */
	public function render_admin_page() {
		echo '<div class="wrap akai-mpc1000-admin"><h1>' . esc_html__( 'AKAI MPC1000 Control Center', 'akai-mpc1000' ) . '</h1>';
		echo '<div id="akai-mpc1000-app" class="akai-mpc1000-app" data-context="admin"></div>';
		echo '</div>';
	}

	/**
	 * Maybe enqueue assets on the public side when shortcode is detected.
	 */
	public function maybe_enqueue_public_assets() {
		if ( ! is_singular() ) {
			return;
		}

		global $post;
		if ( has_shortcode( $post->post_content, 'akai_mpc1000' ) ) {
			wp_enqueue_style( 'akai-mpc1000-app' );
			wp_enqueue_script( 'akai-mpc1000-app' );
		}
	}

	/**
	 * Render the MPC player shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			[
				'project_id' => 0,
				'mode'       => 'perform',
			],
			$atts,
			'akai_mpc1000'
		);

		$project_id = absint( $atts['project_id'] );
		$mode       = sanitize_text_field( $atts['mode'] );

		$instance_id = 'akai-mpc1000-instance-' . wp_generate_password( 8, false, false );

		$payload = [
			'context' => 'embed',
			'mode'    => $mode,
			'project' => $project_id ? $this->prepare_project_payload( $project_id ) : [
				'id'    => 0,
				'title' => __( 'Untitled Performance', 'akai-mpc1000' ),
				'state' => $this->get_default_project_state(),
			],
		];

		$this->inject_bootstrap_data( $instance_id, $payload );

		return sprintf(
			'<div id="%1$s" class="akai-mpc1000-embed" data-instance="%1$s"></div>',
			esc_attr( $instance_id )
		);
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'akai-mpc/v1',
			'/projects',
			[
				[
					'permission_callback' => [ $this, 'can_read_projects' ],
					'callback'            => [ $this, 'rest_get_projects' ],
					'methods'             => WP_REST_Server::READABLE,
				],
				[
					'permission_callback' => [ $this, 'can_edit_projects' ],
					'callback'            => [ $this, 'rest_create_project' ],
					'methods'             => WP_REST_Server::CREATABLE,
					'args'                => $this->get_rest_project_args(),
				],
			]
		);

		register_rest_route(
			'akai-mpc/v1',
			'/projects/(?P<id>\d+)',
			[
				[
					'permission_callback' => [ $this, 'can_read_projects' ],
					'callback'            => [ $this, 'rest_get_project' ],
					'methods'             => WP_REST_Server::READABLE,
					'args'                => [
						'id' => [
							'validate_callback' => 'absint',
						],
					],
				],
				[
					'permission_callback' => [ $this, 'can_edit_projects' ],
					'callback'            => [ $this, 'rest_update_project' ],
					'methods'             => WP_REST_Server::EDITABLE,
					'args'                => array_merge(
						$this->get_rest_project_args( true ),
						[
							'id' => [
								'validate_callback' => 'absint',
							],
						]
					),
				],
			]
		);
	}

	/**
	 * Capability check for reading.
	 *
	 * @return bool
	 */
	public function can_read_projects() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Capability check for editing.
	 *
	 * @return bool
	 */
	public function can_edit_projects() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * REST arguments definition.
	 *
	 * @param bool $is_update Whether update context.
	 *
	 * @return array
	 */
	protected function get_rest_project_args( $is_update = false ) {
		$args = [
			'title' => [
				'required'          => ! $is_update,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'project_state' => [
				'required'          => ! $is_update,
				'type'              => 'object',
				'sanitize_callback' => function( $value ) {
					return json_decode( wp_json_encode( $value ), true );
				},
			],
			'performance_notes' => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
		];

		return $args;
	}

	/**
	 * REST list projects.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_get_projects() {
		$projects = $this->prepare_projects_payload();
		return rest_ensure_response( $projects );
	}

	/**
	 * REST get single project.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_get_project( WP_REST_Request $request ) {
		$project_id = (int) $request['id'];
		$data       = $this->prepare_project_payload( $project_id );

		if ( ! $data ) {
			return new WP_Error( 'akai_mpc_not_found', __( 'Project not found', 'akai-mpc1000' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * REST create project.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_create_project( WP_REST_Request $request ) {
		$params = $request->get_params();
		$state  = $params['project_state'] ?? $this->get_default_project_state();

		$post_id = wp_insert_post(
			[
				'post_type'   => self::CPT_PROJECT,
				'post_title'  => $params['title'] ?? __( 'Untitled Project', 'akai-mpc1000' ),
				'post_status' => 'publish',
			]
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_STATE, wp_json_encode( $state ) );

		if ( ! empty( $params['performance_notes'] ) ) {
			update_post_meta( $post_id, self::META_PERFORMANCE, $params['performance_notes'] );
		}

		return rest_ensure_response( $this->prepare_project_payload( $post_id ) );
	}

	/**
	 * REST update project.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_update_project( WP_REST_Request $request ) {
		$project_id = (int) $request['id'];
		$post       = get_post( $project_id );

		if ( ! $post || self::CPT_PROJECT !== $post->post_type ) {
			return new WP_Error( 'akai_mpc_not_found', __( 'Project not found', 'akai-mpc1000' ), [ 'status' => 404 ] );
		}

		$params = $request->get_params();

		if ( ! empty( $params['title'] ) ) {
			wp_update_post(
				[
					'ID'         => $project_id,
					'post_title' => sanitize_text_field( $params['title'] ),
				]
			);
		}

		if ( isset( $params['project_state'] ) ) {
			update_post_meta( $project_id, self::META_STATE, wp_json_encode( $params['project_state'] ) );
		}

		if ( array_key_exists( 'performance_notes', $params ) ) {
			if ( '' === $params['performance_notes'] ) {
				delete_post_meta( $project_id, self::META_PERFORMANCE );
			} else {
				update_post_meta( $project_id, self::META_PERFORMANCE, sanitize_textarea_field( $params['performance_notes'] ) );
			}
		}

		return rest_ensure_response( $this->prepare_project_payload( $project_id ) );
	}

	/**
	 * Prepare payload for all projects.
	 *
	 * @return array
	 */
	protected function prepare_projects_payload() {
		$posts = get_posts(
			[
				'post_type'      => self::CPT_PROJECT,
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			]
		);

		$payload = [];

		foreach ( $posts as $post ) {
			$payload[] = $this->prepare_project_payload( $post->ID );
		}

		return $payload;
	}

	/**
	 * Prepare payload for a single project.
	 *
	 * @param int $project_id Project ID.
	 *
	 * @return array|null
	 */
	protected function prepare_project_payload( $project_id ) {
		$post = get_post( $project_id );

		if ( ! $post || self::CPT_PROJECT !== $post->post_type ) {
			return null;
		}

		$state_json = get_post_meta( $project_id, self::META_STATE, true );

		if ( empty( $state_json ) ) {
			$state_json = wp_json_encode( $this->get_default_project_state() );
		}

		$state = json_decode( $state_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$state = $this->get_default_project_state();
		}

		return [
			'id'          => (int) $project_id,
			'title'       => get_the_title( $post ),
			'author'      => (int) $post->post_author,
			'updated'     => mysql_to_rfc3339( $post->post_modified_gmt ),
			'performance' => get_post_meta( $project_id, self::META_PERFORMANCE, true ),
			'state'       => $state,
		];
	}

	/**
	 * Inject bootstrap data for embed instances.
	 *
	 * @param string $instance_id Instance ID.
	 * @param array  $payload     Payload.
	 */
	protected function inject_bootstrap_data( $instance_id, array $payload ) {
		wp_enqueue_style( 'akai-mpc1000-app' );
		wp_enqueue_script( 'akai-mpc1000-app' );

		wp_add_inline_script(
			'akai-mpc1000-app',
			'window.AkaiMPC1000Bootstraps = window.AkaiMPC1000Bootstraps || {}; window.AkaiMPC1000Bootstraps[' . wp_json_encode( $instance_id ) . '] = ' . wp_json_encode( $payload ) . ';',
			'before'
		);
	}

	/**
	 * Build default project state approximating MPC layout.
	 *
	 * @return array
	 */
	public function get_default_project_state() {
		if ( ! empty( $this->default_state ) ) {
			return $this->default_state;
		}

		$banks = [];
		foreach ( [ 'A', 'B', 'C', 'D' ] as $bank ) {
			$banks[] = $this->build_pad_bank( $bank );
		}

		$this->default_state = [
			'global'    => [
				'volume'       => 0.85,
				'tempo'        => 96,
				'swing'        => 50,
				'quantize'     => 16,
				'timeSignature'=> '4/4',
			],
			'banks'     => $banks,
			'sequences' => [
				[
					'id'       => 'SEQ-1',
					'name'     => __( 'Sequence 1', 'akai-mpc1000' ),
					'bars'     => 4,
					'tempo'    => 96,
					'swing'    => 50,
					'tracks'   => $this->build_default_tracks(),
					'grid'     => $this->build_empty_grid( 4, 16 ),
					'mutes'    => [],
				],
			],
			'song'      => [
				'chain'  => [],
				'loops'  => false,
			],
		];

		return $this->default_state;
	}

	/**
	 * Build pad bank definition.
	 *
	 * @param string $bank Bank letter.
	 *
	 * @return array
	 */
	protected function build_pad_bank( $bank ) {
		$pads = [];
		$colors = [ '#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5', '#2196f3', '#03a9f4', '#00bcd4', '#009688', '#4caf50', '#8bc34a', '#cddc39', '#ffeb3b', '#ffc107', '#ff9800', '#ff5722' ];

		for ( $i = 0; $i < 16; $i++ ) {
			$pad_number = $i + 1;
			$pads[]     = [
				'id'           => sprintf( '%s%02d', $bank, $pad_number ),
				'label'        => sprintf( '%s%02d', $bank, $pad_number ),
				'color'        => $colors[ $i ],
				'sample'       => null,
				'volume'       => 0.8,
				'pan'          => 0,
				'filter'       => [ 'type' => 'lowpass', 'cutoff' => 20000, 'resonance' => 0 ],
				'adsr'         => [ 'attack' => 0.001, 'decay' => 0.2, 'sustain' => 0.8, 'release' => 0.3 ],
				'loop'         => false,
				'padNote'      => 36 + $i,
				'chokeGroup'   => 0,
				'midiOut'      => null,
				'slicePoints'  => [],
			];
		}

		return [
			'id'   => $bank,
			'name' => sprintf( __( 'Bank %s', 'akai-mpc1000' ), $bank ),
			'pads' => $pads,
		];
	}

	/**
	 * Build default tracks.
	 *
	 * @return array
	 */
	protected function build_default_tracks() {
		$tracks = [];

		for ( $i = 1; $i <= 64; $i++ ) {
			$tracks[] = [
				'id'          => sprintf( 'TRK-%02d', $i ),
				'name'        => sprintf( __( 'Track %02d', 'akai-mpc1000' ), $i ),
				'program'     => 'DRUMS',
				'midiChannel' => 1,
				'type'        => $i <= 4 ? 'DRUM' : 'MIDI',
				'notes'       => [],
			];
		}

		return $tracks;
	}

	/**
	 * Build empty sequencer grid.
	 *
	 * @param int $bars   Number of bars.
	 * @param int $steps  Steps per bar.
	 *
	 * @return array
	 */
	protected function build_empty_grid( $bars, $steps ) {
		$grid = [];
		$total_steps = $bars * $steps;

		for ( $step = 0; $step < $total_steps; $step++ ) {
			$grid[] = [
				'index'   => $step,
				'events'  => [],
				'accent'  => 0,
				'swing'   => null,
			];
		}

		return $grid;
	}

	/**
	 * Prepare initial JavaScript boot payload.
	 *
	 * @return array
	 */
	protected function prepare_bootstrap() {
		return [
			'context' => 'admin',
			'default' => $this->get_default_project_state(),
		];
	}
}
