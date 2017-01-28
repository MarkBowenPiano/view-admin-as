<?php
/**
 * View Admin As - Role Defaults Module
 *
 * @author  Jory Hogeveen <info@keraweb.nl>
 * @package View_Admin_As
 */

! defined( 'VIEW_ADMIN_AS_DIR' ) and die( 'You shall not pass!' );

if ( ! class_exists( 'VAA_View_Admin_As_Role_Defaults' ) ) {

add_action( 'vaa_view_admin_as_modules_loaded', array( 'VAA_View_Admin_As_Role_Defaults', 'get_instance' ) );

/**
 * Set default screen settings for roles and apply them on users through various bulk actions.
 *
 * @author  Jory Hogeveen <info@keraweb.nl>
 * @package View_Admin_As
 * @since   1.4
 * @version 1.6.4
 * @uses    VAA_View_Admin_As_Class_Base Extends class
 */
final class VAA_View_Admin_As_Role_Defaults extends VAA_View_Admin_As_Class_Base
{
	/**
	 * The single instance of the class.
	 *
	 * @since  1.5
	 * @static
	 * @var    VAA_View_Admin_As_Role_Defaults
	 */
	private static $_instance = null;

	/**
	 * Option key.
	 *
	 * @since  1.4
	 * @var    string
	 */
	protected $optionKey = 'vaa_role_defaults';

	/**
	 * Array of meta strings that influence the screen settings.
	 *
	 * @since  1.4
	 * @see    $meta_default
	 * @var    array
	 */
	private $meta = array();

	/**
	 * Array of default meta strings.
	 * %% stands for a wildcard and can be anything.
	 *
	 * @since  1.4
	 * @since  1.5.2  Set both values and keys to fix problem with unsetting a key through the filter.
	 * @var    array
	 */
	private $meta_default = array(
		'admin_color'            => true,  // The admin color.
		'rich_editing'           => true,  // Enable/Disable rich editing.
		'metaboxhidden_%%'       => true,  // Hidden metaboxes.
		'meta-box-order_%%'      => true,  // Metabox order and locations.
		'closedpostboxes_%%'     => true,  // Hidden post boxes.
		'edit_%%_per_page'       => true,  // Amount of items per page in edit pages (overview).
		'manage%%columnshidden'  => true,  // Hidden columns in overview pages.
		'screen_layout_%%'       => true,  // Screen layout (num of columns).
	);

	/**
	 * Array of forbidden meta strings.
	 * %% gets replaced with the table prefix and added to this array on class construction.
	 *
	 * @since  1.5.2
	 * @var    array
	 */
	private $meta_forbidden = array(
		'vaa-view-admin-as',  // Meta value for this plugin.
		'%%capabilities',     // User capabilities.
		'%%user_level',       // User user level.
		'session_tokens',     // User session tokens.
		'nickname',           // User nickname.
		'first_name',         // User first name.
		'last_name',          // User last name.
		'description',        // User description.
	);

	/**
	 * Construct function.
	 * Protected to make sure it isn't declared elsewhere.
	 *
	 * @since   1.4
	 * @since   1.6.1  $vaa param
	 * @access  protected
	 * @param   VAA_View_Admin_As  $vaa  The main VAA object.
	 */
	protected function __construct( $vaa ) {
		self::$_instance = $this;
		parent::__construct( $vaa );

		// Add this class to the modules in the main class.
		$this->vaa->register_module( array(
			'id'       => 'role_defaults',
			'instance' => self::$_instance,
		) );

		/**
		 * Add capabilities for this module.
		 *
		 * @since 1.6
		 */
		$this->capabilities = array( 'view_admin_as_role_defaults' );
		add_filter( 'view_admin_as_add_capabilities', array( $this, 'add_capabilities' ) );

		// Load data.
		$this->set_optionData( get_option( $this->get_optionKey() ) );

		/**
		 * Checks if the management part of module should be enabled.
		 *
		 * @since  1.4    Validate option data.
		 * @since  1.6    Also calls init().
		 */
		if ( $this->get_optionData( 'enable' ) ) {
			$this->enable = true;
			$this->init();
		}

		/**
		 * Only allow settings for admin users or users with the correct capabilities.
		 *
		 * @since  1.5.2    Validate custom capability view_admin_as_role_defaults.
		 * @since  1.5.2.1  Validate is_super_admin (bug in 1.5.2).
		 * @since  1.5.3    Disable for network pages.
		 */
		if (   $this->is_vaa_enabled()
			&& ! is_network_admin()
			&& ( VAA_API::is_super_admin() || current_user_can( 'view_admin_as_role_defaults' ) )
		) {
			add_action( 'vaa_view_admin_as_init', array( $this, 'vaa_init' ) );
		}
	}

	/**
	 * Init function for global functions (not user dependent).
	 *
	 * @since   1.4
	 * @access  private
	 * @global  wpdb  $wpdb
	 * @return  void
	 */
	private function init() {
		global $wpdb;

		/**
		 * Replace %% with the current table prefix and add it to the array of forbidden meta keys.
		 *
		 * @since 1.5.2
		 */
		foreach ( $this->meta_forbidden as $key => $meta_key ) {
			if ( false !== strpos( $meta_key, '%%' ) ) {
				$this->meta_forbidden[] = str_replace( '%%', (string) $wpdb->prefix, $meta_key );
			}
		}
		/**
		 * Allow users to overwrite the default meta keys.
		 *
		 * @since   1.4
		 * @param   array  $meta  Default metadata.
		 * @return  array  $meta
		 */
		$this->meta_default = $this->validate_meta( apply_filters( 'view_admin_as_role_defaults_meta', $this->meta_default ) );

		/**
		 * Get metakeys optionData and merge it with the default meta.
		 *
		 * @since  1.6.3
		 */
		$this->set_meta( array_merge(
			$this->meta_default,
			( $this->get_optionData( 'meta' ) ) ? (array) $this->get_optionData( 'meta' ) : array()
		) );

		// Setting: Automatically apply defaults to new users.
		if ( $this->get_optionData( 'apply_defaults_on_register' ) ) {
			if ( is_multisite() ) {
				add_action( 'add_user_to_blog', array( $this, 'update_user_with_role_defaults_multisite_register' ), 100, 3 );
			} else {
				add_action( 'user_register', array( $this, 'update_user_with_role_defaults' ), 100, 1 );
			}
		}

		// Setting: Hide the screen options for all users who can't access role defaults.
		if ( $this->get_optionData( 'disable_user_screen_options' )
			&& ! ( $this->is_vaa_enabled() && ( VAA_API::is_super_admin() || current_user_can( 'view_admin_as_role_defaults' ) ) )
		) {
			add_filter( 'screen_options_show_screen', '__return_false', 100 );
		}

		/**
		 * Print script in the admin header.
		 * Also handles the lock_meta_boxes setting.
		 *
		 * @since  1.6
		 * @since  1.6.2  Move to footer (changed hook).
		 */
		add_action( 'admin_print_footer_scripts', array( $this, 'admin_print_footer_scripts' ), 100 );
	}

	/**
	 * init function to store data from the main class and enable functionality based on the current view.
	 *
	 * @since   1.4
	 * @access  public
	 * @return  void
	 */
	public function vaa_init() {

		// Enabling this module can only be done by a super admin.
		if ( VAA_API::is_super_admin() ) {

			// Add adminbar menu items in settings section.
			add_action( 'vaa_admin_bar_settings_after', array( $this, 'admin_bar_menu_settings' ), 10, 2 );
		}

		// Add adminbar menu items in role section.
		if ( $this->is_enabled() ) {

			// Enable storage of role default settings.
			$this->init_store_role_defaults();

			// Show the admin bar node.
			add_action( 'vaa_admin_bar_menu', array( $this, 'admin_bar_menu' ), 5, 2 );
		}
	}

	/**
	 * Print scripts in the admin section.
	 *
	 * @since   1.6
	 * @access  public
	 */
	public function admin_print_footer_scripts() {

		/**
		 * Setting: Lock meta box order and locations for all users who can't access role defaults.
		 *
		 * @since  1.6
		 * @since  1.6.2  Improved conditions + check if sortable is enqueued and active.
		 */
		if ( $this->get_optionData( 'lock_meta_boxes' )
		     && ! ( $this->is_vaa_enabled() || VAA_API::is_super_admin() || current_user_can( 'view_admin_as_role_defaults' ) )
		     && wp_script_is( 'jquery-ui-sortable', 'enqueued' )
		) {
			?>
			<script type="text/javascript">
				jQuery(document).ready( function($) {
					if ( $.fn.sortable && $('.ui-sortable').length ) {
						/**
						 * Lock meta boxes in position by disabling sorting.
						 *
						 * Credits - Chris Van Patten:
						 * http://wordpress.stackexchange.com/a/44539
						 */
						$('.meta-box-sortables').sortable( { disabled: true } );
						$('.postbox .hndle').css( 'cursor', 'pointer' );
					}
				});
			</script>
			<?php
		}
	}

	/**
	 * Get the metadata for meta compare.
	 *
	 * @since   1.5
	 * @access  private
	 * @return  array   $this->meta  The meta keys.
	 */
	private function get_meta() {
		return $this->meta;
	}

	/**
	 * Set the metadata for meta compare.
	 * Used to enforce only 1 level depth array of strings.
	 *
	 * @since   1.5
	 * @access  private
	 * @param   array   $var  The new meta keys.
	 * @return  void
	 */
	private function set_meta( $var ) {
		if ( is_array( $var ) ) {
			$this->meta = array_merge( $this->meta_default, $this->validate_meta( $var ) );
			ksort( $this->meta );
		}
	}

	/**
	 * Validates meta keys in case forbidden or invalid meta keys are added.
	 *
	 * @since   1.5.2
	 * @access  private
	 * @param   array   $metas  The meta keys.
	 * @return  array
	 */
	private function validate_meta( $metas ) {
		if ( is_array( $metas ) ) {
			foreach ( $metas as $meta_key => $meta_value ) {
				// Remove forbidden or invalid meta keys.
				if (   in_array( $meta_key, $this->meta_forbidden, true )
					|| strpos( $meta_key, ' ' ) !== false
					|| ! is_string( $meta_key )
				) {
					unset( $metas[ $meta_key ] );
					continue;
				}
				// Validate meta value.
				$metas[ $meta_key ] = (bool) $meta_value;
			}
			return $metas;
		}
		return array();
	}

	/**
	 * Ajax handler, called from main ajax handler.
	 *
	 * @since   1.4
	 * @access  public
	 * @param   array  $data  The ajax data for this module.
	 * @return  array|string|bool
	 */
	public function ajax_handler( $data ) {

		if (   ! defined( 'VAA_DOING_AJAX' )
		    || ! VAA_DOING_AJAX
		    || ! $this->is_vaa_enabled()
		) {
			return false;
		}

		$success = false;

		// Validate super admin.
		if ( VAA_API::is_super_admin() ) {

			if ( isset( $data['enable'] ) ) {
				if ( $data['enable'] ) {
					$success = $this->set_enable( true );
				} else {
					$success = $this->set_enable( false );
				}
				// Prevent further processing.
				return $success;
			}

		}

		// From here all features need this module enabled.
		if ( ! $this->is_enabled() ) {
			return $success;
		}

		if ( isset( $data['apply_defaults_on_register'] ) ) {
			$value = (bool) $data['apply_defaults_on_register'];
			$success = $this->update_optionData( $value, 'apply_defaults_on_register', true );
		}
		if ( isset( $data['disable_user_screen_options'] ) ) {
			$value = (bool) $data['disable_user_screen_options'];
			$success = $this->update_optionData( $value, 'disable_user_screen_options', true );
		}
		if ( isset( $data['lock_meta_boxes'] ) ) {
			$value = (bool) $data['lock_meta_boxes'];
			$success = $this->update_optionData( $value, 'lock_meta_boxes', true );
		}
		// @since  1.6.3  Update metakeys.
		if ( isset( $data['update_meta'] ) ) {
			$value = $this->validate_meta( $data['update_meta'] );
			if ( ! empty( $value ) ) {
				if ( $value === $this->get_optionData( 'meta' ) ) {
					// Data is the same, no changes needed.
					$success = true;
				} else {
					$success = $this->update_optionData( $value, 'meta', true );
				}
			} else {
				$success = false; // @todo Notify for invalid data.
			}
		}
		if ( isset( $data['apply_defaults_to_users'] ) && is_array( $data['apply_defaults_to_users'] ) ) {
			foreach ( $data['apply_defaults_to_users'] as $user_data ) {
				// @todo Send as JSON?
				// @todo notify of errors in updates.
				$user_data = explode( '|', $user_data );
				if ( is_numeric( $user_data[0] ) && isset( $user_data[1] ) && is_string( $user_data[1] ) ) {
					$success = $this->update_user_with_role_defaults( intval( $user_data[0] ), $user_data[1] );
				}
			}
		}
		if ( isset( $data['apply_defaults_to_users_by_role'] ) && is_string( $data['apply_defaults_to_users_by_role'] ) ) {
			// @todo notify of errors in updates.
			$success = $this->apply_defaults_to_users_by_role( strip_tags( $data['apply_defaults_to_users_by_role'] ) );
		}
		if ( isset( $data['clear_role_defaults'] ) && is_string( $data['clear_role_defaults'] ) ) {
			$success = $this->clear_role_defaults( strip_tags( $data['clear_role_defaults'] ) );
		}

		if ( isset( $data['export_role_defaults'] ) && is_string( $data['export_role_defaults'] ) ) {
			$content = $this->export_role_defaults( strip_tags( $data['export_role_defaults'] ) );
			if ( is_array( $content ) ) {
				wp_send_json_success( array(
					'type' => 'textarea',
					'content' => array(
						'text' => esc_attr__( 'Copy code', VIEW_ADMIN_AS_DOMAIN ) . ': ',
						'textareacontent' => json_encode( $content ),
					),
				) );
			} else {
				wp_send_json_error( array( 'type' => 'error', 'content' => $content ) );
			}
			die();
		}
		if ( isset( $data['import_role_defaults'] ) && is_array( $data['import_role_defaults'] ) ) {
			$method = ( ! empty( $data['import_role_defaults_method'] ) ) ? (string) $data['import_role_defaults_method'] : '';
			// $content format: array( 'text' => **text**, 'errors' => **error array** ).
			$content = $this->import_role_defaults( $data['import_role_defaults'], $method );
			if ( true === $content ) {
				wp_send_json_success();
			} else {
				wp_send_json_success( array( 'type' => 'errorlist', 'content' => $content ) );
			}
			die();
		}

		return $success;
	}

	/**
	 * Update user settings with the a role default.
	 * When no role is provided this function only checks the first existing user role. If the user has multiple roles, the other roles are ignored.
	 *
	 * @since   1.4
	 * @access  public
	 * @see     update_user_with_role_defaults_multisite_register()
	 * @see     apply_defaults_to_users_by_role()
	 * @see     ajax_handler()
	 *
	 * @see     'user_register' action
	 * @link    https://developer.wordpress.org/reference/hooks/user_register/
	 *
	 * @param   int     $user_id  The user ID.
	 * @param   string  $role     (optional) The user role name.
	 * @param   int     $blog_id  (optional) The blog ID.
	 * @return  bool
	 */
	public function update_user_with_role_defaults( $user_id, $role = null, $blog_id = null ) {
		$success = true;
		$user = get_user_by( 'id', $user_id );
		if ( $user ) {
			if ( is_numeric( $blog_id ) ) {
				$option_data = get_blog_option( $blog_id, $this->get_optionKey() );
			} else {
				$option_data = get_option( $this->get_optionKey() );
			}
			// If no role was set, use the first role found for this user.
			if ( ! $role && isset( $user->roles[0] ) ) {
				$role = $user->roles[0];
			}
			if ( $role && $option_data ) {
				if ( isset( $option_data['roles'][ $role ] ) ) {
					foreach ( $option_data['roles'][ $role ] as $meta_key => $meta_value ) {
						update_user_meta( $user_id, $meta_key, $meta_value );
						// Do not return update_user_meta results since it's highly possible to be false (values are often the same).
						// @todo check other way of validation
					}
				}
			}
		}
		return $success;
	}

	/**
	 * In case of a multisite register, check if the user has multiple blogs.
	 * If true, it is an existing user and it will not get the role defaults.
	 * If false, it is most likely a new user and it will get the role defaults.
	 *
	 * @since   1.4
	 * @access  public
	 * @see     'add_user_to_blog' action
	 * @link    https://developer.wordpress.org/reference/hooks/add_user_to_blog/
	 *
	 * @param   int     $user_id  The user ID.
	 * @param   string  $role     The user role name.
	 * @param   int     $blog_id  The blog ID.
	 * @return  bool
	 */
	public function update_user_with_role_defaults_multisite_register( $user_id, $role, $blog_id ) {
		$user_blogs = get_blogs_of_user( $user_id );
		if ( 1 === count( $user_blogs ) ) {
			// If the user has access to one blog only it is safe to set defaults since it is most likely a new user.
			return $this->update_user_with_role_defaults( $user_id, $role, $blog_id );
		}
		return false;
	}

	/**
	 * Apply default settings to all users of a role.
	 *
	 * @since   1.4
	 * @access  private
	 * @param   string  $role  The role name.
	 * @return  bool
	 */
	private function apply_defaults_to_users_by_role( $role ) {
		$success = true;
		$roles = array();
		if ( is_array( $role ) ) {
			foreach ( $role as $role_name ) {
				if ( array_key_exists( $role_name, $this->store->get_roles() ) ) {
					$roles[] = $role_name;
				}
			}
		} else {
			if ( array_key_exists( $role, $this->store->get_roles() ) ) {
				$roles[] = $role;
			} elseif ( 'all' === $role ) {
				foreach ( $this->store->get_roles() as $role_name => $val ) {
					$roles[] = $role_name;
				}
			}
		}
		if ( ! empty( $roles ) ) {
			foreach ( $roles as $role ) {
				$users = get_users( array( 'role' => $role ) );
				if ( ! empty( $users ) ) {
					foreach ( $users as $user ) {
						$success = $this->update_user_with_role_defaults( $user->ID, $role );
						// @todo notify of errors in updates.
					}
				}
			}
		}
		return $success;
	}

	/**
	 * Initialize the sync functionality (store defaults).
	 * Init function/action to load necessary data and register all used hooks.
	 * IMPORTANT! This function should ONLY be used when a role view is selected!
	 *
	 * @since   1.4
	 * @access  private
	 * @see     vaa_init()
	 * @return  void
	 */
	private function init_store_role_defaults() {
		if ( $this->store->get_viewAs( 'role' ) && $this->is_enabled() ) {
			add_filter( 'get_user_metadata' , array( $this, 'filter_get_user_metadata' ), 10, 4 );
			add_filter( 'update_user_metadata' , array( $this, 'filter_update_user_metadata' ), 10, 5 );
		}
	}

	/**
	 * Check if the meta_key matches one of the predefined metakeys in the role defaults.
	 * If there is a match and the role default value is set, return this value instead of the current user value.
	 *
	 * IMPORTANT! This filter should ONLY be used when a role view is selected!
	 *
	 * @since   1.4
	 * @since   1.5.3   Stop checking $single parameter.
	 * @access  public
	 * @see     init_store_role_defaults()
	 *
	 * @see     'get_user_metadata' filter
	 * @link    https://codex.wordpress.org/Plugin_API/Filter_Reference/get_(meta_type)_metadata
	 * @link    http://hookr.io/filters/get_user_metadata/
	 *
	 * @param   null    $null       The value update_metadata() should return.
	 * @param   int     $object_id  Object ID.
	 * @param   string  $meta_key   Meta key.
	 * param   bool    $single     (not used) Return a single value or an array?
	 * @return  mixed
	 */
	public function filter_get_user_metadata( $null, $object_id, $meta_key ) {
		if ( true === $this->compare_metakey( $meta_key ) && (int) $object_id === (int) $this->store->get_curUser()->ID ) {
			$new_meta = $this->get_role_defaults( $this->store->get_viewAs( 'role' ), $meta_key );
			// Do not check $single, this logic is in wp-includes/meta.php line 487.
			return array( $new_meta );
		}
		return $null; // Go on as normal.
	}

	/**
	 * Check if the meta_key matches one of the predefined metakeys to store as defaults.
	 * If there is a match, store the update to the defaults and cancel the update for the current user.
	 *
	 * IMPORTANT! This filter should ONLY be used when a role view is selected!
	 *
	 * @since   1.4
	 * @access  public
	 * @see     init_store_role_defaults()
	 *
	 * @see     'update_user_metadata' filter
	 * @link    https://codex.wordpress.org/Plugin_API/Filter_Reference/update_(meta_type)_metadata
	 * @link    http://hookr.io/filters/update_user_metadata/
	 *
	 * @param   null    $null        Whether to allow updating metadata for the given type.
	 * @param   int     $object_id   Object ID.
	 * @param   string  $meta_key    Meta key.
	 * @param   string  $meta_value  Meta value.
	 * param   string  $prev_value  (not used) Previous meta value.
	 * @return  mixed
	 */
	public function filter_update_user_metadata( $null, $object_id, $meta_key, $meta_value ) {
		if ( true === $this->compare_metakey( $meta_key ) && (int) $object_id === (int) $this->store->get_curUser()->ID ) {
			$this->update_role_defaults( $this->store->get_viewAs( 'role' ), $meta_key, $meta_value );
			return false; // Do not update current user meta.
		}
		return $null; // Go on as normal.
	}

	/**
	 * Get defaults of a role.
	 *
	 * @since   1.4
	 * @since   1.6.3  Multiple get methods (parameters are now optional).
	 * @access  private
	 *
	 * @param   string  $role      Role name.
	 * @param   string  $meta_key  Meta key.
	 * @return  mixed
	 */
	private function get_role_defaults( $role = null, $meta_key = null ) {
		$defaults = $this->get_optionData( 'roles' );
		if ( $role && $meta_key && isset( $defaults[ $role ][ $meta_key ] ) ) {
			return $defaults[ $role ][ $meta_key ];
		}
		elseif ( $role && null === $meta_key && isset( $defaults[ $role ] ) ) {
			return $defaults[ $role ];
		}
		elseif ( null === $role && null === $meta_key ) {
			return $defaults;
		}
		return false;
	}

	/**
	 * Update a role with new defaults.
	 *
	 * @since   1.4
	 * @access  private
	 *
	 * @param   string  $role        Role name.
	 * @param   string  $meta_key    Meta key.
	 * @param   string  $meta_value  Meta value.
	 * @return  bool
	 */
	private function update_role_defaults( $role, $meta_key, $meta_value ) {
		$role_defaults = $this->get_role_defaults();
		if ( ! isset( $role_defaults[ $role ] ) ) {
			$role_defaults[ $role ] = array();
		}
		$role_defaults[ $role ][ $meta_key ] = $meta_value;
		return $this->update_optionData( $role_defaults, 'roles', true );
	}

	/**
	 * Remove defaults of a role.
	 *
	 * @since   1.4
	 * @access  private
	 * @param   string|array  $role  Role name or array of role names or just "all" for all roles.
	 * @return  bool
	 */
	private function clear_role_defaults( $role ) {
		$role_defaults = $this->get_role_defaults();
		if ( ! is_array( $role ) ) {
			if ( isset( $role_defaults ) && 'all' === $role ) {
				$role_defaults = array();
			} else {
				$roles = array( $role );
			}
		} else {
			$roles = $role;
		}
		if ( isset( $roles ) ) {
			foreach ( $roles as $role ) {
				if ( isset( $role_defaults[ $role ] ) ) {
					unset( $role_defaults[ $role ] );
				}
			}
		}
		if ( $this->get_role_defaults() !== $role_defaults ) {
			return $this->update_optionData( $role_defaults, 'roles' );
		}
		return true; // No changes needed.
	}

	/**
	 * Export role defaults.
	 *
	 * @since   1.5
	 * @access  private
	 * @param   string  $role  Role name or "all" for all roles.
	 * @return  mixed
	 */
	private function export_role_defaults( $role = 'all' ) {
		$role_defaults = $this->get_role_defaults();
		if ( 'all' !== $role && isset( $role_defaults[ $role ] ) ) {
			$data = $role_defaults[ $role ];
			$data = array( $role => $data );
		} elseif ( 'all' === $role && ! empty( $role_defaults ) ) {
			$data = $role_defaults;
		} else {
			$data = esc_attr__( 'No valid data found', VIEW_ADMIN_AS_DOMAIN );
		}
		return $data;
	}

	/**
	 * Import role defaults.
	 *
	 * @since   1.5
	 * @since   1.6.2  Add extra import methods
	 * @access  private
	 * @param   array   $data    Data to import.
	 * @param   string  $method  Import method.
	 * @return  mixed
	 */
	private function import_role_defaults( $data, $method = 'import' ) {
		$new_defaults = array();
		$error_list   = array();
		if ( empty( $data ) || ! is_array( $data ) ) {
			return array( 'text' => esc_attr__( 'No valid data found', VIEW_ADMIN_AS_DOMAIN ) );
		}
		foreach ( $data as $role => $role_data ) {
			// Make sure the role exists.
			if ( array_key_exists( $role, $this->store->get_roles() ) ) {
				// Add the role to the new defaults.
				$new_defaults[ $role ] = array();
				foreach ( $role_data as $data_key => $data_value ) {
					// Make sure the import data are valid meta keys.
					if ( true === $this->compare_metakey( $data_key ) ) {
						// Add the key and data.
						$new_defaults[ $role ][ $data_key ] = $data_value;
					} else {
						$error_list[] = esc_attr__( 'Key not allowed', VIEW_ADMIN_AS_DOMAIN ) . ': ' . $data_key;
					}
				}
			} else {
				$error_list[] = esc_attr__( 'Role not found', VIEW_ADMIN_AS_DOMAIN ) . ': ' . $role;
			}
		}
		if ( ! empty( $new_defaults ) ) {
			$role_defaults = $this->get_role_defaults();
			foreach ( $new_defaults as $role => $role_data ) {
				if ( empty( $role_defaults[ $role ] ) ) {
					$role_defaults[ $role ] = array();
				}
				// @since  1.6.2  Multiple import methods.
				switch ( $method ) {
					case 'overwrite':
						// Overwrite the existing data, keep data that don't exist in the import.
						$role_defaults[ $role ] = array_merge( $role_defaults[ $role ], $role_data );
					break;
					case 'append':
						// Append new data without overwriting the existing data.
						$role_defaults[ $role ] = array_merge( $role_data, $role_defaults[ $role ] );
					break;
					case 'import':
					default:
						// Fully Overwrite data for each supplied role.
						$role_defaults[ $role ] = $role_data;
					break;
				}
			}
			$this->update_optionData( $role_defaults, 'roles', true );
			if ( ! empty( $error_list ) ) {
				// Close enough!
				return array(
					'text'   => esc_attr__( 'Data imported but there were some errors', VIEW_ADMIN_AS_DOMAIN ) . ':',
					'errors' => $error_list,
				);
			}
			return true; // Yay!
		}
		// Nope..
		return array(
			'text'   => esc_attr__( 'No valid data found', VIEW_ADMIN_AS_DOMAIN ) . ':',
			'errors' => $error_list,
		);
	}

	/**
	 * Match the meta key with predefined metakeys.
	 * %% stands for a wildcard. This function only supports one wildcard!
	 *
	 * @since   1.4
	 * @access  private
	 * @param   string  $meta_key_compare  Meta key.
	 * @return  bool
	 */
	private function compare_metakey( $meta_key_compare ) {
		$meta_keys = $this->get_meta();
		if ( is_array( $meta_keys ) ) {
			foreach ( $meta_keys as $meta_key => $meta_value ) {
				if ( empty( $meta_value ) || ! is_string( $meta_key ) ) {
					continue;
				} else {
					$meta_key_parts = explode( '%%', $meta_key );

					$compare_start = true;
					if ( ! empty( $meta_key_parts[0] ) ) {
						$compare_start = $this->starts_with( $meta_key_compare, $meta_key_parts[0] );
					}

					$compare_end = true;
					if ( ! empty( $meta_key_parts[1] ) ) {
						$compare_end = $this->ends_with( $meta_key_compare, $meta_key_parts[1] );
					}

					if ( true === $compare_start && true === $compare_end ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Does a string starts with a given string?
	 * @since   1.4
	 * @param   string  $haystack  The string to search in.
	 * @param   string  $needle    The string to search for.
	 * @return  bool
	 */
	private function starts_with( $haystack, $needle ) {
		// search backwards starting from haystack length characters from the end.
		return '' === $needle || strrpos( $haystack, $needle, -strlen( $haystack ) ) !== false;
	}

	/**
	 * Does a string ends with a given string?
	 * @since   1.4
	 * @param   string  $haystack  The string to search in.
	 * @param   string  $needle    The string to search for.
	 * @return  bool
	 */
	private function ends_with( $haystack, $needle ) {
		// search forward starting from end minus needle length characters.
		return '' === $needle || ( ( $temp = strlen( $haystack ) - strlen( $needle ) ) >= 0 && strpos( $haystack, $needle, $temp ) !== false);
	}

	/**
	 * Add admin bar setting items.
	 *
	 * @since   1.5
	 * @access  public
	 * @see     'vaa_admin_bar_settings_after' action
	 *
	 * @param   WP_Admin_Bar  $admin_bar  The toolbar object.
	 * @param   string        $root       The root item (vaa-settings).
	 * @return  void
	 */
	public function admin_bar_menu_settings( $admin_bar, $root ) {

		$admin_bar->add_group( array(
			'id'     => $root . '-role-defaults',
			'parent' => $root,
			'meta'   => array(
				'class' => 'ab-sub-secondary',
			),
		) );

		$root = $root . '-role-defaults';

		$admin_bar->add_node( array(
			'id'     => $root . '-enable',
			'parent' => $root,
			'title'  => VAA_View_Admin_As_Admin_Bar::do_checkbox( array(
				'name'        => $root . '-enable',
				'value'       => $this->get_optionData( 'enable' ),
				'compare'     => true,
				'label'       => __( 'Enable role defaults', VIEW_ADMIN_AS_DOMAIN ),
				'description' => __( 'Set default screen settings for roles and apply them on users through various bulk and automatic actions', VIEW_ADMIN_AS_DOMAIN ),
			) ),
			'href'   => false,
			'meta'   => array(
				'class' => 'auto-height',
			),
		) );

	}

	/**
	 * Add admin bar menu's.
	 *
	 * @since   1.4
	 * @since   1.5.2   Changed hook to vaa_admin_bar_settings_after (previous: 'vaa_admin_bar_roles_before').
	 * @access  public
	 * @see     'vaa_admin_bar_menu' action
	 *
	 * @param   WP_Admin_Bar  $admin_bar  The toolbar object.
	 * @param   string        $root       The root item (vaa).
	 * @return  void
	 */
	public function admin_bar_menu( $admin_bar, $root ) {

		$admin_bar->add_node( array(
			'id'     => $root . '-role-defaults',
			'parent' => $root,
			'title'  => VAA_View_Admin_As_Admin_Bar::do_icon( 'dashicons-id-alt' ) . __( 'Role defaults', VIEW_ADMIN_AS_DOMAIN ),
			'href'   => false,
			'meta'   => array(
				'class'    => 'vaa-has-icon',
				'tabindex' => '0',
			),
		) );

		$root = $root . '-role-defaults';

		$admin_bar->add_node( array(
			'id'     => $root . '-setting-register-enable',
			'parent' => $root,
			'title'  => VAA_View_Admin_As_Admin_Bar::do_checkbox( array(
				'name'    => $root . '-setting-register-enable',
				'value'   => $this->get_optionData( 'apply_defaults_on_register' ),
				'compare' => true,
				'label'   => __( 'Automatically apply defaults to new users', VIEW_ADMIN_AS_DOMAIN ),
			) ),
			'href'   => false,
			'meta'   => array(
				'class' => 'auto-height',
			),
		) );
		$admin_bar->add_node( array(
			'id'     => $root . '-setting-disable-user-screen-options',
			'parent' => $root,
			'title'  => VAA_View_Admin_As_Admin_Bar::do_checkbox( array(
				'name'        => $root . '-setting-disable-user-screen-options',
				'value'       => $this->get_optionData( 'disable_user_screen_options' ),
				'compare'     => true,
				'label'       => __( 'Disable screen options', VIEW_ADMIN_AS_DOMAIN ),
				'description' => __( "Hide the screen options for all users who can't access role defaults", VIEW_ADMIN_AS_DOMAIN ),
			) ),
			'href'   => false,
			'meta'   => array(
				'class' => 'auto-height',
			),
		) );
		$admin_bar->add_node( array(
			'id'     => $root . '-setting-lock-meta-boxes',
			'parent' => $root,
			'title'  => VAA_View_Admin_As_Admin_Bar::do_checkbox( array(
				'name'        => $root . '-setting-lock-meta-boxes',
				'value'       => $this->get_optionData( 'lock_meta_boxes' ),
				'compare'     => true,
				'label'       => __( 'Lock meta boxes', VIEW_ADMIN_AS_DOMAIN ),
				'description' => __( "Lock meta box order and locations for all users who can't access role defaults", VIEW_ADMIN_AS_DOMAIN ),
			) ),
			'href'   => false,
			'meta'   => array(
				'class' => 'auto-height',
			),
		) );

		/**
		 * Manage metakeys.
		 *
		 * @since  1.6.3
		 */
		$admin_bar->add_group( array(
			'id'     => $root . '-meta',
			'parent' => $root,
			'meta'   => array(
				'class' => 'ab-sub-secondary',
			),
		) );
		$admin_bar->add_node( array(
			'id'     => $root . '-meta-title',
			'parent' => $root . '-meta',
			'title'  => VAA_View_Admin_As_Admin_Bar::do_icon( 'dashicons-admin-tools' )
						. __( 'Manage meta sync', VIEW_ADMIN_AS_DOMAIN ),
			'href'   => false,
			'meta'   => array(
				'class'    => 'ab-bold vaa-has-icon ab-vaa-toggle',
				'tabindex' => '0',
			),
		) );
		$admin_bar->add_node( array(
			'id'     => $root . '-meta-docs',
			'parent' => $root . '-meta',
			'title'  => VAA_View_Admin_As_Admin_Bar::do_icon( 'dashicons-info' ) . __( 'Documentation', VIEW_ADMIN_AS_DOMAIN ),
			'href'   => 'https://github.com/JoryHogeveen/view-admin-as/wiki/FAQ#4-what-data-is-stored-for-role-defaults-and-how-can-i-change-this',
			'meta'   => array(
				'class'  => 'auto-height vaa-has-icon',
				'target' => '_blank',
			),
		) );
		$admin_bar->add_node( array(
			'id'     => $root . '-meta-add',
			'parent' => $root . '-meta',
			'title'  => VAA_View_Admin_As_Admin_Bar::do_input( array(
				'name'        => $root . '-meta-new',
				'placeholder' => esc_attr__( 'Add meta key', VIEW_ADMIN_AS_DOMAIN ),
			) ) . VAA_View_Admin_As_Admin_Bar::do_button( array(
				'name'        => $root . '-meta-add',
				'label'       => __( 'Add', VIEW_ADMIN_AS_DOMAIN ),
				'classes'     => 'button-primary input-overlay',
			) )
			. '<div id="' . $root . '-meta-template" style="display: none;"><div class="ab-item vaa-item">'
			. VAA_View_Admin_As_Admin_Bar::do_checkbox( array(
				'name'           => 'role-defaults-meta-select[]',
				'id'             => $root . '-meta-select-vaa_new_item',
				'value'          => true,
				'compare'        => true,
				'checkbox_value' => 'vaa_new_item',
				'label'          => 'vaa_new_item',
				'removable'      => true,
			) ) . '</div></div>',
			'href'   => false,
			'meta'   => array(
			  'class' => 'ab-vaa-input',
			),
		) );
		$meta_select_content = '';
		foreach ( $this->get_meta() as $metakey => $value ) {
			$meta_select_content .=
				'<div class="ab-item vaa-item">'
				. VAA_View_Admin_As_Admin_Bar::do_checkbox( array(
					'name'           => 'role-defaults-meta-select[]',
					'id'             => $root . '-meta-select-' . $metakey,
					'value'          => $value,
					'compare'        => true,
					'checkbox_value' => $metakey,
					'label'          => $metakey,
					'removable'      => ( array_key_exists( $metakey, $this->meta_default ) ) ? false : true,
				) )
				. '</div>';
		}
		$admin_bar->add_node( array(
			'id'     => $root . '-meta-select',
			'parent' => $root . '-meta',
			'title'  => $meta_select_content,
			'href'   => false,
			'meta'   => array(
				'class' => 'ab-vaa-multipleselect max-height',
			),
		) );
		$admin_bar->add_node( array(
			'id'     => $root . '-meta-apply',
			'parent' => $root . '-meta',
			'title'  => VAA_View_Admin_As_Admin_Bar::do_button( array(
				'name'    => $root . '-meta-apply',
				'label'   => __( 'Apply', VIEW_ADMIN_AS_DOMAIN ),
				'classes' => 'button-primary',
			) ),
			'href'   => false,
			'meta'   => array(
				'class' => 'vaa-button-container',
			),
		) );

		/**
		 * Bulk actions.
		 */

		$role_select_options = array(
			array(
				'label' => ' --- ',
			),
			array(
				'value' => 'all',
				'label' => ' - ' . __( 'All roles', VIEW_ADMIN_AS_DOMAIN ) . ' - ',
			),
		);
		foreach ( $this->store->get_rolenames() as $role_key => $role_name ) {
			$role_select_options[] = array(
				'value' => esc_attr( $role_key ),
				'label' => esc_html( $role_name ),
			);
		}

		if ( $this->store->get_users() ) {
			// Users select.
			$admin_bar->add_group( array(
				'id'     => $root . '-bulk-users',
				'parent' => $root,
				'meta'   => array(
					'class' => 'ab-sub-secondary',
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-bulk-users-title',
				'parent' => $root . '-bulk-users',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_icon( 'dashicons-admin-users' )
							. __( 'Apply defaults to users', VIEW_ADMIN_AS_DOMAIN ),
				'href'   => false,
				'meta'   => array(
					'class'    => 'ab-bold vaa-has-icon ab-vaa-toggle',
					'tabindex' => '0',
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-bulk-users-filter',
				'parent' => $root . '-bulk-users',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_input( array(
					'name'        => $root . '-bulk-users-filter',
					'placeholder' => esc_attr__( 'Filter', VIEW_ADMIN_AS_DOMAIN ) . ' (' . strtolower( __( 'Username' ) ) . ')',
				) ),
				'href'   => false,
				'meta'   => array(
					'class' => 'ab-vaa-filter',
				),
			) );
			$bulk_users_select_content = '';
			foreach ( $this->store->get_users() as $user ) {
				foreach ( $user->roles as $role ) {
					$role_data = $this->store->get_roles( $role );
					if ( $role_data instanceof WP_Role ) {
						$role_name = $this->store->get_rolenames( $role );
						$bulk_users_select_content .=
							'<div class="ab-item vaa-item">'
							. VAA_View_Admin_As_Admin_Bar::do_checkbox( array(
								'name'           => 'role-defaults-bulk-users-select[]',
								'id'             => $root . '-bulk-users-select-' . $user->ID,
								'checkbox_value' => $user->ID . '|' . $role,
								'label'          => '<span class="user-name">' . $user->display_name . '</span> &nbsp; <span class="user-role">(' . $role_name . ')</span>',
							) )
							. '</div>';
					}
				}
			}
			$admin_bar->add_node( array(
				'id'     => $root . '-bulk-users-select',
				'parent' => $root . '-bulk-users',
				'title'  => $bulk_users_select_content,
				'href'   => false,
				'meta'   => array(
					'class' => 'ab-vaa-multipleselect max-height',
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-bulk-users-apply',
				'parent' => $root . '-bulk-users',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_button( array(
					'name'    => $root . '-bulk-users-apply',
					'label'   => __( 'Apply', VIEW_ADMIN_AS_DOMAIN ),
					'classes' => 'button-primary',
				) ),
				'href'   => false,
				'meta'   => array(
					'class' => 'vaa-button-container',
				),
			) );
		}

		if ( $this->store->get_users() && $this->store->get_roles() ) {
			// Roles select.
			$admin_bar->add_group( array(
				'id'     => $root . '-bulk-roles',
				'parent' => $root,
				'meta'   => array(
					'class' => 'ab-sub-secondary',
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-bulk-roles-title',
				'parent' => $root . '-bulk-roles',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_icon( 'dashicons-groups' )
							. __( 'Apply defaults to users by role', VIEW_ADMIN_AS_DOMAIN ),
				'href'   => false,
				'meta'   => array(
					'class'    => 'ab-bold vaa-has-icon ab-vaa-toggle',
					'tabindex' => '0',
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-bulk-roles-select',
				'parent' => $root . '-bulk-roles',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_select( array(
					'name' => $root . '-bulk-roles-select',
					'values' => $role_select_options,
				) ),
				'href'   => false,
				'meta'   => array(
					'class' => 'ab-vaa-select select-role', // vaa-column-one-half vaa-column-last .
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-bulk-roles-apply',
				'parent' => $root . '-bulk-roles',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_button( array(
					'name'    => $root . '-bulk-roles-apply',
					'label'   => __( 'Apply', VIEW_ADMIN_AS_DOMAIN ),
					'classes' => 'button-primary',
				) ),
				'href'   => false,
				'meta'   => array(
					'class' => 'vaa-button-container',
				),
			) );
		}

		if ( $this->store->get_roles() ) {

			/*
			 * Export actions.
			 */
			$admin_bar->add_group( array(
				'id'     => $root . '-export',
				'parent' => $root,
				'meta'   => array(
					'class' => 'ab-sub-secondary',
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-export-roles',
				'parent' => $root . '-export',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_icon( 'dashicons-upload' )
							. __( 'Export defaults for role', VIEW_ADMIN_AS_DOMAIN ),
				'href'   => false,
				'meta'   => array(
					'class'    => 'ab-bold vaa-has-icon ab-vaa-toggle',
					'tabindex' => '0',
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-export-roles-select',
				'parent' => $root . '-export',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_select( array(
					'name' => $root . '-export-roles-select',
					'values' => $role_select_options,
				) ),
				'href'   => false,
				'meta'   => array(
					'class' => 'ab-vaa-select select-role', // vaa-column-one-half vaa-column-last .
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-export-roles-export',
				'parent' => $root . '-export',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_button( array(
					'name'    => $root . '-export-roles-export',
					'label'   => __( 'Export', VIEW_ADMIN_AS_DOMAIN ),
					'classes' => 'button-secondary',
				) ),
				'href'   => false,
				'meta'   => array(
					'class' => 'vaa-button-container',
				),
			) );

			/*
			 * Import actions.
			 */
			$admin_bar->add_group( array(
				'id'     => $root . '-import',
				'parent' => $root,
				'meta'   => array(
					'class' => 'ab-sub-secondary',
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-import-roles',
				'parent' => $root . '-import',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_icon( 'dashicons-download' )
							. __( 'Import defaults for role', VIEW_ADMIN_AS_DOMAIN ),
				'href'   => false,
				'meta'   => array(
					'class'    => 'ab-bold vaa-has-icon ab-vaa-toggle',
					'tabindex' => '0',
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-import-roles-input',
				'parent' => $root . '-import',
				'title'  => '<textarea id="' . $root . '-import-roles-input" name="role-defaults-import-roles-input" placeholder="' . esc_attr__( 'Paste code here', VIEW_ADMIN_AS_DOMAIN ) . '"></textarea>',
				'href'   => false,
				'meta'   => array(
					'class' => 'ab-vaa-textarea input-role', // vaa-column-one-half vaa-column-last .
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-import-roles-import',
				'parent' => $root . '-import',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_button( array(
					'name'    => $root . '-import-roles-import',
					'label'   => __( 'Import', VIEW_ADMIN_AS_DOMAIN ),
					'classes' => 'button-secondary ab-vaa-showhide vaa-import-role-defaults',
					'attr'    => array(
						'data-method' => 'import',
						'data-showhide' => 'p.vaa-import-role-defaults-desc',
					),
				) ) . ' '
				. VAA_View_Admin_As_Admin_Bar::do_button( array(
					'name'    => $root . '-import-roles-import-overwrite',
					'label'   => __( 'Overwrite', VIEW_ADMIN_AS_DOMAIN ),
					'classes' => 'button-secondary ab-vaa-showhide vaa-import-role-defaults',
					'attr'    => array(
						'data-method' => 'overwrite',
						'data-showhide' => 'p.vaa-import-role-defaults-overwrite-desc',
					),
				) ) . ' '
				. VAA_View_Admin_As_Admin_Bar::do_button( array(
					'name'    => $root . '-import-roles-import-append',
					'label'   => __( 'Append', VIEW_ADMIN_AS_DOMAIN ),
					'classes' => 'button-secondary ab-vaa-showhide vaa-import-role-defaults',
					'attr'    => array(
						'data-method' => 'append',
						'data-showhide' => 'p.vaa-import-role-defaults-append-desc',
					),
				) )
				. VAA_View_Admin_As_Admin_Bar::do_description(
					__( 'Fully overwrite data for each supplied role', VIEW_ADMIN_AS_DOMAIN ),
					array( 'class' => 'vaa-import-role-defaults-desc' )
				)
				. VAA_View_Admin_As_Admin_Bar::do_description(
					__( 'Overwrite and keep existing data that is not overwritten', VIEW_ADMIN_AS_DOMAIN ),
					array( 'class' => 'vaa-import-role-defaults-overwrite-desc' )
				)
				. VAA_View_Admin_As_Admin_Bar::do_description(
					__( 'Append without overwriting the existing data', VIEW_ADMIN_AS_DOMAIN ),
					array( 'class' => 'vaa-import-role-defaults-append-desc' )
				),
				'href'   => false,
				'meta'   => array(
					'class' => 'vaa-button-container',
				),
			) );

			/**
			 * Add all existing roles from defaults to the clear list if they have been removed from WP.
			 * Don't show roles that don't have data.
			 *
			 * @see    https://github.com/JoryHogeveen/view-admin-as/issues/22
			 * @since  1.6.2
			 */
			$role_clear_options = array(
				array(
					'label' => ' --- ',
				),
				array(
					'value' => 'all',
					'label' => ' - ' . __( 'All roles', VIEW_ADMIN_AS_DOMAIN ) . ' - ',
				),
			);
			foreach ( (array) $this->get_role_defaults() as $role_key => $role ) {
				if ( ! empty( $role_key ) ) {
					// get_rolenames will return key if it didn't find the role name.
					$role_name = $this->store->get_rolenames( $role_key );
					$role_clear_options[] = array(
						'value' => esc_attr( $role_key ),
						'label' => $role_name,
					);
				}
			}

			/*
			 * Clear actions
			 */
			$admin_bar->add_group( array(
				'id'     => $root . '-clear',
				'parent' => $root,
				'meta'   => array(
					'class' => 'ab-sub-secondary vaa-sub-transparent',
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-clear-roles',
				'parent' => $root . '-clear',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_icon( 'dashicons-trash' )
							. __( 'Remove defaults for role', VIEW_ADMIN_AS_DOMAIN ),
				'href'   => false,
				'meta'   => array(
					'class'    => 'ab-bold vaa-has-icon ab-vaa-toggle',
					'tabindex' => '0',
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-clear-roles-select',
				'parent' => $root . '-clear',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_select( array(
					'name' => $root . '-clear-roles-select',
					'values' => $role_clear_options,
				) ),
				'href'   => false,
				'meta'   => array(
					'class' => 'ab-vaa-select select-role', // vaa-column-one-half vaa-column-last .
				),
			) );
			$admin_bar->add_node( array(
				'id'     => $root . '-clear-roles-apply',
				'parent' => $root . '-clear',
				'title'  => VAA_View_Admin_As_Admin_Bar::do_button( array(
					'name'    => $root . '-clear-roles-apply',
					'label'   => __( 'Apply', VIEW_ADMIN_AS_DOMAIN ),
					'classes' => 'button-secondary',
				) ),
				'href'   => false,
				'meta'   => array(
					'class' => 'vaa-button-container',
				),
			) );
		}
	}

	/**
	 * Main Instance.
	 *
	 * Ensures only one instance of this class is loaded or can be loaded.
	 *
	 * @since   1.5
	 * @access  public
	 * @static
	 * @param   VAA_View_Admin_As  $caller  The referrer class.
	 * @return  VAA_View_Admin_As_Role_Defaults
	 */
	public static function get_instance( $caller = null ) {
		if ( is_object( $caller ) && 'VAA_View_Admin_As' === get_class( $caller ) ) {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self( $caller );
			}
			return self::$_instance;
		}
		return null;
	}

} // end class.

} // end if class_exists.
