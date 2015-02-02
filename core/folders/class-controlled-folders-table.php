<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// Load WP_List_Table if not loaded
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * GDM_Controlled_Folders_Table Class
 */
class GDM_Controlled_Folders_Table extends WP_List_Table {
	/**
	 * Number of results to show per page
	 *
	 * @var string
	 */
	public $per_page = 30;

	/**
	 *
	 * Total number of folders
	 * @var string
	 */
	public $total_count;

	/**
	 * Active number of folders
	 *
	 * @var string
	 */
	public $active_count;

	/**
	 * Inactive number of folders
	 *
	 * @var string
	 */
	public $inactive_count;

	/**
	 * Get things started
	 *
	 * @uses GDM_Controlled_Folders_Table::get_controlled_folder_counts()
	 * @see WP_List_Table::__construct()
	 */
	public function __construct() {
		global $page;

		parent::__construct( array(
			'singular'  => 'Controlled Folder',    // Singular name of the listed records
			'plural'    => 'Controlled Folders',    	// Plural name of the listed records
			'ajax'      => false             			// Does this table support ajax?
		) );

		$this->get_controlled_folder_counts();
	}

	/**
	 * Show the search field
	 *
	 * @access public
	 * @since 1.4
	 *
	 * @param string $text Label for the search box
	 * @param string $input_id ID of the search box
	 *
	 * @return svoid
	 */
	public function search_box( $text, $input_id ) {
		if ( empty( $_REQUEST['s'] ) && !$this->has_items() )
			return;

		$input_id = $input_id . '-search-input';

		if ( ! empty( $_REQUEST['orderby'] ) )
			echo '<input type="hidden" name="orderby" value="' . esc_attr( $_REQUEST['orderby'] ) . '" />';
		if ( ! empty( $_REQUEST['order'] ) )
			echo '<input type="hidden" name="order" value="' . esc_attr( $_REQUEST['order'] ) . '" />';
		?>
		<p class="search-box">
			<label class="screen-reader-text" for="<?php echo $input_id ?>"><?php echo $text; ?>:</label>
			<input type="search" id="<?php echo $input_id ?>" name="s" value="<?php _admin_search_query(); ?>" />
			<?php submit_button( $text, 'button', false, false, array('ID' => 'search-submit') ); ?>
		</p>
	<?php
	}

	/**
	 * Retrieve the view types
	 *
	 * @access public
	 * @since 1.4
	 * @return array $views All the views available
	 */
	public function get_views() {
		$base           = admin_url('admin.php?page=gdm-controlledfolders');

		$current        = 'all';
		$total_count    = '&nbsp;<span class="count">(' . $this->total_count    . ')</span>';

		$views = array(
			'all'		=> sprintf( '<a href="%s"%s>%s</a>', $base, $current === 'all' || $current == '' ? ' class="current"' : '', 'All' . $total_count )
		);

		return $views;
	}

	/**
	 * Retrieve the table columns
	 *
	 * @access public
	 * @since 1.4
	 * @return array $columns Array of all the list table columns
	 */
	public function get_columns() {
		$columns = array(
			'cb'        => '<input type="checkbox" />',
			'title'  	=> 'Title',
			'folderid'  => 'Drive Folder ID',
			'shortcode' => 'Shortcode'
		);

		return $columns;
	}

	/**
	 * Retrieve the table's sortable columns
	 *
	 * @access public
	 * @return array Array of all the sortable columns
	 */
	public function get_sortable_columns() {
		return array(
			'title'   => array( 'title', false )
		);
	}

	/**
	 * This function renders most of the columns in the list table.
	 *
	 * @access public
	 *
	 * @param array $item Contains all the data of the cf
	 * @param string $column_name The name of the column
	 *
	 * @return string Column Name
	 */
	function column_default( $item, $column_name ) {
		switch( $column_name ){
			default:
				return $item[ $column_name ];
		}
	}

	/**
	 * Render the Title Column
	 *
	 * @access public
	 * @param array $item Contains all the data of the cf code
	 * @return string Data shown in the Name column
	 */
	function column_title( $item ) {
		$cf     = get_post( $item['ID'] );
		$base         = admin_url( 'admin.php?page=gdm-controlledfolders&gdm-action=edit_cf&cf=' . $item['ID'] );
		$row_actions  = array();
		
		$edit_url = add_query_arg( array( 'gdm-action' => 'edit_cf', 'cf' => $cf->ID ) );

		$row_actions['edit'] = '<a href="' . $edit_url . '">Edit</a>';

		$row_actions['delete'] = '<a href="' . wp_nonce_url( add_query_arg( array( 'gdm-action' => 'delete_cf', 'cf' => $cf->ID ) ), 'gdm_cf_nonce' ) . '">Delete</a>';

		return '<a href="'.$edit_url.'">'.htmlentities( $item['title'] ).'</a>' . $this->row_actions( $row_actions );
	}

