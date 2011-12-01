<?php
class WP_Admin_Bar {
	private $nodes = array();
	public $user;

	public function __get( $name ) {
		switch ( $name ) {
			case 'proto' :
				return is_ssl() ? 'https://' : 'http://';
				break;
			case 'menu' :
				_deprecated_argument( 'WP_Admin_Bar', '3.3', 'Modify admin bar nodes with WP_Admin_Bar::get_node(), WP_Admin_Bar::add_node(), and WP_Admin_Bar::remove_node(), not the <code>menu</code> property.' );
				return array(); // Sorry, folks.
				break;
		}
	}

	public function initialize() {
		$this->user = new stdClass;

		$this->add_node( array(
			'id'       => 'root',
			'group'    => false,
		) );

		if ( is_user_logged_in() ) {
			/* Populate settings we need for the menu based on the current user. */
			$this->user->blogs = get_blogs_of_user( get_current_user_id() );
			if ( is_multisite() ) {
				$this->user->active_blog = get_active_blog_for_user( get_current_user_id() );
				$this->user->domain = empty( $this->user->active_blog ) ? user_admin_url() : trailingslashit( get_home_url( $this->user->active_blog->blog_id ) );
				$this->user->account_domain = $this->user->domain;
			} else {
				$this->user->active_blog = $this->user->blogs[get_current_blog_id()];
				$this->user->domain = trailingslashit( home_url() );
				$this->user->account_domain = $this->user->domain;
			}
		}

		add_action( 'wp_head', 'wp_admin_bar_header' );

		add_action( 'admin_head', 'wp_admin_bar_header' );

		if ( current_theme_supports( 'admin-bar' ) ) {
			$admin_bar_args = get_theme_support( 'admin-bar' ); // add_theme_support( 'admin-bar', array( 'callback' => '__return_false') );
			$header_callback = $admin_bar_args[0]['callback'];
		}

		if ( empty($header_callback) )
			$header_callback = '_admin_bar_bump_cb';

		add_action('wp_head', $header_callback);

		wp_enqueue_script( 'admin-bar' );
		wp_enqueue_style( 'admin-bar' );

		do_action( 'admin_bar_init' );
	}

	public function add_menu( $node ) {
		$this->add_node( $node );
	}

	public function remove_menu( $id ) {
		$this->remove_node( $id );
	}

	/**
	 * Add a node to the menu.
	 *
	 * @param array $args - The arguments for each node.
	 * - id         - string    - The ID of the item.
	 * - title      - string    - The title of the node.
	 * - parent     - string    - The ID of the parent node. Optional.
	 * - href       - string    - The link for the item. Optional.
	 * - group      - boolean   - If the node is a group. Optional. Default false.
	 * - meta       - array     - Meta data including the following keys: html, class, onclick, target, title, tabindex.
	 */
	public function add_node( $args ) {
		// Shim for old method signature: add_node( $parent_id, $menu_obj, $args )
		if ( func_num_args() >= 3 && is_string( func_get_arg(0) ) )
			$args = array_merge( array( 'parent' => func_get_arg(0) ), func_get_arg(2) );

		if ( is_object( $args ) )
			$args = get_object_vars( $args );

		// Ensure we have a valid title.
		if ( empty( $args['id'] ) ) {
			if ( empty( $args['title'] ) )
				return;

			_doing_it_wrong( __METHOD__, __( 'The menu ID should not be empty.' ), '3.3' );
			// Deprecated: Generate an ID from the title.
			$args['id'] = esc_attr( sanitize_title( trim( $args['title'] ) ) );
		}

		$defaults = array(
			'id'     => false,
			'title'  => false,
			'parent' => false,
			'href'   => false,
			'group'  => false,
			'meta'   => array(),
		);

		// If the node already exists, keep any data that isn't provided.
		if ( $this->get_node( $args['id'] ) )
			$defaults = get_object_vars( $this->get_node( $args['id'] ) );

		// Do the same for 'meta' items.
		if ( ! empty( $defaults['meta'] ) && empty( $args['meta'] ) )
			$args['meta'] = wp_parse_args( $args['meta'], $defaults['meta'] );

		$args = wp_parse_args( $args, $defaults );

		$this->_set_node( $args );
	}

