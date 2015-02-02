<?php

class gdm_controlled_folders {
	protected $gdm_options = null;
	protected $permissions_helper = null;
	public function __construct($options, $permissions_helper) {
		$this->gdm_options = $options;
		$this->permissions_helper = $permissions_helper;
	}
	
	public function setup_post_types() {
		add_action('init', array($this, 'do_setup_post_types'));
		add_action('admin_init', array($this, 'do_early_gdm_actions'));
		add_action('admin_menu', array($this, 'do_create_menu'));
	}
	
	public function do_setup_post_types() {
		register_post_type( 'gdm_cfolder',
			array(
				'labels' => array(
					'name' => 'Controlled Folders',
					'singular_name' => 'Controlled Folder' 
				),
				'description'		=> 'Google Drive Controlled Folder',
				'public' 			=> false,
				'query_var' 		=> false,
				'rewrite' 			=> false,
				'show_ui'           => false,
				'capability_type' 	=> 'manage_control_folders',
				'map_meta_cap'      => true,
				'supports' 			=> array( 'title' ),
				'can_export'		=> true
			)
		);
		
		add_action('wp_ajax_gdm_register_controlled_folder', array($this, 'gdm_register_controlled_folder'));
	}
	
	public function gdm_register_controlled_folder() {
		if (!current_user_can('manage_control_folders')) {
			die(json_encode(array('error' => array('message' => "You are not authorized to create controlled folders (no 'manage_control_folders' capability)"))));
		}
		
		if (!isset($_POST['cf_register_nonce']) || !wp_verify_nonce($_POST['cf_register_nonce'], 'gdm-cf-register')) {
			die(json_encode(array('error' => array('message' => "Security problem trying to register controlled folder (invalid nonce)"))));
		}
		
		if (!isset($_POST['title']) || !isset($_POST['folderid'])) {
			die(json_encode(array('error' => array('message' => 'Need both title and folderid'))));
		}
		
		$new_perms = $this->permissions_helper->read_permissions_inputs($_POST, 'gdm_permissions_meta_', $alldefault);
		
		$id = $this->store_controlled_folder(array(
								'title' => $_POST['title'], 
								'folderid' => $_POST['folderid'],
								'gdm_controls' => array('permissions' => $new_perms)
						));
		
		die(json_encode(array('id' => $id)));
	}
	
	public function store_controlled_folder($data, $id=null) {
		
		if (!isset($data['title']) || !isset($data['folderid'])) {
			return null;
		}
		
		if ($id) {
			wp_update_post( array(
				'ID'          => $id,
				'post_title'  => isset( $data['title'] ) ? $data['title'] : '',
				'post_status' => 'active'
			) );
				
		}
		else {
			$id = wp_insert_post( array(
				'post_type'   => 'gdm_cfolder',
				'post_title'  => isset( $data['title'] ) ? $data['title'] : '',
				'post_status' => 'active'
			) );
			if (!$id) {
				return null;
			}
		}
		
		update_post_meta( $id, 'gdm-folder-id',  $data['folderid']);
		
		if (isset($data['alldefault']) && $data['alldefault']) {
			delete_post_meta($id, 'gdm_controls');
		}
		elseif (isset($data['gdm_controls'])) {
			update_post_meta($id, 'gdm_controls',  $data['gdm_controls']);
		}
		
		return $id;
	}
	
	public function do_create_menu() {
		add_menu_page('Controlled Folders', 'Controlled Folder', 'manage_control_folders', 'gdm-controlledfolders', 
						array($this, 'display_controlled_folders_page'), 'dashicons-category');
	}
	
