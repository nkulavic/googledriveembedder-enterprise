<?php

class gdm_control_permissions_helper {
	
	protected $gdm_options = null;
	public function __construct($options) {
		$this->gdm_options = $options;
	}
	
	public function setup_metaboxes() {
		
		$guess_post_type = 'post';
		if (isset($_REQUEST['post_type'])) {
			$guess_post_type = $_REQUEST['post_type'];
		}
		else {
			if (isset($_REQUEST['post']) && is_numeric($_REQUEST['post'])) {
				$guess_post_type = get_post_type($_REQUEST['post']);
			}
		}
		
		if (current_user_can('manage_control_folders') 
				|| $this->gdm_options['gdm_allow_overrides_autoc'] 
				|| ($this->gdm_options['gdm_allow_overrides_perms'] 
						&& in_array($guess_post_type, array('post','page')) 
						&& $this->get_resolved_auto_create_folders($guess_post_type))) {
			add_action('add_meta_boxes', array($this, 'add_permissions_metaboxes'));
			add_action( 'save_post', array($this, 'save_permissions_meta'), 10, 2 );
		}
	}
	
	public function add_permissions_metaboxes() {
		foreach ( $this->get_public_post_type_names() as $post_type ) {
			add_meta_box(
				'gdm-permissions-settings',      // Unique ID
				esc_html( 'Drive Attachments Folder' ),    // Title
				array($this, 'output_permissions_meta_box'),   // Callback function
				$post_type,         // Admin page (or post type)
				'advanced',         // Context
				'default'         // Priority
			);
		}
	}
	
	public function get_public_post_type_names() {

		return get_post_types( array('public'=>true), 'names' );
		
	}
	
	public function output_permissions_meta_box($object, $box) {
		wp_nonce_field( 'gdm_permissions_metabox_nonce', 'gdm_permissions_metabox_nonce' );
		
		$local_options = get_post_meta( $object->ID, 'gdm_controls', true );
		
		$auto_create_folder = ($local_options && is_array($local_options) && isset($local_options['auto_create_folder']))
								? $local_options['auto_create_folder'] : 'default';
		
		$localperms = ($local_options && is_array($local_options) && isset($local_options['permissions']))
								? $local_options['permissions'] : null;
		
		?>
		  <div class="gdm-metabox">
		  <?php if ($this->gdm_options['gdm_allow_overrides_autoc'] || current_user_can('manage_control_folders')) { ?>
		  	<h4>Document Attachments Area</h4>
		  	<label for="input_gdm_auto_create_folder">Auto-create Drive folder</label> &nbsp;
			<select id="input_gdm_auto_create_folder" name='gdm_auto_create_folder'>
			<option value="default"<?php echo $auto_create_folder=='default' ? ' selected' : ''; ?>>Default</option>
			<option value="yes"<?php echo $auto_create_folder=='yes' ? ' selected' : ''; ?>>Yes</option>
			<option value="no"<?php echo $auto_create_folder=='no' ? ' selected' : ''; ?>>No</option>
			</select>
		  
		    <br />
		   <?php  } 
		   	if  ($this->gdm_options['gdm_allow_overrides_perms'] || current_user_can('manage_control_folders')) { ?>
		    
		    <h4>Folder Permissions</h4>
		    
		    <?php 
		    
		    $this->output_permissions_table('', $localperms, true); 
		    
		    $gde = GoogleDriveEmbedder();
		    $gde->enqueue_rolepermissions_scripts();
		    
		    ?>
		    
		    <p><i>Always = always allow using Service Account
		    <br />Drive = allow only if user has a Google Drive account with appropriate sharing settings
		    <br />Never = never allow.</i></p>
		    
		    
		    <script type="text/javascript">
				jQuery(document).ready(function ($) {
					// On metabox
					$('div.gdm-metabox table.gdm-permissions-table').rolepermissions();
				});
			</script>
			
			<?php } ?>
		    
  		</div>
		<?php 
	}
	