	final protected function _set_node( $args ) {
		$this->nodes[ $args['id'] ] = (object) $args;
	}

	/**
	 * Gets a node.
	 *
	 * @return object Node.
	 */
	final public function get_node( $id ) {
		if ( isset( $this->nodes[ $id ] ) )
			return $this->nodes[ $id ];
	}

	final protected function _get_nodes() {
		return $this->nodes;
	}

	/**
	 * Add a group to a menu node.
	 *
	 * @since 3.3.0
	 *
	 * @param array $args - The arguments for each node.
	 * - id         - string    - The ID of the item.
	 * - parent     - string    - The ID of the parent node. Optional. Default root.
	 * - meta       - array     - Meta data including the following keys: class, onclick, target, title.
	 */
	final public function add_group( $args ) {
		$args['group'] = true;

		$this->add_node( $args );
	}

	/**
	 * Remove a node.
	 *
	 * @return object The removed node.
	 */
	public function remove_node( $id ) {
		$this->_unset_node( $id );
	}

	final protected function _unset_node( $id ) {
		unset( $this->nodes[ $id ] );
	}

	public function render() {
		$this->_bind();
		$this->_render();
	}

	final protected function _bind() {
		foreach ( $this->_get_nodes() as $node ) {
			if ( 'root' == $node->id )
				continue;

			// Handle root menu items
			if ( empty( $node->parent ) ) {
				$parent = $this->get_node( 'root' );
			} elseif ( ! $parent = $this->get_node( $node->parent ) ) {
				// If the parent node isn't registered, ignore the node.
				continue;
			}

			// Ensure that our tree is of the form "item -> group -> item -> group -> ..."
			if ( ! $parent->group && ! $node->group ) { // Both are items.
				// The default group is added here to allow groups that are
				// added before standard menu items to render first.
				if ( ! isset( $parent->children['default'] ) ) {
					$parent->children['default'] = (object) array(
						'id'       => "{$parent->id}-default",
						'parent'   => $parent->id,
						'group'    => true,
						'children' => array(),
					);
				}
				$parent = $parent->children['default'];
			}

			// Update the parent ID (it might have changed).
			$node->parent = $parent->id;

			if ( ! isset( $parent->children ) )
				$parent->children = array();

			// Add the node to the tree.
			$parent->children[] = $node;
		}
	}

	final protected function _render() {
		global $is_IE, $is_iphone;

		// Add browser classes.
		// We have to do this here since admin bar shows on the front end.
		$class = 'nojq nojs';
		if ( $is_IE ) {
			if ( strpos( $_SERVER['HTTP_USER_AGENT'], 'MSIE 7' ) )
				$class .= ' ie7';
			elseif ( strpos( $_SERVER['HTTP_USER_AGENT'], 'MSIE 8' ) )
				$class .= ' ie8';
			elseif ( strpos( $_SERVER['HTTP_USER_AGENT'], 'MSIE 9' ) )
				$class .= ' ie9';
		} elseif ( $is_iphone ) {
			$class .= ' mobile';
		}

		?>
		<div id="wpadminbar" class="<?php echo $class; ?>" role="navigation">
			<div class="quicklinks" role="menubar">
				<?php foreach ( $this->get_node( 'root' )->children as $group ) {
					$this->_render_group( $group, 'ab-top-menu' );
				} ?>
			</div>
		</div>

		<?php
	}

	final protected function _render_group( $node, $class = '' ) {
		if ( ! $node->group )
			return;

		// Check for groups within groups.
		$groups = array();
		foreach ( $node->children as $child ) {
			if ( $child->group ) {
				$groups[] = $child;
			} else {
				if ( ! isset( $default ) ) {
					// Create a default proxy item to be used in the case of nested groups.
					$default  = (object) wp_parse_args( array( 'children' => array() ), (array) $node );
					$groups[] = $default;
				}
				$default->children[] = $child;
			}
		}

		$is_single_group = count( $groups ) === 1;

		// If we don't have any subgroups, render the group.
		if ( $is_single_group && ! empty( $node->children ) ) :

			if ( ! empty( $node->meta['class'] ) )
				$class .= ' ' . $node->meta['class'];

			?><ul id="<?php echo esc_attr( "wp-admin-bar-{$node->id}" ); ?>" class="<?php echo esc_attr( $class ); ?>" role="menu"><?php
				foreach ( $node->children as $item ) {
					$this->_render_item( $item );
				}
			?></ul><?php

		// Wrap the subgroups in a div and render each individual subgroup.
		elseif ( ! $is_single_group ) :
			?><div id="<?php echo esc_attr( "wp-admin-bar-{$node->id}-container" ); ?>" class="ab-group-container" role="menu"><?php
				foreach ( $groups as $group ) {
					$this->_render_group( $group, $class );
				}
			?></div><?php
		endif;
	}

