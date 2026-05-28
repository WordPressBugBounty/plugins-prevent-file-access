<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://miniorange.com/
 * @since      1.1.1
 *
 * @package    Media_Restriction
 * @subpackage Media_Restriction/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Media_Restriction
 * @subpackage Media_Restriction/admin
 * @author     test <test@test.com>
 */
require_once 'partials/media-restriction-admin-display.php';
require_once 'class-miniorange-media-restriction-customer.php';
require_once 'partials/class-mo-media-restriction-admin-feedback.php';
/**
 * This class handled the admin menu and initiate the plugin.
 */
class Media_Restriction_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.1.1
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.1.1
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.1.1
	 * @param string $plugin_name  The name of this plugin.
	 * @param string $version  The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		update_option( 'host_name', 'https://login.xecurify.com' );
		add_action( 'init', array( $this, 'mo_media_restriction_validate' ) );
		if ( get_option( 'mo_enable_media_restriction' ) === false ) {
			update_option( 'mo_enable_media_restriction', 1 );
		}
	}

	/**
	 * Validate and sanitize file path to prevent path traversal attacks
	 *
	 * @param string $path The path to validate.
	 * @return string|false The validated path or false if invalid
	 */
	private function mo_media_restriction_validate_path( $path ) {
		$path = str_replace( array( "\0", "\x00" ), '', $path );
		$path = str_replace( '\\', '/', $path );

		$previous_path = '';
		while ( $path !== $previous_path ) {
			$previous_path = $path;
			$path          = urldecode( $path );
		}

		$path = str_replace( '\\', '/', $path );

		$dangerous_patterns = array(
			'../',
			'..\\',
			'%2e%2e%2f',
			'%2e%2e%5c',
			'%252e%252e%252f',
			'%252e%252e%255c',
			'0x2e0x2e0x2f',
			'0x2e0x2e0x5c',
			'%c0%ae%c0%ae%c0%af',
			'%c1%9c',
			'..%2f',
			'..%5c',
			'%2e.',
			'.%2e',
		);

		foreach ( $dangerous_patterns as $pattern ) {
			if ( stripos( $path, $pattern ) !== false ) {
				$this->mo_media_restriction_log_security_event( 'Dangerous path pattern detected', $path );
				return false;
			}
		}

		if ( substr( $path, 0, 1 ) === '/' || ( strlen( $path ) > 1 && substr( $path, 1, 1 ) === ':' ) ) {
			return false;
		}

		$full_path = ABSPATH . DIRECTORY_SEPARATOR . ltrim( $path, '/' );

		$real_path = realpath( $full_path );

		if ( false === $real_path ) {
			return false;
		}

		$wp_root = realpath( ABSPATH );
		if ( strpos( $real_path, $wp_root ) !== 0 ) {
			return false;
		}

		$allowed_dirs = $this->mo_media_restriction_get_allowed_directories();
		$is_allowed   = false;

		foreach ( $allowed_dirs as $allowed_dir ) {
			$allowed_real_path = realpath( $allowed_dir );
			if ( false !== $allowed_real_path && strpos( $real_path, $allowed_real_path ) === 0 ) {
				$is_allowed = true;
				break;
			}
		}

		if ( ! $is_allowed ) {
			$this->mo_media_restriction_log_security_event( 'Access to disallowed directory attempted', $path );
			return false;
		}

		return $real_path;
	}
	/**
	 * Initialize and return the WP_Filesystem instance.
	 * @return WP_Filesystem_Base|false
	 */
	private function mo_media_restriction_get_filesystem() {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) {
			return false;
		}
		return $wp_filesystem;
	}

	/**
	 * Get list of allowed directories for file access
	 *
	 * @return array Array of allowed directory paths
	 */
	private function mo_media_restriction_get_allowed_directories() {
		$uploads_dir = wp_upload_dir();

		$allowed_dirs = array(
			$uploads_dir['basedir'],
		);

		$protected_dir = $uploads_dir['basedir'] . DIRECTORY_SEPARATOR . 'protectedfiles';
		if ( file_exists( $protected_dir ) ) {
			$allowed_dirs[] = $protected_dir;
		}

		$custom_dirs = get_option( 'mo_media_restriction_allowed_dirs', array() );
		if ( is_array( $custom_dirs ) ) {
			$allowed_dirs = array_merge( $allowed_dirs, $custom_dirs );
		}

		$allowed_dirs = apply_filters( 'mo_media_restriction_allowed_directories', $allowed_dirs );

		return array_unique( $allowed_dirs );
	}

	/**
	 * Show restricted folder path
	 *
	 * @param mixed $redirect_url redirection url.
	 * @return void
	 */
	public function mo_media_show_file_or_folder( $redirect_url ) {
		$validated_path = $this->mo_media_restriction_validate_path( $redirect_url );

		if ( false === $validated_path ) {
			wp_die( 'WPMR001: Invalid file path', 'Access Denied', array( 'response' => 403 ) );
		}

		$file_path     = $validated_path;
		$relative_path = str_replace( ABSPATH, '', $validated_path );
		$file_url      = site_url() . '/' . ltrim( $relative_path, '/' );

		if ( file_exists( $file_path ) ) {
			if ( is_dir( $file_path ) ) {
				$dh = opendir( $file_path );
				if ( $dh ) {

					$file = readdir( $dh );
					while ( false !== $file ) {
						if ( '..' !== $file && '.' !== $file ) {
							$file_name = sanitize_file_name( $file );
							if ( $file_name === $file ) {
								$child_relative_path = ltrim( str_replace( ABSPATH, '', $validated_path ), '/' );
								$child_url           = site_url() . '/' . $child_relative_path . '/' . $file_name;
								echo "<a href='" . esc_url( $child_url ) . "'>" . esc_html( $file ) . '</a><br>';
							}
						}
					}
					closedir( $dh );
				}
				exit;
			} else {
				$allowed_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt' );
				$file_extension     = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

				if ( ! in_array( $file_extension, $allowed_extensions, true ) ) {
					wp_die( 'WPMR002: File type not allowed', 'Access Denied', array( 'response' => 403 ) );
				}

				header( 'content-type: ' . mime_content_type( $file_path ) );
				$wp_filesystem = $this->mo_media_restriction_get_filesystem();
				echo $wp_filesystem->get_contents( $file_path ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary/raw file content output.
				exit;
			}
		} else {
			wp_die( 'WPMR003: No such file exist' );
		}
	}

	/**
	 * Media restriction validate.
	 *
	 * @return mixed
	 */
	public function mo_media_restriction_validate() {
		if ( isset( $_GET['mo_media_restrict_request'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['mo_media_restrict_request'] ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended -- We are implementing custom nonce verification below.
			if ( is_user_logged_in() === false ) {
				$restrict_option = get_option( 'mo_mr_redirect_to' );
				if ( '403-forbidden-page' === $restrict_option ) {
					header( 'HTTP/1.0 403 Forbidden' );
					echo 'Access forbidden!';
				} else {
					$redirect_to = get_permalink( get_page_by_path( $restrict_option ) );
					wp_safe_redirect( $redirect_to );
				}
				exit;
			} else {
				$redirect_url = isset( $_GET['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) : site_url();

				if ( empty( $redirect_url ) || strlen( $redirect_url ) > 255 ) {
					wp_die( 'WPMR005: Invalid file path', 'Access Denied', array( 'response' => 403 ) );
				}

				$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
				if ( ! wp_verify_nonce( $nonce, 'mo_media_restriction_access' ) ) {
					$this->mo_media_restriction_log_security_event( 'Invalid nonce for file access', $redirect_url );
					wp_die( 'WPMR006: Invalid file path', 'Access Denied', array( 'response' => 403 ) );
				}

				if ( strpos( $redirect_url, '..' ) !== false || strpos( $redirect_url, '/' ) === 0 ) {
					$this->mo_media_restriction_log_security_event( 'Path traversal attempt detected', $redirect_url );
					wp_die( 'WPMR007: Invalid file path', 'Access Denied', array( 'response' => 403 ) );
				}

				if ( ! $this->mo_media_restriction_check_rate_limit() ) {
					wp_die( 'WPMR008: Too many requests. Please try again later.', 'Rate Limit Exceeded', array( 'response' => 429 ) );
				}

				$this->mo_media_show_file_or_folder( $redirect_url );
			}
		}
	}

	/**
	 * Log security events for monitoring
	 *
	 * @param string $event_type Type of security event.
	 * @param string $details Additional details.
	 * @return void
	 */
	private function mo_media_restriction_log_security_event( $event_type, $details ) {
		$user      = wp_get_current_user();
		$log_entry = array(
			'timestamp'  => current_time( 'mysql' ),
			'user_id'    => $user->ID,
			'user_login' => $user->user_login,
			'ip_address' => isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown',

			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: 'unknown',
			'event_type' => $event_type,
			'details'    => $details,
		);

		$existing_logs   = get_option( 'mo_media_restriction_security_logs', array() );
		$existing_logs[] = $log_entry;

		if ( count( $existing_logs ) > 100 ) {
			$existing_logs = array_slice( $existing_logs, -100 );
		}

		update_option( 'mo_media_restriction_security_logs', $existing_logs );

	}

	/**
	 * Simple rate limiting to prevent abuse
	 *
	 * @return bool True if request is allowed, false if rate limited
	 */
	private function mo_media_restriction_check_rate_limit() {
		$user_id        = get_current_user_id();
		$rate_limit_key = "mo_media_restriction_rate_limit_{$user_id}";
		$current_time   = time();
		$window_size    = 60;
		$max_requests   = 30;

		$user_requests = get_transient( $rate_limit_key );

		if ( false === $user_requests ) {
			set_transient( $rate_limit_key, array( $current_time ), $window_size );
			return true;
		}

		$user_requests = array_filter(
			$user_requests,
			function( $timestamp ) use ( $current_time, $window_size ) {
				return ( $current_time - $timestamp ) < $window_size;
			}
		);

		if ( count( $user_requests ) >= $max_requests ) {
			return false;
		}

		$user_requests[] = $current_time;
		set_transient( $rate_limit_key, $user_requests, $window_size );

		return true;
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.1.1
	 * @param string $page page.
	 * @return mixed
	 */
	public function enqueue_styles( $page ) {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Media_Restriction_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Media_Restriction_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		if ( 'toplevel_page_mo_media_restrict' !== $page ) {
			return;
		}
		if ( isset( $_REQUEST['page'] ) && 'mo_media_restrict' === sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ignoring nonce recommendation because we are fetching data from URL directly and not form submission.
			wp_enqueue_style( 'mo_media_admin_bootstrap_style', plugins_url( 'css/bootstrap.min.css', __FILE__ ), array(), $this->version );
			wp_enqueue_style( 'mo_media_admin_font_awesome_style', plugins_url( 'css/font-awesome.min.css', __FILE__ ), array(), $this->version );
			wp_enqueue_style( 'mo_media_admin_media_phone_style', plugins_url( 'css/phone.min.css', __FILE__ ), array(), $this->version );
			wp_enqueue_style( 'mo_media_admin_media_settings_style', plugins_url( 'css/media-restriction-admin.css', __FILE__ ), array(), $this->version );
			wp_enqueue_style( 'mo_media_admin_settings_style', plugins_url( 'css/style.min.css', __FILE__ ), array(), $this->version );
			wp_enqueue_style( 'mo_media_admin_table_style', plugins_url( 'css/jquery.dataTables.min.css', __FILE__ ), array(), $this->version );
		}
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.1.1
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Media_Restriction_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Media_Restriction_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */
		if ( isset( $_REQUEST['page'] ) && 'mo_media_restrict' === sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ignoring nonce recommendation because we are fetching data from URL directly and not form submission.
			wp_enqueue_script( 'mo_media_admin_media_settings_script', plugins_url( 'js/media-restriction-admin.min.js', __FILE__ ), array(), $this->version, false );
			wp_enqueue_script( 'mo_media_admin_media_phone_script', plugins_url( 'js/phone.js', __FILE__ ), array(), $this->version, false );
			wp_enqueue_script( 'mo_media_admin_custom_settings_script', plugins_url( 'js/custom.min.js', __FILE__ ), array(), $this->version, false );
			wp_enqueue_script( 'mo_media_admin_table_script', plugins_url( 'js/jquery.dataTables.min.js', __FILE__ ), array(), $this->version, false );
			wp_enqueue_script( 'mo_media_admin_fontawesome_script', plugins_url( 'js/fontawesome.js', __FILE__ ), array(), $this->version, false );
		}
	}
	/**
	 * Maps allowed file extensions to their permitted MIME types.
	 * Single source of truth for both extension and MIME validation.
	 *
	 * @return array<string, string|array<int, string>>
	 */
	private function mo_media_restriction_get_allowed_mime_types() {
		return array(
			'doc'  => array( 'application/msword', 'application/octet-stream' ),
			'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip' ),
			'pdf'  => 'application/pdf',
			'png'  => 'image/png',
			'jpg'  => array( 'image/jpeg' ),
			'gif'  => 'image/gif',
		);
	}
	/**
	 * On Post field check values are empty or not
	 *
	 * @since    1.1.1
	 * @param string $value  The version of this plugin.
	 */
	private function mo_media_restriction_check_empty_or_null( $value ) {
		if ( ! isset( $value ) || empty( $value ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Remove rules in htaccess file
	 *
	 * @since    1.1.1
	 */
	private function mo_media_restrict_remove_rules() {
		$home_path     = get_home_path();
		$htaccess_file = $home_path . '.htaccess';
		$wp_filesystem = $this->mo_media_restriction_get_filesystem();

		if (
			( file_exists( $htaccess_file ) && $wp_filesystem->is_writable( $htaccess_file ) ) ||
			( ! file_exists( $htaccess_file ) && $wp_filesystem->is_writable( $home_path ) )
		) {
			insert_with_markers( $htaccess_file, 'MINIORANGE MEDIA RESTRICTION', array() );
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Write rules in htaccess file
	 *
	 * @since    1.1.1
	 */
	private function mo_media_restrict_write_rules() {
		global $wp_rewrite;
		$home_path      = get_home_path();
		$htaccess_file  = $home_path . '.htaccess';
		$permalink_type = get_option( 'permalink_structure' );
		$wp_filesystem  = $this->mo_media_restriction_get_filesystem();

		if ( file_exists( $htaccess_file ) && $wp_filesystem->is_writable( $home_path ) && $wp_filesystem->is_writable( $htaccess_file ) ) {
			$htaccess_file_backup = $home_path . '.htaccess-backup';
			if ( ! file_exists( $htaccess_file_backup ) ) {
				copy( $htaccess_file, $home_path . '.htaccess-backup' );
			}
			if ( empty( $permalink_type ) ) {
				$wp_rewrite->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
				flush_rewrite_rules();
			}
		} else {
			if ( $wp_filesystem->is_writable( $home_path ) ) {
				$wp_rewrite->set_permalink_structure( '/%category%/%postname%/' );
				flush_rewrite_rules();
				copy( $htaccess_file, $home_path . '.htaccess-backup' );
			} else {
				return false;
			}
		}

		$mo_media_restriction_file_types = get_option( 'mo_media_restriction_file_types' );
		if ( empty( $mo_media_restriction_file_types ) ) {
			$mo_media_restriction_file_types = 'png|jpg|gif|pdf|doc';
		}
		$restrict_option = get_option( 'mo_mr_restrict_option' );
		if ( empty( $restrict_option ) ) {
			$restrict_option = 'display-custom-page';
		}
		$redirect_to = get_option( 'mo_mr_redirect_to' );
		if ( empty( $redirect_to ) ) {
			$redirect_to = '403-forbidden-page';
		}

		$rules  = 'RewriteCond %{REQUEST_FILENAME} ^.*(' . $mo_media_restriction_file_types . ")$ [OR]\n";
		$rules .= 'RewriteCond %{REQUEST_URI} protectedfiles ';
		$rules .= "\n";
		$rules .= "RewriteCond %{HTTP_COOKIE} !^.*wordpress_logged_in.*$ [NC]\n";

		$choose_server = get_option( 'mo_media_restriction_choose_server', 'apache' );

		if ( 'godaddy' === $choose_server ) {
			$rules .= 'RewriteRule ^(.*)$ ./?mo_media_restrict_request=1&redirect_to=$1 [R=302,NC]';
		} else {
			if ( 'display-custom-page' === $restrict_option ) {
				if ( '403-forbidden-page' === $redirect_to ) {
					$rules .= 'RewriteRule . - [R=403,L]';
				} else {
					$rules .= 'RewriteRule . ./' . $redirect_to . ' [R=302,NC]';
				}
			}
		}

		if ( ! $this->mo_media_restrict_remove_rules() ) {
			return false;
		}

		return insert_with_markers( $htaccess_file, 'MINIORANGE MEDIA RESTRICTION', $rules );
	}

	/**
	 * Success Message
	 *
	 * @return void
	 */
	public function mo_media_restriction_success_message() {
		$class   = 'error';
		$message = get_option( 'mo_media_restriction_message' );
		echo "<div class='" . esc_attr( $class ) . "'> <p>" . esc_attr( $message ) . '</p></div>';
	}

	/**
	 * Error Message
	 *
	 * @return void
	 */
	public function mo_media_restriction_error_message() {
		$class   = 'updated';
		$message = get_option( 'mo_media_restriction_message' );
		echo "<div class='" . esc_attr( $class ) . "'><p>" . esc_attr( $message ) . '</p></div>';
	}

	/**
	 * Success message print
	 *
	 * @return void
	 */
	private function mo_media_restriction_show_success_message() {
		remove_action( 'admin_notices', array( $this, 'mo_media_restriction_success_message' ) );
		add_action( 'admin_notices', array( $this, 'mo_media_restriction_error_message' ) );
	}

	/**
	 * Error message print
	 *
	 * @return void
	 */
	private function mo_media_restriction_show_error_message() {
		remove_action( 'admin_notices', array( $this, 'mo_media_restriction_error_message' ) );
		add_action( 'admin_notices', array( $this, 'mo_media_restriction_success_message' ) );
	}

	/**
	 * Post request of support form.
	 *
	 * @return mixed
	 */
	public function mo_media_restrict_support() {
		if ( isset( $_POST['option'] ) ) {
			if ( current_user_can( 'administrator' ) ) {
				if ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_restriction_feedback' && isset( $_REQUEST['mo_media_restriction_feedback_fields'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_feedback_fields'] ) ), 'mo_media_restriction_feedback_form' ) ) {
					$user                      = wp_get_current_user();
					$message                   = 'Plugin Deactivated:';
					$deactivate_reason         = array_key_exists( 'mo_media_restriction_deactivate_reason_radio', $_POST ) ? sanitize_textarea_field( wp_unslash( $_POST['mo_media_restriction_deactivate_reason_radio'] ) ) : false;
					$deactivate_reason_message = array_key_exists( 'mo_media_restriction_query_feedback', $_POST ) ? sanitize_textarea_field( wp_unslash( $_POST['mo_media_restriction_query_feedback'] ) ) : false;
					if ( $deactivate_reason ) {
						$message .= $deactivate_reason;
						if ( isset( $deactivate_reason_message ) ) {
							$message .= ':' . $deactivate_reason_message;
						}
						$email = is_bool( get_option( 'mo_media_restriction_admin_email' ) ) ? '' : get_option( 'mo_media_restriction_admin_email' );
						if ( '' === $email ) {
							$email = $user->user_email;
						}
						$phone = get_option( 'mo_media_restriction_admin_phone' );
						// only reason.
						$feedback_reasons = new Miniorange_Media_Restriction_Customer();
						$submited         = $feedback_reasons->mo_media_restriction_send_email_alert( $email, $phone, $message, 'Feedback: WordPress Prevent Files / Folders Access' );

						$path = plugin_dir_path( dirname( __FILE__ ) ) . 'media-restriction.php';
						deactivate_plugins( $path );
						if ( false === $submited ) {
							update_option( 'mo_media_restriction_message', 'Your query could not be submitted. Please try again.' );
							$this->mo_media_restriction_show_error_message();
						} else {
							update_option( 'mo_media_restriction_message', 'Thanks for getting in touch! We shall get back to you shortly.' );
							$this->mo_media_restriction_show_success_message();
						}
					} else {
						update_option( 'mo_media_restriction_message', 'Please Select one of the reasons ,if your reason is not mentioned please select Other Reasons' );
						$this->mo_media_restriction_show_error_message();
					}
				} elseif ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_restriction_skip_feedback' && isset( $_REQUEST['mo_media_restriction_skip_feedback_form_fields'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_skip_feedback_form_fields'] ) ), 'mo_media_restriction_skip_feedback_form' ) ) {
					$path = plugin_dir_path( dirname( __FILE__ ) ) . 'media-restriction.php';
					deactivate_plugins( $path );
					update_option( 'mo_media_restriction_message', 'Plugin deactivated successfully' );
					$this->mo_media_restriction_show_success_message();
				}
			}
		}
	}

	/**
	 * Post data request handle for media restriction on admin side
	 *
	 * @since    1.1.1
	 */
	public function mo_media_restrict_page() {
		if ( isset( $_POST['option'] ) ) {
			if ( current_user_can( 'administrator' ) ) {
				if ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_enable_media_restriction' && isset( $_REQUEST['mo_media_restriction_enable_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_enable_field'] ) ), 'mo_media_restriction_enable_form' ) ) {
					update_option( 'mo_enable_media_restriction', isset( $_POST['mo_enable_media_restriction'] ) ? intval( $_POST['mo_enable_media_restriction'] ) : 0 );
					if ( get_option( 'mo_enable_media_restriction' ) ) {
						$upload_dir = wp_upload_dir();
						if ( $upload_dir && isset( $upload_dir['basedir'] ) ) {
							$base_upload_dir = $upload_dir['basedir'];
							$protectedfiles  = $base_upload_dir . DIRECTORY_SEPARATOR . 'protectedfiles';

							if ( ! file_exists( $protectedfiles ) && ! is_dir( $protectedfiles ) ) {
								wp_mkdir_p( $protectedfiles );
							}
						}
					} else {
						if ( ! $this->mo_media_restrict_remove_rules() ) {
							echo "<div class='mo_media_restriction_error_box'><b>Directory doesn\'t have write permissions.</b></div>";
						}
					}
				} elseif ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_restriction_file_types' && isset( $_REQUEST['mo_media_restriction_file_configuration_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_file_configuration_field'] ) ), 'mo_media_restriction_file_configuration_form' ) ) {
					$mo_media_restriction_file_types = isset( $_POST['mo_media_restriction_file_types'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mo_media_restriction_file_types'] ) ) : 0;
					$mo_media_restriction_show_rules = isset( $_POST['mo_media_restriction_show_rules'] ) ? intval( $_POST['mo_media_restriction_show_rules'] ) : 0;
					if ( empty( $mo_media_restriction_file_types ) ) {
						echo '<div class="mo_media_restriction_error_box"><b>File extension is required.</b></div>';
					} else {
						if ( is_string( $mo_media_restriction_file_types ) ) {
							$mo_media_restriction_file_types = str_replace( '{"value":"', '', $mo_media_restriction_file_types );
							$mo_media_restriction_file_types = str_replace( '"}', '', $mo_media_restriction_file_types );
							$mo_media_restriction_file_types = str_replace( '[', '', $mo_media_restriction_file_types );
							$mo_media_restriction_file_types = str_replace( ']', '', $mo_media_restriction_file_types );
							$mo_media_restriction_file_types = explode( ',', $mo_media_restriction_file_types );
							$string                          = $mo_media_restriction_file_types[0];
							$count                           = count( $mo_media_restriction_file_types );
							for ( $i = 1;$i < $count;$i++ ) {
								$string = $string . '|' . $mo_media_restriction_file_types[ $i ];
							}
							$mo_media_restriction_file_types = $string;
						}
						update_option( 'mo_media_restriction_file_types', $mo_media_restriction_file_types );
						update_option( 'mo_media_restriction_show_rules', $mo_media_restriction_show_rules );
						if ( 0 === $mo_media_restriction_show_rules ) {
							if ( get_option( 'mo_enable_media_restriction' ) ) {
								if ( $this->mo_media_restrict_write_rules() ) {
									echo "<div class='mo_media_restriction_success_box'><b>Settings saved successfully.</b></div>";
								} else {
									echo "<div class='mo_media_restriction_error_box'><b>Please give write permissions.</b></div>";
								}
							} else {
								echo "<div class='mo_media_restriction_error_box'><b>Enable media restriction for logged in user first.</b></div>";
							}
						} else {
							update_option( 'mo_media_restriction_show_rules', 2 );
						}
					}
				} elseif ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_restriction_enable' && isset( $_REQUEST['mo_media_restriction_enable_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_enable_field'] ) ), 'mo_media_restriction_media_restriction_enable_form' ) ) {
					$mo_media_restriction_show_rules = isset( $_POST['mo_media_restriction_show_rules'] ) ? intval( $_POST['mo_media_restriction_show_rules'] ) : 0;
					if ( isset( $_POST['mo_mr_restrict_option'] ) ) {
							$restrict_option = sanitize_textarea_field( wp_unslash( $_POST['mo_mr_restrict_option'] ) );
							update_option( 'mo_mr_restrict_option', $restrict_option );

						if ( 'display-custom-page' === $restrict_option ) {
							if ( isset( $_POST['mo_media_redirect_to_display_page'] ) ) {
								$redirect_to = sanitize_textarea_field( wp_unslash( $_POST['mo_media_redirect_to_display_page'] ) );
								update_option( 'mo_mr_redirect_to', $redirect_to );
							}
						}
					}

						$choose_server = isset( $_POST['choose_server'] ) ? sanitize_textarea_field( wp_unslash( $_POST['choose_server'] ) ) : 'apache';
						update_option( 'mo_media_restriction_choose_server', $choose_server );
					if ( 0 === $mo_media_restriction_show_rules ) {
						if ( get_option( 'mo_enable_media_restriction' ) ) {
							if ( $this->mo_media_restrict_write_rules() ) {
								echo "<div class='mo_media_restriction_success_box'><b>Settings saved successfully.</b></div>";
							} else {
								echo "<div class='mo_media_restriction_error_box'><b>Please give write permissions.</b></div>";
							}
						} else {
							echo "<div class='mo_media_restriction_error_box'><b>Enable media restriction for logged in user first.</b></div>";
						}
					} else {
						update_option( 'mo_media_restriction_show_rules', 2 );
					}
				} elseif ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_restriction_file_upload' && isset( $_REQUEST['mo_media_restriction_file_upload_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_file_upload_field'] ) ), 'mo_media_restriction_file_upload_form' ) ) {
					$filename            = isset( $_FILES['fileToUpload']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['fileToUpload']['name'] ) ) : '';
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name must match PHP's path byte-for-byte; wp_unslash/stripslashes breaks Windows paths (e.g. \t in \tmp\). is_uploaded_file() validates.
					$tmp_name            = isset( $_FILES['fileToUpload']['tmp_name'] ) && is_string( $_FILES['fileToUpload']['tmp_name'] ) ? $_FILES['fileToUpload']['tmp_name'] : '';
					$extension_lowercase = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
					$allowed_mime_map    = $this->mo_media_restriction_get_allowed_mime_types();
					$whitelist = array_keys( $allowed_mime_map );

					// Extension must be in the whitelist.
					if ( $this->mo_media_restriction_check_empty_or_null( $filename ) || validate_file( $filename ) || ! in_array( $extension_lowercase, $whitelist, true ) ) {
						echo '<div class="mo_media_restriction_error_box"><b>' . esc_html__( 'Invalid file name or type.', $this->plugin_name ) . '</b></div>';
					} elseif ( empty( $tmp_name ) || ! is_uploaded_file( $tmp_name ) ) {
						//Confirm this is a genuine PHP upload before inspecting content.
						echo '<div class="mo_media_restriction_error_box"><b>' . esc_html__( 'Error uploading the file.', $this->plugin_name ) . '</b></div>';
					} else {
						//Detect actual MIME type from binary magic bytes.
						$detected_mime = false;
						if ( function_exists( 'finfo_open' ) ) {
							$finfo         = finfo_open( FILEINFO_MIME_TYPE );
							$detected_mime = finfo_file( $finfo, $tmp_name );
							finfo_close( $finfo );
						} else {
							error_log( 'media-restriction.php: finfo PHP extension is not available. Upload rejected.' );
							echo '<div class="mo_media_restriction_error_box"><b>' . esc_html__( 'Server configuration error: cannot validate file type. Upload rejected.', $this->plugin_name ) . '</b></div>';
							return;
						}

						if ( false === $detected_mime || '' === $detected_mime ) {
							error_log( 'media-restriction.php: MIME detection returned empty result for upload: ' . $filename );
							echo '<div class="mo_media_restriction_error_box"><b>' . esc_html__( 'Could not determine file type. Upload rejected.', $this->plugin_name ) . '</b></div>';
						} elseif ( ! isset( $allowed_mime_map[ $extension_lowercase ] ) || ! in_array( $detected_mime, (array) $allowed_mime_map[ $extension_lowercase ], true ) ) {
							//Detected MIME must match what this extension permits.
							wp_die( 'File content does not match its extension.', 'Access Denied', array( 'response' => 403 ) );
						} else {
							// All gates passed: proceed with upload.
							$upload_dir = wp_upload_dir();
							if ( $upload_dir && isset( $upload_dir['basedir'] ) ) {
								$base_upload_dir = $upload_dir['basedir'];
								$protectedfiles  = $base_upload_dir . DIRECTORY_SEPARATOR . 'protectedfiles';
								if ( false !== $upload_dir['error'] ) {
									echo "<div class='mo_media_restriction_error_box'><b style='color:red'>" . esc_html( $upload_dir['error'] ) . '</b></div>';
								} else {
									// Check if path exists but is not a directory.
									if ( file_exists( $protectedfiles ) && ! is_dir( $protectedfiles ) ) {
										echo '<div class="mo_media_restriction_error_box"><b>' . esc_html__( 'Upload path exists but is not a directory.', $this->plugin_name ) . '</b></div>';
										return;
									}

									// Create directory if it does not exist.
									if ( ! is_dir( $protectedfiles ) && ! wp_mkdir_p( $protectedfiles ) ) {
										echo '<div class="mo_media_restriction_error_box"><b>' . esc_html__( 'Failed to create directory. Please check permissions.', $this->plugin_name ) . '</b></div>';
										return;
									}

									$target_file = $protectedfiles . DIRECTORY_SEPARATOR . basename( $filename );
									// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Slashes must be preserved for a filesystem path.
									if ( move_uploaded_file( $tmp_name, $target_file ) ) {
										echo '<div class="mo_media_restriction_success_box"><b>' . esc_html__( 'File uploaded successfully.', $this->plugin_name ) . '</b></div>';
									} else {
										echo '<div class="mo_media_restriction_error_box"><b>' . esc_html__( 'Error moving the uploaded file.', $this->plugin_name ) . '</b></div>';
									}
								}
							} else {
								echo '<div class="mo_media_restriction_error_box"><b>' . esc_html__( "Directory doesn't exist.", $this->plugin_name ) . '</b></div>';
							}
						}
					}
				} elseif ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_restriction_contact_us' && isset( $_REQUEST['mo_media_restriction_contact_us_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_contact_us_field'] ) ), 'mo_media_restriction_contact_us_form' ) ) {
					// contact us.
					if ( isset( $_POST['mo_media_restriction_contact_us_email'] ) ) {
						$email = sanitize_email( wp_unslash( $_POST['mo_media_restriction_contact_us_email'] ) );
					}
					if ( isset( $_POST['mo_media_restriction_contact_us_phone'] ) ) {
						$phone = '+ ' . preg_replace( '/[^0-9]/', '', sanitize_textarea_field( wp_unslash( $_POST['mo_media_restriction_contact_us_phone'] ) ) );
					}
					if ( isset( $_POST['mo_media_restriction_contact_us_query'] ) ) {
						$query = sanitize_textarea_field( wp_unslash( $_POST['mo_media_restriction_contact_us_query'] ) );
					}
					if ( $this->mo_media_restriction_check_empty_or_null( $email ) || $this->mo_media_restriction_check_empty_or_null( $query ) ) {
						echo '<br><b style=color:#ef2020d1>Please fill up Email and Query fields to submit your query.</b>';
					} else {
						$customer = new Miniorange_Media_Restriction_Customer();
						$submited = $customer->submit_contact_us( $email, $phone, $query );
						if ( false === $submited ) {
							echo '<div class="mo_media_restriction_error_box"><span class="icon-box-2"><img class="icon-image-2" src="' . esc_attr( plugin_dir_url( __FILE__ ) ) . '/images/mail.png" alt="error" height="20px" width = "20px"></span>
							<b>Unable to connect to Internet. Please try again.</b></div>';
						} else {
							echo '<div class="mo_media_restriction_success_box"><span class="icon-box-2"><img class="icon-image-2" src="' . esc_attr( plugin_dir_url( __FILE__ ) ) . '/images/tick.png" alt="success" height="20px" width = "20px"></span>
							<b>Thanks for your inquiry! We shall get back to you shortly.</b></div>';
						}
					}
				} elseif ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_restriction_delete_file' && isset( $_REQUEST['mo_media_restriction_delete_file_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_delete_file_field'] ) ), 'mo_media_restriction_delete_file_form' ) ) {
					$filename = isset( $_POST['mo_media_restrict_filename'] ) ? sanitize_file_name( wp_unslash( $_POST['mo_media_restrict_filename'] ) ) : '';
					if ( $this->mo_media_restriction_check_empty_or_null( $filename ) || 'none' === $filename ) {
						echo '<div class="mo_media_restriction_error_box"><b>Please select a file to submit your query.</b></div>';
					} else {
						if ( ! validate_file( $filename ) ) {
							$upload_dir = wp_upload_dir();
							if ( $upload_dir && isset( $upload_dir['basedir'] ) ) {
								$base_upload_dir = $upload_dir['basedir'];
								$protectedfiles  = $base_upload_dir . DIRECTORY_SEPARATOR . 'protectedfiles';
								if ( file_exists( $protectedfiles ) ) {
									if ( file_exists( $protectedfiles . DIRECTORY_SEPARATOR . $filename ) ) {
										wp_delete_file( $protectedfiles . DIRECTORY_SEPARATOR . $filename );
										echo '<div class="mo_media_restriction_success_box"><b>File deleted successfully.</b></div>';
									} else {
										echo '<div class="mo_media_restriction_error_box"><b>File doesn\'t exist.</b></div>';
									}
								} else {
									echo '<div class="mo_media_restriction_error_box"><b>Protected directory doesn\'t exist.</b></div>';
								}
							} else {
								echo '<div class="mo_media_restriction_error_box"><b>Upload directory doesn\'t exist.</b></div>';
							}
						} else {
							echo '<div class="mo_media_restriction_error_box"><b>Invalid file.</b></div>';
						}
					}
				} elseif ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_restriction_register_customer' && isset( $_REQUEST['mo_media_restriction_register_customer_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_register_customer_field'] ) ), 'mo_media_restriction_register_customer_form' ) ) {
					// validation and sanitization.
					$email            = '';
					$phone            = '';
					$password         = '';
					$confirm_password = '';
					$fname            = '';
					$lname            = '';
					$company          = '';
					if ( $this->mo_media_restriction_check_empty_or_null( sanitize_text_field( $_POST['mo_media_restriction_admin_email'] ) ) || $this->mo_media_restriction_check_empty_or_null( $_POST['mo_media_restriction_password'] ) || $this->mo_media_restriction_check_empty_or_null( $_POST['mo_media_restriction_confirm_password'] ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- As we are not storing password in the database, so we can ignore sanitization. Preventing use of sanitization in password will lead to removal of special characters.
						echo '<div class="mo_media_restriction_error_box"><b>All the fields are required. Please enter valid entries.</b></div>';
					} elseif ( strlen( $_POST['mo_media_restriction_password'] ) < 8 || strlen( $_POST['mo_media_restriction_confirm_password'] ) < 8 ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- As we are not storing password in the database, so we can ignore sanitization. Preventing use of sanitization in password will lead to removal of special characters.
						echo '<div class="mo_media_restriction_error_box"><b>Choose a password with minimum length 8.</b></div>';
					} else {
						$email            = ! empty( $_POST['mo_media_restriction_admin_email'] ) ? sanitize_email( wp_unslash( $_POST['mo_media_restriction_admin_email'] ) ) : '';
						$phone            = '';
						$password         = wp_unslash( $_POST['mo_media_restriction_password'] ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- As we are not storing password in the database, so we can ignore sanitization. Preventing use of sanitization in password will lead to removal of special characters.
						$confirm_password = wp_unslash( $_POST['mo_media_restriction_confirm_password'] ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- As we are not storing password in the database, so we can ignore sanitization. Preventing use of sanitization in password will lead to removal of special characters.
						$fname            = '';
						$lname            = '';
						$company          = '';
						update_option( 'mo_media_restriction_admin_email', $email );
						update_option( 'mo_media_restriction_admin_phone', $phone );
						update_option( 'mo_media_restriction_admin_fname', $fname );
						update_option( 'mo_media_restriction_admin_lname', $lname );
						update_option( 'mo_media_restriction_admin_company', $company );

						if ( 0 === strcmp( $password, $confirm_password ) ) {
							$customer = new Miniorange_Media_Restriction_Customer();
							$email    = get_option( 'mo_media_restriction_admin_email' );
							$content  = json_decode( $customer->check_customer(), true );

							if ( 0 === strcasecmp( $content['status'], 'CUSTOMER_NOT_FOUND' ) ) {
								$response = json_decode( $customer->create_customer( $password ), true );

								if ( 0 !== strcasecmp( $response['status'], 'SUCCESS' ) ) {
									echo '<div class="mo_media_restriction_error_box"><b>Failed to create customer. Try again.</b></div>';
								} else {
									echo '<div class="mo_media_restriction_success_box"><b>' . esc_attr( $response['message'] ) . '.</b></div>';
									update_option( 'mo_media_restriction_new_user', 'login' );
								}
							} elseif ( 0 === strcasecmp( $content['status'], 'SUCCESS' ) ) {
								update_option( 'mo_media_restriction_new_user', 'login' );
								echo '<div class="mo_media_restriction_error_box"><b>Account already exist. Please Login.</b></div>';
							} elseif ( is_null( $content ) ) {
								echo '<div class="mo_media_restriction_error_box"><b>Failed to create customer. Try again.</b></div>';
							} else {
								echo '<div class="mo_media_restriction_error_box"><b>' . esc_attr( $content['message'] ) . '.</b></div>';
							}
						} else {
							echo '<div class="mo_media_restriction_error_box"><b>Passwords do not match.</b></div>';
						}
					}
				} elseif ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_restriction_login_customer' && isset( $_REQUEST['mo_media_restriction_login_customer_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_login_customer_field'] ) ), 'mo_media_restriction_login_customer_form' ) ) {
					// validation and sanitization.
					$email    = '';
					$password = '';
					if ( $this->mo_media_restriction_check_empty_or_null( sanitize_text_field( $_POST['mo_media_restriction_admin_email'] ) ) || $this->mo_media_restriction_check_empty_or_null( $_POST['mo_media_restriction_password'] ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- As we are not storing password in the database, so we can ignore sanitization. Preventing use of sanitization in password will lead to removal of special characters.
						echo '<div class="mo_media_restriction_error_box"><b>All the fields are required. Please enter valid entries.</b></div>';
					} else {
						$email    = sanitize_email( wp_unslash( $_POST['mo_media_restriction_admin_email'] ) );
						$password = wp_unslash( $_POST['mo_media_restriction_password'] ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- As we are not storing password in the database, so we can ignore sanitization. Preventing use of sanitization in password will lead to removal of special characters.

						update_option( 'mo_media_restriction_admin_email', $email );
						$customer     = new Miniorange_Media_Restriction_Customer();
						$content      = $customer->get_customer_key( $password );
						$customer_key = json_decode( $content, true );
						if ( json_last_error() === JSON_ERROR_NONE && isset( $customer_key['status'] ) && 'SUCCESS' === $customer_key['status'] ) {
							update_option( 'mo_media_restriction_admin_customer_key', $customer_key['id'] );
							update_option( 'mo_media_restriction_admin_api_key', $customer_key['apiKey'] );
							update_option( 'customer_token', $customer_key['token'] );
							update_option( 'mo_media_restriction_admin_phone', isset( $customer_key['phone'] ) ? $customer_key['phone'] : '' );
							delete_option( 'password' );
							update_option( 'mo_media_restriction_new_user', 'account-setup' );
							echo '<div class="mo_media_restriction_success_box"><b>Customer retrieved successfully.</b></div>';
						} else {
							echo '<div class="mo_media_restriction_error_box"><b>Invalid username or password. Please try again.</b></div>';
						}
					}
				} elseif ( sanitize_text_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_r_demo_request_form' && isset( $_REQUEST['mo_media_r_demo_request_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_r_demo_request_field'] ) ), 'mo_media_r_demo_request_form' ) ) {
					$email     = ! empty( $_POST['mo_auto_create_demosite_email'] ) ? sanitize_email( wp_unslash( $_POST['mo_auto_create_demosite_email'] ) ) : '';
					$demo_plan = ! empty( $_POST['mo_auto_create_demosite_demo_plan'] ) ? sanitize_text_field( wp_unslash( $_POST['mo_auto_create_demosite_demo_plan'] ) ) : '';
					$query     = ! empty( $_POST['mo_auto_create_demosite_usecase'] ) ? sanitize_text_field( wp_unslash( $_POST['mo_auto_create_demosite_usecase'] ) ) : '';
					if ( $this->mo_media_restriction_check_empty_or_null( $email ) || $this->mo_media_restriction_check_empty_or_null( $demo_plan ) || $this->mo_media_restriction_check_empty_or_null( $query ) ) {
						echo '<div class="mo_media_restriction_error_box"><b>All the fields are required. Please enter valid entries.</b></div>';
					} else {
						$customer = new Miniorange_Media_Restriction_Customer();
						$submited = $customer->mo_api_auth_send_demo_alert( $email, $demo_plan, $query, 'Trial: WordPress Prevent Files / Folders Access - ' . $email );
						if ( false === $submited ) {
							echo '<div class="mo_media_restriction_error_box"><b>Your query could not be submitted. Please try again.</b></div>';
						} else {
							echo '<div class="mo_media_restriction_success_box"><b>Thanks for getting in touch! We shall get back to you shortly.</b></div>';
						}
					}
				} elseif ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_restriction_change_to_login' && isset( $_REQUEST['mo_media_restriction_change_to_login_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_change_to_login_field'] ) ), 'mo_media_restriction_change_to_login' ) ) {
					// validation and sanitization.
					delete_option( 'mo_media_restriction_admin_customer_key' );
					delete_option( 'mo_media_restriction_admin_api_key' );
					delete_option( 'customer_token' );
					delete_option( 'mo_media_restriction_admin_phone' );
					delete_option( 'mo_media_restriction_admin_email' );
					update_option( 'mo_media_restriction_new_user', 'login' );
				} elseif ( sanitize_textarea_field( wp_unslash( $_POST['option'] ) ) === 'mo_media_restriction_change_to_register' && isset( $_REQUEST['mo_media_restriction_change_to_register_field'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mo_media_restriction_change_to_register_field'] ) ), 'mo_media_restriction_change_to_register' ) ) {
					// validation and sanitization.
					delete_option( 'mo_media_restriction_admin_customer_key' );
					delete_option( 'mo_media_restriction_admin_api_key' );
					delete_option( 'customer_token' );
					delete_option( 'mo_media_restriction_admin_phone' );
					delete_option( 'mo_media_restriction_admin_email' );
					update_option( 'mo_media_restriction_new_user', 'register' );
				} else {
					echo '<div class="mo_media_restriction_error_box"><b>Something went wrong please try again later.</b></div>';
				}
			}
		}

		mo_media_restrict_page_ui();
	}

	/**
	 * Nginx rules
	 */
	public function mo_media_restrict_write_nginx_rules() {
		$rule  = '';
		$rule .= 'location ~ .*(/protectedfiles) {<br>';
		$rule .= '&nbsp&nbsp&nbspreturn 301 $scheme://$http_host/?mo_media_restrict_request=1&redirect_to=$request_uri;<br>';
		$rule .= '&nbsp&nbsp&nbsp}<br>';
		return $rule;
	}

	/**
	 * Generate secure URL for file access with proper nonce
	 *
	 * @param string $file_path The file path to generate URL for.
	 * @return string The secure URL with nonce
	 */
	public function mo_media_restriction_generate_secure_url( $file_path ) {
		$file_path = sanitize_text_field( $file_path );

		$nonce = wp_create_nonce( 'mo_media_restriction_access' );

		$secure_url = add_query_arg(
			array(
				'mo_media_restrict_request' => '1',
				'redirect_to'               => $file_path,
				'_wpnonce'                  => $nonce,
			),
			home_url()
		);

		return $secure_url;
	}

}