	public function save_permissions_meta($post_id, $post) {
		/* Verify nonce */
		if ( !isset( $_POST['gdm_permissions_metabox_nonce'] ) || !wp_verify_nonce( $_POST['gdm_permissions_metabox_nonce'], 'gdm_permissions_metabox_nonce' ) ) {			
			return $post_id;
		}
		
		/* Get the post type object. */
		$post_type = get_post_type_object($post->post_type);
		
		/* Check if the current user has permission to edit the post. */
		if (!current_user_can($post_type->cap->edit_post, $post_id)) {
			return $post_id;
		}
		
		$old_local_options = get_post_meta( $post_id, 'gdm_controls', true );
		
		$new_auto_create_folder = ((current_user_can('manage_control_folders') || $this->gdm_options['gdm_allow_overrides_autoc']) 
									&& isset($_POST['gdm_auto_create_folder']) && in_array($_POST['gdm_auto_create_folder'], array('yes','no','default')) 
									? $_POST['gdm_auto_create_folder']
									: ( is_array($old_local_options) && isset($old_local_options['auto_create_folder']) 
												? $old_local_options['auto_create_folder'] 
												:'default' ));
		
		$alldefault = $new_auto_create_folder == 'default';

		// Read permission settings
		$new_perms = current_user_can('manage_control_folders') || $this->gdm_options['gdm_allow_overrides_perms'] 
			 	        ? $this->read_permissions_inputs($_POST, 'gdm_permissions_meta_', $alldefault)
						: ( is_array($old_local_options) && isset($old_local_options['permissions']) 
												? $old_local_options['permissions'] 
												: array() );
		
		if (!$alldefault) {
			$final_options = array('auto_create_folder' => $new_auto_create_folder, 'permissions' => $new_perms);
			update_post_meta($post_id, 'gdm_controls', $final_options);
		}
		else if ($old_local_options) {
			delete_post_meta($post_id, 'gdm_controls');
		}
	}
	
	// $alldefault will remain as it is, unless a non-default is encountered, in which case it will become false
	// Returns an array of perms
	public function read_permissions_inputs($data, $prefix='', &$alldefault, $defaultstring='default') {
		$new_perms = array();
		
		foreach (array_merge(array('_users_', '_visitors_'), array_keys($this->get_wp_roles_array())) as $usertype) {
			$actionperms = array();
			$thisrowexists = false;
			foreach (array('view', 'upload', 'edit') as $action) {
				$selectname = $prefix.$usertype.'_'.$action;
				if (isset($data[$selectname]) && in_array($data[$selectname], array('yes','no','drive','default'))) {
					$thisrowexists = true;
					$actionperms[$action] = $data[$selectname];
					if ($data[$selectname] != $defaultstring) {
						$alldefault = false;
					}
				}
			}
			if ($thisrowexists) {
				$new_perms[$usertype] = $actionperms;
			}
		}
		return $new_perms;
	}
	
	public function get_wp_roles_array($filterarray=null) {
		global $wp_roles;
		$rolesmap = array();
		foreach ($wp_roles->roles as $key => $value) {
			if (is_null($filterarray) || in_array($key, $filterarray)) {
				$rolesmap[$key] = $value['name'];
			}
		}
		return $rolesmap;
	}
	
	public function output_permissions_table($groupname, $localperms=null, $allowdefault=false) {
		$roleslist = array_merge( array( '_users_' => 'Logged-in Users' ),
								  $this->get_wp_roles_array(is_null($localperms) ? array() : array_keys($localperms)),
								  array( '_visitors_' => 'Visitors' )
								);
		
		?>
		<table class="gdm-permissions-table">
			<thead>
				<tr>
					<th>&nbsp;</th>
					<th>View</th>
					<th>Upload</th>
				</tr>
			</thead>
			<tbody>
				<?php 
				
				foreach ($roleslist as $rolekey => $rolename) {
					?>
					<tr data-gdm-rp-row="<?php echo esc_attr($rolekey); ?>">
						<td><?php echo htmlentities($rolename); ?></td>
						<td><?php $this->output_permissions_select($groupname, $localperms, $rolekey, 'view', $allowdefault); ?></td>
						<td><?php $this->output_permissions_select($groupname, $localperms, $rolekey, 'upload', $allowdefault); ?></td>
					</tr>
					<?php
				}
				
				?>
				
			</tbody>
		</table>
		<?php 
	}
	