	final protected function _render_item( $node ) {
		if ( $node->group )
			return;

		$is_parent = ! empty( $node->children );
		$has_link  = ! empty( $node->href );

		$tabindex = isset( $node->meta['tabindex'] ) ? (int) $node->meta['tabindex'] : 10;

		$menuclass = '';
		$aria_attributes = 'tabindex="' . $tabindex . '" role="menuitem"';

		if ( $is_parent ) {
			$menuclass = 'menupop';
			$aria_attributes .= ' aria-haspopup="true"';
		}

		if ( ! empty( $node->meta['class'] ) )
			$menuclass .= ' ' . $node->meta['class'];

		?>

		<li id="<?php echo esc_attr( "wp-admin-bar-{$node->id}" ); ?>" class="<?php echo esc_attr( $menuclass ); ?>"><?php
			if ( $has_link ):
				?><a class="ab-item" <?php echo $aria_attributes; ?> href="<?php echo esc_url( $node->href ) ?>"<?php
					if ( ! empty( $node->meta['onclick'] ) ) :
						?> onclick="<?php echo esc_js( $node->meta['onclick'] ); ?>"<?php
					endif;
				if ( ! empty( $node->meta['target'] ) ) :
					?> target="<?php echo esc_attr( $node->meta['target'] ); ?>"<?php
				endif;
				if ( ! empty( $node->meta['title'] ) ) :
					?> title="<?php echo esc_attr( $node->meta['title'] ); ?>"<?php
				endif;
				?>><?php
			else:
				?><div class="ab-item ab-empty-item" <?php echo $aria_attributes;
				if ( ! empty( $node->meta['title'] ) ) :
					?> title="<?php echo esc_attr( $node->meta['title'] ); ?>"<?php
				endif;
				?>><?php
			endif;

			echo $node->title;

			if ( $has_link ) :
				?></a><?php
			else:
				?></div><?php
			endif;

			if ( $is_parent ) :
				?><div class="ab-sub-wrapper"><?php
					foreach ( $node->children as $group ) {
						$this->_render_group( $group, 'ab-submenu' );
					}
				?></div><?php
			endif;

			if ( ! empty( $node->meta['html'] ) )
				echo $node->meta['html'];

			?>
		</li><?php
	}

	public function recursive_render( $id, $node ) {
		_deprecated_function( __METHOD__, '3.3', 'WP_Admin_bar::render(), WP_Admin_Bar::_render_item()' );
		$this->_render_item( $node );
	}

	public function add_menus() {
		// User related, aligned right.
		add_action( 'admin_bar_menu', 'wp_admin_bar_search_menu', 10 );
		add_action( 'admin_bar_menu', 'wp_admin_bar_my_account_menu', 20 );

		// Site related.
		add_action( 'admin_bar_menu', 'wp_admin_bar_wp_menu', 10 );
		add_action( 'admin_bar_menu', 'wp_admin_bar_my_sites_menu', 20 );
		add_action( 'admin_bar_menu', 'wp_admin_bar_site_menu', 30 );
		add_action( 'admin_bar_menu', 'wp_admin_bar_updates_menu', 40 );

		// Content related.
		if ( ! is_network_admin() && ! is_user_admin() ) {
			add_action( 'admin_bar_menu', 'wp_admin_bar_comments_menu', 60 );
			add_action( 'admin_bar_menu', 'wp_admin_bar_new_content_menu', 70 );
		}
		add_action( 'admin_bar_menu', 'wp_admin_bar_edit_menu', 80 );
		add_action( 'admin_bar_menu', 'wp_admin_bar_shortlink_menu', 90 );

		add_action( 'admin_bar_menu', 'wp_admin_bar_add_secondary_groups', 200 );

		do_action( 'add_admin_bar_menus' );
	}
}
?>
