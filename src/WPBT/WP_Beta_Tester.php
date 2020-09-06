<?php
/**
 * WordPress Beta Tester
 *
 * @package WordPress_Beta_Tester
 * @author Andy Fragen, original author Peter Westwood.
 * @license GPLv2+
 * @copyright 2009-2016 Peter Westwood (email : peter.westwood@ftwr.co.uk)
 */

/**
 * WP_Beta_Tester
 */
class WP_Beta_Tester {
	/**
	 * Holds main plugin file.
	 *
	 * @var $file
	 */
	public $file;

	/**
	 * Holds plugin options.
	 *
	 * @var $options
	 */
	public static $options;

	/**
	 * Constructor.
	 *
	 * @param  string $file    Main plugin file.
	 * @param  array  $options Plugin options.
	 * @return void
	 */
	public function __construct( $file, $options ) {
		$this->file    = $file;
		self::$options = $options;
	}

	/**
	 * Rev up the engines.
	 *
	 * @return void
	 */
	public function run() {
		$this->load_hooks();
		// TODO: I really want to do this, but have to wait for PHP 5.4
		// TODO: ( new WPBT_Settings( $this, $options ) )->run();
		$settings = new WPBT_Settings( $this, self::$options );
		$settings->run();
	}

	/**
	 * Load hooks.
	 *
	 * @return void
	 */
	protected function load_hooks() {
		add_action(
			'update_option_wp_beta_tester_stream',
			array(
				$this,
				'action_update_option_wp_beta_tester_stream',
			)
		);
		add_filter( 'pre_http_request', array( $this, 'filter_http_request' ), 10, 3 );

		// Add dashboard widget.
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		add_action( 'wp_network_dashboard_setup', array( $this, 'add_dashboard_widget' ) );

		// Delete development RSS feed transient for dashboard widget on core upgrade.
		add_action( 'upgrader_process_complete', array( $this, 'delete_feed_transient_on_upgrade' ), 10, 2 );
	}