	protected function output_permissions_select($groupname, $localperms, $usertype, $actionname, $allowdefault=false) {
		$selectname = $usertype.'_'.$actionname;
		
		if ($groupname == '') {
			$selectname = 'gdm_permissions_meta_'.$selectname; 
		}
		else {
			$selectname = $groupname.'['.$selectname.']';
		}	
		
		$value = is_null($localperms) && !$allowdefault
					? $this->get_global_permission($usertype, $actionname) 
					: $this->get_local_permission($usertype, $actionname, $localperms);
		
		if (is_null($value)) {
			$value = $allowdefault ? 'default' : 'drive';
		}
		
		echo '<select name="'.$selectname.'" id="select_'.$selectname.'">';
		if ($actionname != 'upload' || !isset($_SERVER['SERVER_SOFTWARE']) || 
			 strpos($_SERVER['SERVER_SOFTWARE'], 'Google App Engine') === false || $value == 'yes')
		{
			// Always doesn't currently work to upload on AppEngine
			echo '<option value="yes"'.($value == 'yes' ? ' selected' : '').'>Always</option>';
		}
		echo '<option value="drive"'.($value == 'drive' ? ' selected' : '').'>Drive</option>'
				.'<option value="no"'.($value == 'no' ? ' selected' : '').'>Never</option>'
		 		.($allowdefault ? '<option value="default"'.($value == 'default' ? ' selected' : '').'>Default</option>' : '')
		 		.'</select>';
	}
	
	public function get_global_permission($usertype, $actionname) {
		$perms = $this->gdm_options['gdm_permissions'];
		if (isset($perms[$usertype])) {
			if (isset($perms[$usertype][$actionname])) {
				return $perms[$usertype][$actionname];
			}
		}
		return null;
	}
	
	public function get_local_permission($usertype, $actionname, $perms) {
		if (is_array($perms) && isset($perms[$usertype])) {
			if (isset($perms[$usertype][$actionname])) {
				return $perms[$usertype][$actionname];
			}
		}
		return null;
	}
	
	public function get_resolved_permission($actionname, $post_id=-1) {
		// Get user's role
		$usertype = '_visitors_';
		$userrole = '';
		
		$user = wp_get_current_user();
		if ($user->exists()) {
			$usertype = '_users_';
			$userrole = '';
			if (is_array($user->roles) && count($user->roles) == 1) {
				$rolelist = array_values($user->roles);
				$userrole = $rolelist[0];
			}
		}
		
		$local_perm = 'default';
		if ($post_id > 0) {
			$local_options = get_post_meta( $post_id, 'gdm_controls', true );
	
			$localperms = ($local_options && is_array($local_options) && isset($local_options['permissions']))
				? $local_options['permissions'] : null;
			
			if ($userrole != '') {
				// Try for a role-based override
				$local_perm = $this->get_local_permission($userrole, $actionname, $localperms);
			}
			
			if (is_null($local_perm) || $userrole == '') {
				// If there is no role, or if there was no role-based override found
				$local_perm = $this->get_local_permission($usertype, $actionname, $localperms);
			}
		}
		
		if ($local_perm == 'default' || is_null($local_perm)) {
			if ($userrole != '') {
				$local_perm = $this->get_global_permission($userrole, $actionname);
			}
			
			if (is_null($local_perm) || $userrole == '') {
				$local_perm = $this->get_global_permission($usertype, $actionname);
			}
			
			if (is_null($local_perm)) {
				$local_perm = 'drive';
			}
			
		}
		return $local_perm;
	}

	public function get_resolved_auto_create_folders($post_type, $post_id=null) {
		$auto_create_folder = 'default';
		if ($post_id) {
			$local_options = get_post_meta( $post_id, 'gdm_controls', true );
		
			$auto_create_folder = ( isset($local_options['auto_create_folder']) && in_array($local_options['auto_create_folder'], array('yes','no','default')) 
							? $local_options['auto_create_folder'] : 'default' );
		}
			
		if ($auto_create_folder == 'default') {
			if (isset($this->gdm_options['gdm_auto_create'][$post_type])) {
				return $this->gdm_options['gdm_auto_create'][$post_type] == 'yes';
			}
		}
		return $auto_create_folder == 'yes';
	}
	
}