	public function do_early_gdm_actions() {
		if (!isset($_REQUEST['gdm-action']) || !current_user_can('manage_control_folders')) {
			return;	
		}
		
		// Are we trying to save some data?
		if ($_REQUEST['gdm-action'] == 'update_cf') {
			
			// Check nonce
			if (!isset( $_REQUEST['gdm-cf-nonce'] ) || !wp_verify_nonce( $_REQUEST['gdm-cf-nonce'], 'gdm_cf_nonce' ) ) {
				wp_redirect(add_query_arg( 'gdm-message', 'cf_error', admin_url('admin.php?page=gdm-controlledfolders') ) );
				die();
			}
			
			$cf_post = null;
			$cf_folderid = '';
			$localperms = null;
			if (isset($_REQUEST['cf']) && is_numeric($_REQUEST['cf'])) {
				$cf_post = get_post($_REQUEST['cf']);
				$cf_folderid = get_post_meta($_REQUEST['cf'], 'gdm-folder-id', true);
			
				$local_options = get_post_meta( $_REQUEST['cf'], 'gdm_controls', true );
			
				$localperms = ($local_options && is_array($local_options) && isset($local_options['permissions']))
								? $local_options['permissions'] : null;
			}
				
			if ($this->save_controlled_folder($cf_post)) {
				wp_redirect(add_query_arg( 'gdm-message', 'cf_updated', admin_url('admin.php?page=gdm-controlledfolders') ) );
				die();
			}
			else {
				wp_redirect(add_query_arg( 'gdm-message', 'cf_error', admin_url('admin.php?page=gdm-controlledfolders') ) );
				die();
			}
		}
		elseif ($_REQUEST['gdm-action'] == 'delete_cf') {
			// Delete a controlled folder
			if (isset($_REQUEST['cf']) && is_numeric($_REQUEST['cf'])) {
				// Check nonce
				if (isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'gdm_cf_nonce' ) ) {
					if (wp_delete_post($_REQUEST['cf'], true)) {
						wp_redirect(add_query_arg( 'gdm-message', 'cf_deleted', admin_url('admin.php?page=gdm-controlledfolders') ) );
						die();
					}
				}
				
				wp_redirect(add_query_arg( 'gdm-message', 'cf_error', admin_url('admin.php?page=gdm-controlledfolders') ) );
				die();
			}
		}
	}
	
	public function display_controlled_folders_page() {
		
		if (!current_user_can('manage_control_folders')) {
			die("You are not authorized to manage controlled folders (no 'manage_control_folders' capability)");
		}
		
		if (isset($_GET['gdm-action']) 
				&& ($_GET['gdm-action'] == 'edit_cf' 
						|| $_GET['gdm-action'] == 'add_controlled_folder')) {
			
			$cf_post = null;
			$cf_folderid = '';
			$localperms = null;
			if (isset($_REQUEST['cf']) && is_numeric($_REQUEST['cf'])) {
				$cf_post = get_post($_REQUEST['cf']);
				$cf_folderid = get_post_meta($_REQUEST['cf'], 'gdm-folder-id', true);
				
				$local_options = get_post_meta( $_REQUEST['cf'], 'gdm_controls', true );
				
				$localperms = ($local_options && is_array($local_options) && isset($local_options['permissions']))
								? $local_options['permissions'] : null;
			}
			
			$gde = GoogleDriveEmbedder();
			$gde->enqueue_rolepermissions_scripts();
			
		?>
		<h2>Controlled Folders - <?php echo $_REQUEST['gdm-action'] == 'edit_cf' ? 'Edit' : 'Add New'; ?>
		 &nbsp;&nbsp; <a href="<?php echo admin_url( 'admin.php?page=gdm-controlledfolders' ); ?>" class="button-secondary">Go Back</a>
		</h2>
		
		<form id="gdm-add-controlledfolder" action="" method="POST">
		
			<table class="form-table">
			<tbody>
				<tr>
					<th scope="row" valign="top">
						<label for="gdm-cf-title">Title</label>
					</th>
					<td>
						<input name="title" id="gdm-cf-title" type="text" value="<?php echo $cf_post ? esc_attr($cf_post->post_title) : ''; ?>" style="width: 300px;"/>
					</td>
				</tr>
				<tr>
					<th scope="row" valign="top">
						<label for="gdm-cf-folderid">Drive Folder ID</label>
					</th>
					<td>
						<input type="text" id="gdm-cf-folderid" name="folderid" value="<?php echo esc_attr($cf_folderid); ?>" style="width: 300px;"/>
					</td>
				</tr>
			</tbody>
			</table>
			
			<h3>Permissions</h3>
			
			<?php  $this->permissions_helper->output_permissions_table('', $localperms, true); ?>
			
			<script type="text/javascript">
				jQuery(document).ready(function ($) {
					// Controlled Folders edit page
					$('table.gdm-permissions-table').rolepermissions();
				});
			</script>
		
			<p class="submit">
				<input type="hidden" name="gdm-action" value="update_cf"/>
				<input type="hidden" name="gdm-redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=gdm-controlledfolders' ) ); ?>"/>
				<input type="hidden" name="gdm-cf-nonce" value="<?php echo wp_create_nonce( 'gdm_cf_nonce' ); ?>"/>
				<input type="hidden" name="cf" value="<?php echo $cf_post ? $cf_post->ID : ''; ?>"/>
				<input type="submit" value="Save" class="button-primary"/>
			</p>
		</form>
		<?php 
		
		}
		else {
			if (isset($_GET['gdm-message'])) {
				if ($_GET['gdm-message'] == 'cf_updated') {
						?>
						<div id="setting-error-settings_updated" class="updated settings-error">
						<p>
						<strong>Controlled Folder saved</strong>
						</p>
						</div>
					<?php
				}
				elseif ($_GET['gdm-message'] == 'cf_deleted') {
						?>
						<div id="setting-error-settings_updated" class="updated settings-error">
						<p>
						<strong>Controlled Folder deleted</strong>
						</p>
						</div>
					<?php
				}
				elseif ($_GET['gdm-message'] == 'cf_error') {
					?>
						<div id="setting-error-settings_<?php echo $i; ?>" class="error settings-error">
						<p>
						<strong>Error updating Controlled Folder</strong>
						</p>
						</div>
					<?php
				}
			}
			
			// Display list
			
			require_once 'class-controlled-folders-table.php';
			$cf_table = new GDM_Controlled_Folders_Table();
			$cf_table->prepare_items();
			?>
				<div class="wrap">
					<h2>Controlled Folders <em>(Google Drive Embedder)</em> <a href="<?php echo add_query_arg( array( 'gdm-action' => 'add_controlled_folder' ) ); ?>" class="add-new-h2">Add New</a></h2>
					
					<form id="gdm-cf-filter" method="get" action="<?php echo admin_url( 'admin.php' ); ?>">
						<?php $cf_table->search_box( 'Search', 'gdm-cfolders' ); ?>
			
						<input type="hidden" name="post_type" value="gdm_cfolder" />
						<input type="hidden" name="page" value="gdm-controlledfolders" />
			
						<?php $cf_table->views() ?>
						<?php $cf_table->display() ?>
					</form>
					
				</div>
			<?php

		}
	}
	
	// Submit edit or add new
	protected function save_controlled_folder($cf_post=null) {
		if (!isset($_POST['title']) || $_POST['title'] == '' || !isset($_POST['folderid']) || $_POST['folderid'] == '') {
			return false;
		}
		
		$alldefault = true;
		
		// Read permission settings
		$new_perms = $this->permissions_helper->read_permissions_inputs($_POST, 'gdm_permissions_meta_', $alldefault);

		$data = array(
			'title' => $_POST['title'],
			'folderid' => $_POST['folderid'],
			'alldefault' => $alldefault,
			'gdm_controls' => array('permissions' => $new_perms)
		);
		
		$this->store_controlled_folder($data, $cf_post ? $cf_post->ID : null);
		return true;
	}
}