	/**
	 * Check and display notice if 'update' really downgrade.
	 *
	 * @return void
	 */
	public function action_admin_head_plugins_php() {
		// Workaround the check throttling in wp_version_check().
		$st = get_site_transient( 'update_core' );
		if ( is_object( $st ) ) {
			$st->last_checked = 0;
			set_site_transient( 'update_core', $st );
		}
		wp_version_check();

		// Can output an error here if current config drives version backwards.
		if ( $this->check_if_settings_downgrade( $st ) ) {
			echo '<div id="message" class="notice notice-warning"><p>';
			$admin_page = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'tools.php' );
			$admin_page = add_query_arg(
				array(
					'page' => 'wp-beta-tester',
					'tab'  => 'wp_beta_tester_core',
				),
				$admin_page
			);
			/* translators: %s: link to setting page */
			printf(
				/* translators: %s: WordPress Beta Tester Settings page URL */
				wp_kses_post( __( '<strong>Warning:</strong> Your current <a href="%s">WordPress Beta Tester plugin configuration</a> will downgrade your install to a previous version - please reconfigure it.', 'wordpress-beta-tester' ) ),
				esc_url( $admin_page )
			);
			echo '</p></div>';
		}
	}

	/**
	 * Filter 'pre_http_request' to add beta-tester API check.
	 *
	 * @param  mixed  $result $result from filter.
	 * @param  array  $args   Array of filter args.
	 * @param  string $url    URL from filter.
	 * @return /stdClass Output from wp_remote_get().
	 */
	public function filter_http_request( $result, $args, $url ) {
		if ( $result || isset( $args['_beta_tester'] ) ) {
			return $result;
		}
		if ( false === strpos( $url, '//api.wordpress.org/core/version-check/' ) ) {
			return $result;
		}

		// It's a core-update request.
		$args['_beta_tester'] = true;

		$wp_version = get_bloginfo( 'version' );
		// $url        = str_replace( 'version=' . $wp_version, 'version=' . $this->mangle_wp_version(), $url );
		// $url = str_replace('/1.7/', '/1.8/', $url);
		$url = empty( self::$options['stream-option'] )
			? add_query_arg( 'channel', self::$options['channel'], $url )
			: add_query_arg( 'channel', self::$options['stream-option'], $url );

		return wp_remote_get( $url, $args );
	}

	/**
	 * Our option has changed so update the cached information pronto.
	 *
	 * @return void
	 */
	public function action_update_option_wp_beta_tester_stream() {
		do_action( 'wp_version_check' );
	}

	/**
	 * Get preferred update version from core.
	 *
	 * @return /stdClass
	 */
	public function get_preferred_from_update_core() {
		if ( ! function_exists( 'get_preferred_from_update_core' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		// Validate that we have api data and if not get the normal data so we always have it.
		$preferred = get_preferred_from_update_core();
		if ( false === $preferred ) {
			wp_version_check();
			$preferred = get_preferred_from_update_core();
		}

		return $preferred;
	}

	/**
	 * Get modified WP version to pass to API check.
	 *
	 * @return string $wp_version
	 */
	protected function mangle_wp_version() {
		$options    = get_site_option(
			'wp_beta_tester',
			array(
				'stream' => 'point',
				'revert' => true,
			)
		);
		$preferred  = $this->get_preferred_from_update_core();
		$wp_version = get_bloginfo( 'version' );

		// If we're getting no updates back from get_preferred_from_update_core(),
		// let an HTTP request go through unmangled.
		if ( ! isset( $preferred->current ) ) {
			return $wp_version;
		}

		if ( 0 === strpos( $options['stream'], 'beta-rc' )
			&& version_compare( $preferred->current, $wp_version, 'lt' ) ) {
			$versions = array_map( 'intval', explode( '.', $wp_version ) );
		} else {
			$versions = array_map( 'intval', explode( '.', $preferred->current ) );
		}

		// ensure that a downgrade correctly gets mangled version.
		if ( isset( $options['revert'] ) && $options['revert'] ) {
			$versions = $this->correct_versions_for_downgrade( $versions );
		}

		switch ( $options['stream'] ) {
			case 'point':
			case 'beta-rc-point':
				$versions[2] = isset( $versions[2] ) ? $versions[2] + 1 : 1;
				break;
			case 'unstable':
			case 'beta-rc-unstable':
				++$versions[1];
				if ( 10 === $versions[1] ) {
					++$versions[0];
					$versions[1] = 0;
				}
				break;
		}
		$wp_version = implode( '.', $versions ) . '-wp-beta-tester';

		return $wp_version;
	}

	/**
	 * Ensure that a downgrade to a point release returns a version array that
	 * will properly get the correct offer.
	 *
	 * @param array $versions Array containing the semver arguments of the currently
	 *                        installed version.
	 *
	 * @return array
	 */
	private function correct_versions_for_downgrade( $versions ) {
		$wp_version      = get_bloginfo( 'version' );
		$current         = array_map( 'intval', explode( '.', $wp_version ) );
		$release_version = 0 === preg_match( '/alpha|beta|RC/', $wp_version );

		if ( version_compare( implode( '.', $versions ), implode( '.', $current ), '>=' ) ) {
			$versions[1] = $versions[1] - 1;
		}
		if ( ( $release_version || isset( $current[2] ) ) && $versions[1] < $current[1] ) {
			$versions[1] = $current[1];
		}

		// Add an obscenely high value to always get the point release offer.
		$versions[2] = 100;

		return $versions;
	}

	/**
	 * Returns whether beta is really downgrade.
	 *
	 * @param \stdClass Core update object.
	 *
	 * @return bool
	 */
	protected function check_if_settings_downgrade( $current ) {
		$wp_version      = get_bloginfo( 'version' );
		$wp_real_version = explode( '-', $wp_version );
		$wp_next_version = explode( '-', $current->updates[0]->version );
		// $wp_mangled_version = explode( '-', $this->mangle_wp_version() );
		// $wp_mangled_version = $wp_real_version;

		return version_compare( $wp_next_version[0], $wp_real_version[0], 'lt' );
	}

	/**
	 * Add dashboard widget for beta testing information.
	 *
	 * @since 2.2.3
	 *
	 * @return void
	 */
	public function add_dashboard_widget() {
		$wp_version = get_bloginfo( 'version' );
		$beta_rc    = 1 === preg_match( '/alpha|beta|RC/', $wp_version );

		if ( $beta_rc ) {
			wp_add_dashboard_widget( 'beta_tester_dashboard_widget', __( 'WordPress Beta Testing', 'wordpress-beta-tester' ), array( $this, 'beta_tester_dashboard' ) );
		}
	}

	/**
	 * Setup dashboard widget.
	 *
	 * @since 2.2.3
	 *
	 * @return void
	 */
	public function beta_tester_dashboard() {
		$wp_version   = get_bloginfo( 'version' );
		$next_version = explode( '-', $wp_version );
		$milestone    = array_shift( $next_version );

		/* translators: %s: WordPress version */
		printf( wp_kses_post( '<p>' . __( 'Please help test <strong>WordPress %s</strong>.', 'wordpress-beta-tester' ) . '</p>' ), esc_attr( $milestone ) );

		echo wp_kses_post( $this->add_dev_notes_field_guide_links( $milestone ) );
		echo wp_kses_post( $this->parse_development_feed( $milestone ) );

		/* translators: %1: link to closed and reopened trac tickets on current milestone */
		printf( wp_kses_post( '<p>' . __( 'Here are the <a href="%s">commits for the milestone</a>.', 'wordpress-beta-tester' ) . '</p>' ), esc_url_raw( "https://core.trac.wordpress.org/query?status=closed&status=reopened&milestone=$milestone" ) );

		/* translators: %s: link to trac search */
		printf( wp_kses_post( '<p>' . __( '&#128027; Did you find a bug? Search for a <a href="%s">trac ticket</a> to see if it has already been reported.', 'wordpress-beta-tester' ) . '</p>' ), 'https://core.trac.wordpress.org/search' );

		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( current_user_can( $capability ) ) {
			$parent             = is_multisite() ? 'settings.php' : 'tools.php';
			$wpbt_settings_page = add_query_arg( 'page', 'wp-beta-tester', network_admin_url( $parent ) );

			/* translators: %s: WP Beta Tester settings URL */
			printf( wp_kses_post( '<p>' . __( 'Head over to your <a href="%s">WordPress Beta Tester Settings</a> and make sure the <strong>beta/RC</strong> stream is selected.', 'wordpress-beta-tester' ) . '</p>' ), esc_url_raw( $wpbt_settings_page ) );
		}
	}

	/**
	 * Parse development RSS feed for list of milestoned items.
	 *
	 * @since 2.2.3
	 * @param string $milestone Milestone version.
	 *
	 * @return string HTML unordered list.
	 */
	private function parse_development_feed( $milestone ) {
		$rss_args = array(
			'show_summary' => 0,
			'items'        => 10,
		);
		ob_start();
		wp_widget_rss_output( 'https://wordpress.org/news/category/development/feed/', $rss_args );
		$feed = ob_get_contents();
		ob_end_clean();

		$milestone = preg_quote( $milestone, '.' );
		$li_regex  = "#<li>.*$milestone.*?<\/li>#";
		preg_match( $li_regex, $feed, $matches );
		$match = array_pop( $matches );
		$list  = empty( $match ) ? '' : "<ul>$match</ul>";

		return $list;
	}

	/**
	 * Add milestone dev notes and field guide when on RC version.
	 *
	 * @since 2.2.3
	 * @param string $milestone Milestone version.
	 *
	 * @return string HTML unordered list.
	 */
	private function add_dev_notes_field_guide_links( $milestone ) {
		$wp_version       = get_bloginfo( 'version' );
		$beta_rc          = 1 === preg_match( '/beta|RC/', $wp_version );
		$rc               = 1 === preg_match( '/RC/', $wp_version );
		$milestone_dash   = str_replace( '.', '-', $milestone );
		$dev_note_link    = '';
		$field_guide_link = '';

		if ( $beta_rc ) {
			$dev_note_link = sprintf(
			/* translators: %1$s Link to dev notes, %2$s: Link title */
				'<a href="%1$s">%2$s</a>',
				"https://make.wordpress.org/core/tag/$milestone_dash+dev-notes/",
				/* translators: %s: Milestone version */
				sprintf( __( 'WordPress %s Dev Notes', 'wordpress-beta-tester' ), $milestone )
			);
			$dev_note_link = "<li>$dev_note_link</li>";
		}
		if ( $rc ) {
			$field_guide_link = sprintf(
			/* translators: %1$s Link to field guide, %2$s: Link title */
				'<a href="%1$s">%2$s</a>',
				"https://make.wordpress.org/core/tag/$milestone_dash+field-guide/",
				/* translators: %s: Milestone version */
				sprintf( __( 'WordPress %s Field Guide', 'wordpress-beta-tester' ), $milestone )
			);
			$field_guide_link = "<li>$field_guide_link</li>";
		}
		$links = $beta_rc || $rc ? "<ul> $dev_note_link $field_guide_link </ul>" : null;

		return $links;
	}

	/**
	 * Delete development RSS feed transient on core upgrade.
	 *
	 * @uses filter 'upgrader_process_complete'.
	 *
	 * @param \Core_Upgrader $obj        \Core_Upgrader object.
	 * @param array          $hook_extra $hook_extra array from filter.
	 *
	 * @return void
	 */
	public function delete_feed_transient_on_upgrade( $obj, $hook_extra ) {
		if ( $obj instanceof \Core_Upgrader && 'core' === $hook_extra['type'] ) {
			$transient = md5( 'https://wordpress.org/news/category/development/feed/' );
			delete_transient( "feed_{$transient}" );
			delete_transient( "feed_mod_{$transient}" );
		}
	}
}