	/**
	 * Render the checkbox column
	 *
	 * @access public
	 * @since 1.4
	 * @param array $item Contains all the data for the checkbox column
	 * @return string Displays a checkbox
	 */
	function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="%1$s[]" value="%2$s" />',
			/*$1%s*/ 'cfolder',
			/*$2%s*/ $item['ID']
		);
	}

	/**
	 * Message to be displayed when there are no items
	 *
	 * @since 1.7.2
	 * @access public
	 */
	function no_items() {
		echo 'No controlled folders found.';
	}

	/**
	 * Retrieve the bulk actions
	 *
	 * @access public
	 * @since 1.4
	 * @return array $actions Array of the bulk actions
	 */
	public function get_bulk_actions() {
		$actions = array(
			'delete'     => __( 'Delete', 'edd' )
		);

		return $actions;
	}

	/**
	 * Process the bulk actions
	 *
	 * @access public
	 * @since 1.4
	 * @return void
	 */
	public function process_bulk_action() {
		$ids = isset( $_GET['cfolder'] ) ? $_GET['cfolder'] : false;

		if ( ! is_array( $ids ) )
			$ids = array( $ids );

		foreach ( $ids as $id ) {
			if ( 'delete' === $this->current_action() ) {
				wp_delete_post( $id, true );
			}
		}

	}

	/**
	 * Retrieve the cf code counts
	 *
	 * @access public
	 * @since 1.4
	 * @return void
	 */
	public function get_controlled_folder_counts() {
		$cf_count  = wp_count_posts( 'gdm_cfolder' );
		$this->active_count   = isset($cf_count->active) ? $cf_count->active : 0;
		$this->inactive_count = isset($cf_count->inactive) ? $cf_count->inactive : 0;
		$this->total_count    = $this->active_count + $this->inactive_count;
	}

	/**
	 * Retrieve all the data for all the cfs
	 *
	 * @access public
	 * @since 1.4
	 * @return array $cfolders_data Array of all the data for the cfs
	 */
	public function controlled_folders_data() {
		$cfolders_data = array();

		$per_page = $this->per_page;

		$orderby 		= isset( $_GET['orderby'] )  ? $_GET['orderby']                  : 'ID';
		$order 			= isset( $_GET['order'] )    ? $_GET['order']                    : 'DESC';
		$order_inverse 	= $order == 'DESC'           ? 'ASC'                             : 'DESC';
		$meta_key		= isset( $_GET['meta_key'] ) ? $_GET['meta_key']                 : null;
		$search         = isset( $_GET['s'] )        ? sanitize_text_field( $_GET['s'] ) : null;
		$order_class 	= strtolower( $order_inverse );

		$cfolders = $this->gdm_get_cfolders( array(
			'posts_per_page' => $per_page,
			'paged'          => isset( $_GET['paged'] ) ? $_GET['paged'] : 1,
			'orderby'        => $orderby,
			'order'          => $order,
			'meta_key'       => $meta_key,
			's'              => $search
		) );

		if ( $cfolders ) {
			foreach ( $cfolders as $cf ) {
				$folderid = get_post_meta( $cf->ID, 'gdm-folder-id', true );
					
				$cfolders_data[] = array(
					'ID' 			=> $cf->ID,
					'title' 		=> $cf->post_title,
					'folderid' 		=> $folderid,
					'shortcode'		=> '[google-drive-folder cfid="'.$cf->ID.'"]'
				);
			}
		}

		return $cfolders_data;
	}
	
	protected function gdm_get_cfolders( $args = array() ) {
		$defaults = array(
				'post_type'      => 'gdm_cfolder',
				'posts_per_page' => 30,
				'post_status' => 'active',
				'paged'          => null
		);
	
		$args = wp_parse_args( $args, $defaults );
	
		$cfolders = get_posts( $args );
	
		if ( $cfolders ) {
			return $cfolders;
		}
	
		if( !$cfolders && ! empty( $args['s'] ) ) {
			// If no cfs are found and we are searching, re-query with a meta key to find cfs by id
			if (is_numeric($args['s'])) {
				$cfolder = get_post($args['s']);
				if ($cfolder) {
					return array($cfolder);
				}
		    }
		}
	
		return false;
	}

	/**
	 * Setup the final data for the table
	 *
	 */
	public function prepare_items() {
		$per_page = $this->per_page;

		$columns = $this->get_columns();

		$hidden = array();

		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$this->process_bulk_action();

		$data = $this->controlled_folders_data();

		$current_page = $this->get_pagenum();

		$this->items = $data;
		
		$total_items = $this->total_count;

		$this->set_pagination_args( array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page )
			)
		);
	}
}
