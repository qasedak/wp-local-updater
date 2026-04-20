<?php
/**
 * Plugin Name: WP Local Updater
 * Plugin URI: https://github.com/qasedak/wp-local-updater
 * Description: Update WordPress core from a direct download URL or uploaded ZIP — for environments with restricted internet access.
 * Version: 1.0.0
 * Author: Mohammad Anbarestany
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-local-updater
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Local_Updater {

	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'wp-local-updater',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	public function add_menu() {
		add_management_page(
			__( 'WP Local Updater', 'wp-local-updater' ),
			__( 'Local Updater', 'wp-local-updater' ),
			'update_core',
			'wp-local-updater',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue styles for the plugin admin page.
	 */
	public function enqueue_styles( $hook ) {
		// Only load on our plugin page
		if ( 'tools_page_wp-local-updater' !== $hook ) {
			return;
		}

		$plugin_url = plugin_dir_url( __FILE__ );
		$css_file   = 'assets/css/wp-local-updater.css';

		wp_enqueue_style(
			'wp-local-updater',
			$plugin_url . $css_file,
			[],
			filemtime( plugin_dir_path( __FILE__ ) . $css_file )
		);
	}

	/**
	 * Single page callback: detects POST overflow, processes upgrade, or shows the form.
	 * Runs inside the normal admin page context, same pattern as WordPress's own update-core.php.
	 */
	public function render_page() {
		if ( ! current_user_can( 'update_core' ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'wp-local-updater' ) );
		}

		// When post_max_size is exceeded, PHP silently empties $_POST.
		// Detect this by comparing CONTENT_LENGTH against the PHP limit.
		if (
			empty( $_POST ) &&
			! empty( $_SERVER['CONTENT_LENGTH'] ) &&
			(int) $_SERVER['CONTENT_LENGTH'] > $this->bytes( ini_get( 'post_max_size' ) )
		) {
			$limit = ini_get( 'post_max_size' );
			echo '<div class="wrap"><div class="notice notice-error" ><p>';
			echo '<strong>' . esc_html__( "Error: Uploaded file exceeds PHP's post_max_size limit.", 'wp-local-updater' ) . '</strong><br>';
			/* translators: %s: current post_max_size value */
			echo sprintf( esc_html__( 'Current limit: %s', 'wp-local-updater' ), '<code dir="ltr">' . esc_html( $limit ) . '</code>' ) . '<br>';
			echo esc_html__( 'To increase the limit, set the following values in php.ini (or .htaccess):', 'wp-local-updater' );
		echo '<pre class="wlu-error-limit">';
			echo "upload_max_filesize = 64M\npost_max_size = 64M\nmax_execution_time = 300";
			echo '</pre>';
			echo '</p></div></div>';
			return;
		}

		if ( isset( $_POST['wlu_do_update'] ) && check_admin_referer( 'wlu_update_action', 'wlu_nonce' ) ) {
			$this->do_upgrade();
			return;
		}

		$this->render_form();
	}

	/**
	 * Process the core upgrade. Runs inside the admin page context.
	 */
	private function do_upgrade() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$version     = sanitize_text_field( wp_unslash( $_POST['wlu_version'] ?? '' ) );
		$source_type = sanitize_text_field( wp_unslash( $_POST['wlu_source_type'] ?? 'url' ) );

		if ( ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $version ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>';
			echo esc_html__( 'Invalid version number format.', 'wp-local-updater' );
			echo '</p></div></div>';
			return;
		}

		if ( $source_type === 'file' ) {
			$zip_source = $this->handle_file_upload();
			if ( is_wp_error( $zip_source ) ) {
				echo '<div class="wrap"><div class="notice notice-error"><p>';
				/* translators: %s: error message */
				echo sprintf( esc_html__( 'File upload failed: %s', 'wp-local-updater' ), esc_html( $zip_source->get_error_message() ) );
				echo '</p></div></div>';
				return;
			}
		} else {
			$zip_source = esc_url_raw( wp_unslash( $_POST['wlu_url'] ?? '' ) );
			if ( empty( $zip_source ) || ! filter_var( $zip_source, FILTER_VALIDATE_URL ) ) {
				echo '<div class="wrap"><div class="notice notice-error"><p>';
				echo esc_html__( 'Invalid download URL.', 'wp-local-updater' );
				echo '</p></div></div>';
				return;
			}
		}

		$page_url = wp_nonce_url(
			admin_url( 'tools.php?page=wp-local-updater' ),
			'wlu_update_action',
			'wlu_nonce'
		);

		$credentials = request_filesystem_credentials(
			$page_url,
			'',
			false,
			ABSPATH,
			[ 'wlu_do_update', 'wlu_version', 'wlu_source_type', 'wlu_url' ]
		);

		if ( $credentials === false ) {
			return; // WP displayed the credentials form; wait for user input.
		}

		if ( ! WP_Filesystem( $credentials ) ) {
			request_filesystem_credentials( $page_url, '', true, ABSPATH,
				[ 'wlu_do_update', 'wlu_version', 'wlu_source_type', 'wlu_url' ]
			);
			return;
		}

		@set_time_limit( 300 );

		// Block outbound requests to wordpress.org during the upgrade.
		// Core_Upgrader calls wp_version_check() internally, which fails in
		// restricted networks (e.g. Iran) and produces confusing warnings.
		$block_wporg = function ( $pre, $args, $url ) {
			if ( strpos( $url, 'wordpress.org' ) !== false ) {
				return new WP_Error( 'wlu_blocked', 'Blocked: not needed for local update.' );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $block_wporg, 1, 3 );

		// On Debian/Ubuntu the WordPress package installs ca-bundle.crt as a symlink
		// to a root-owned system file. WP_Filesystem_Direct::copy() follows the symlink
		// and fails with "Permission denied". Remove the symlink so the upgrader can
		// place a real file in its place.
		$ca_path = ABSPATH . 'wp-includes/certificates/ca-bundle.crt';
		if ( is_link( $ca_path ) ) {
			unlink( $ca_path );
		}

		$update = (object) [
			'response' => 'upgrade',
			'current'  => $version,
			'version'  => $version,
			'download' => $zip_source,
			'locale'   => get_locale(),
			'packages' => (object) [
				'partial'     => null,
				'new_bundled' => null,
				'no_content'  => null,
				'full'        => $zip_source,
			],
		];

		echo '<div class="wrap" >';
		echo '<h1>' . esc_html__( 'Updating WordPress…', 'wp-local-updater' ) . '</h1>';

		$skin     = new WP_Upgrader_Skin( [ 'title' => __( 'WordPress Update', 'wp-local-updater' ) ] );
		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $update );

		remove_filter( 'pre_http_request', $block_wporg, 1 );

		echo '<hr class="wlu-divider">';

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>';
			/* translators: %s: error message */
			echo '<strong>' . esc_html__( 'Error:', 'wp-local-updater' ) . '</strong> ' . esc_html( $result->get_error_message() );
			echo '</p></div>';
		} elseif ( $result === false ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Update failed. Review the messages above.', 'wp-local-updater' );
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>';
			/* translators: %s: version number */
			echo sprintf(
				esc_html__( 'WordPress successfully updated to version %s.', 'wp-local-updater' ),
				'<strong>' . esc_html( $version ) . '</strong>'
			);
			echo '</p></div>';

			if ( $source_type === 'file' && file_exists( $zip_source ) ) {
				wp_delete_file( $zip_source );
			}
		}

		echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=wp-local-updater' ) ) . '" class="button">';
		echo esc_html__( '← Back', 'wp-local-updater' );
		echo '</a></p>';
		echo '</div>';
	}

	/**
	 * Validate and save the uploaded ZIP. Returns the local file path or WP_Error.
	 */
	private function handle_file_upload() {
		if (
			empty( $_FILES['wlu_zip'] ) ||
			$_FILES['wlu_zip']['error'] !== UPLOAD_ERR_OK ||
			! is_uploaded_file( $_FILES['wlu_zip']['tmp_name'] )
		) {
			return new WP_Error( 'no_file', __( 'No file received or upload error.', 'wp-local-updater' ) );
		}

		$file = $_FILES['wlu_zip'];

		if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'zip' ) {
			return new WP_Error( 'bad_ext', __( 'File must have a .zip extension.', 'wp-local-updater' ) );
		}

		$handle = fopen( $file['tmp_name'], 'rb' );
		if ( $handle === false ) {
			return new WP_Error( 'read_error', __( 'Error reading uploaded file.', 'wp-local-updater' ) );
		}
		$magic = fread( $handle, 4 );
		fclose( $handle );

		if ( $magic !== "PK\x03\x04" ) {
			return new WP_Error( 'bad_magic', __( 'File is not a valid ZIP archive.', 'wp-local-updater' ) );
		}

		$upload_dir = wp_upload_dir();
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-local-updater/';

		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Could not create upload directory.', 'wp-local-updater' ) );
		}

		$filename = 'wordpress-' . bin2hex( random_bytes( 8 ) ) . '.zip';
		$target   = $target_dir . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) {
			return new WP_Error( 'move_failed', __( 'Error saving uploaded file.', 'wp-local-updater' ) );
		}

		return $target;
	}

	/**
	 * Render the update form.
	 */
	private function render_form() {
		$current_version = get_bloginfo( 'version' );
		?>

		<div class="wrap wlu-wrap">
			<h1><?php esc_html_e( 'WP Local Updater', 'wp-local-updater' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Enter a direct download URL for the new WordPress ZIP, or upload the file. Clicking Update will apply the upgrade immediately.', 'wp-local-updater' ); ?></p>

			<hr>

			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Current version', 'wp-local-updater' ); ?></th>
					<td><strong><?php echo esc_html( $current_version ); ?></strong></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Update WordPress Core', 'wp-local-updater' ); ?></h2>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'wlu_update_action', 'wlu_nonce' ); ?>
				<input type="hidden" name="wlu_do_update" value="1">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wlu_version"><?php esc_html_e( 'New version number', 'wp-local-updater' ); ?></label>
						</th>
						<td>
							<input type="text"
							       id="wlu_version"
							       name="wlu_version"
							       class="regular-text"
							       placeholder="<?php esc_attr_e( 'e.g. 6.9.4', 'wp-local-updater' ); ?>"
							       pattern="^\d+\.\d+(\.\d+)?$"
							       required
							       dir="ltr">
							<p class="description">
								<?php
								/* translators: %s: current WordPress version number */
								printf(
									esc_html__( 'Must be greater than the current version (%s).', 'wp-local-updater' ),
									esc_html( $current_version )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Update source', 'wp-local-updater' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio"
									       name="wlu_source_type"
									       value="url"
									       id="wlu_source_url"
									       checked>
									<?php esc_html_e( 'Download URL', 'wp-local-updater' ); ?>
								</label>

								<div id="wlu_url_field">
									<input type="url"
									       name="wlu_url"
									       id="wlu_url"
									       class="large-text"
									       dir="ltr"
									       placeholder="https://wordpress.org/latest.zip">
									<p class="description">
										<?php esc_html_e( 'Enter a direct download link to the WordPress .zip from a local mirror.', 'wp-local-updater' ); ?>
									</p>
								</div>

								<br>

								<label>
									<input type="radio"
									       name="wlu_source_type"
									       value="file"
									       id="wlu_source_file">
									<?php esc_html_e( 'Upload ZIP file', 'wp-local-updater' ); ?>
								</label>

								<div id="wlu_file_field" style="display:none;">
									<input type="file"
									       name="wlu_zip"
									       id="wlu_zip"
									       accept=".zip,application/zip">
									<p class="description">
										<?php
										/* translators: %s: server upload size limit */
										printf(
											esc_html__( 'Upload the wordpress-x.x.x.zip file you downloaded manually. Server limit: %s', 'wp-local-updater' ),
											esc_html( ini_get( 'upload_max_filesize' ) )
										);
										?>
									</p>
								</div>
							</fieldset>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary button-hero"
					        onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to update WordPress? Make sure you have a backup before proceeding.', 'wp-local-updater' ) ); ?>');">
					<span class="dashicons dashicons-update wlu-dashicons"></span><?php esc_html_e( 'Start Update', 'wp-local-updater' ); ?>
					</button>
				</p>
			</form>

		<div class="wlu-notice-box">
			<strong><span class="dashicons dashicons-warning wlu-dashicons"></span><?php esc_html_e( 'Notice:', 'wp-local-updater' ); ?></strong>
			<ul>
				<li><?php esc_html_e( 'Always back up your database and files before updating.', 'wp-local-updater' ); ?></li>
				<li><?php esc_html_e( 'Make sure the ZIP file is the official WordPress release.', 'wp-local-updater' ); ?></li>
				<li><?php esc_html_e( 'Do not close or refresh the page during the update.', 'wp-local-updater' ); ?></li>
				<li>
					<?php esc_html_e( 'Ensure PHP limits are set high enough:', 'wp-local-updater' ); ?>
					<pre class="wlu-code-block">upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300</pre>
					<?php
					$pm  = $this->bytes( ini_get( 'post_max_size' ) );
					$um  = $this->bytes( ini_get( 'upload_max_filesize' ) );
					$et  = (int) ini_get( 'max_execution_time' );
					$low = ( $pm < 20 * 1024 * 1024 ) || ( $um < 20 * 1024 * 1024 ) || ( $et > 0 && $et < 120 );
					if ( $low ) :
					?>
					<br><span class="wlu-settings-warning">
						<?php
						/* translators: 1: post_max_size value, 2: upload_max_filesize value, 3: max_execution_time in seconds */
						printf(
							esc_html__( 'Your current server settings are low: post_max_size=%1$s, upload_max_filesize=%2$s, max_execution_time=%3$ss', 'wp-local-updater' ),
							esc_html( ini_get( 'post_max_size' ) ),
							esc_html( ini_get( 'upload_max_filesize' ) ),
							esc_html( $et )
						);
						?>
					</span>
					<?php endif; ?>
				</li>
			</ul>
		</div>
		</div>

		<script>
		( function () {
			var urlRadio  = document.getElementById( 'wlu_source_url' );
			var fileRadio = document.getElementById( 'wlu_source_file' );
			var urlField  = document.getElementById( 'wlu_url_field' );
			var fileField = document.getElementById( 'wlu_file_field' );
			var urlInput  = document.getElementById( 'wlu_url' );
			var fileInput = document.getElementById( 'wlu_zip' );

			if ( ! urlRadio ) return;

			function toggle() {
				var isUrl = urlRadio.checked;
				urlField.style.display  = isUrl ? '' : 'none';
				fileField.style.display = isUrl ? 'none' : '';
				urlInput.required  = isUrl;
				fileInput.required = ! isUrl;
			}

			urlRadio.addEventListener( 'change', toggle );
			fileRadio.addEventListener( 'change', toggle );
			toggle();
		} )();
		</script>
		<?php
	}

	/**
	 * Convert PHP shorthand size notation (e.g. "8M") to bytes.
	 */
	private function bytes( string $val ): int {
		$val  = trim( $val );
		$last = strtolower( $val[ strlen( $val ) - 1 ] );
		$num  = (int) $val;
		switch ( $last ) {
			case 'g': $num *= 1024;
			// fall through
			case 'm': $num *= 1024;
			// fall through
			case 'k': $num *= 1024;
		}
		return $num;
	}
}

new WP_Local_Updater();
