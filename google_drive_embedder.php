<?php

/**
 * Plugin Name: Google Drive Embedder Enterprise
 * Plugin URI: http://wp-glogin.com/drive
 * Description: Easily browse for Google Drive documents and embed directly in your posts and pages. Extends the popular Google Apps Login plugin so no extra user authentication (or admin setup) is required. 
 * Version: 3.4
 * Author: Dan Lester
 * Author URI: http://wp-glogin.com/
 * License: Premium Paid per WordPress site and Google Apps domain
 * Network: true
 * 
 * Do not copy or redistribute without authorization from author Lesterland Ltd (contact@wp-glogin.com)
 * 
 * You need to have purchased a license to install this software on one website, to be used in 
 * conjunction with a Google Apps domain containing the number of users you specified when you
 * purchased this software.
 * 
 * You are not authorized to use or distribute this software beyond the single site license that you
 * have purchased.
 * 
 * You must not remove or alter any copyright notices on any and all copies of this software.
 * 
 * Please report violations to contact@wp-glogin.com
 * 
 * Copyright Lesterland Ltd, registered company in the UK number 08553880
 * 
 */

define( 'WPGLOGIN_GDM_STORE_URL', 'http://wp-glogin.com' );
define( 'WPGLOGIN_GDM_ITEM_NAME', 'Google Drive Embedder for WordPress Enterprise' );

require_once( plugin_dir_path(__FILE__).'/core/commercial_google_drive_embedder.php' );

class gdm_enterprise_google_drive_embedder extends commercial_google_drive_embedder {
	
	protected $PLUGIN_VERSION = '3.4';
	
	// Singleton
	private static $instance = null;
	
	public static function get_instance() {
		if (null == self::$instance) {
			self::$instance = new self;
		}
		return self::$instance;
	}
	
	protected function add_actions() {
		parent::add_actions();
		add_shortcode( 'google-drive-folder', Array($this, 'gdm_shortcode_display_folder') );
		
		add_filter( 'the_content', array($this, 'gdm_folders_the_content') );
		
		add_filter('gal_gather_serviceacct_reqs',  array($this, 'gdm_gather_serviceacct_reqs'));
		add_action('wp_ajax_gdm_file_upload', array($this, 'gdm_file_upload'));
		add_action('wp_ajax_nopriv_gdm_file_upload', array($this, 'gdm_file_upload'));
		add_action('wp_ajax_gdm_api_proxy', array($this, 'gdm_api_proxy'));
		add_action('wp_ajax_nopriv_gdm_api_proxy', array($this, 'gdm_api_proxy'));
		add_action('wp_ajax_gdm_cb_content', array($this, 'gdm_cb_content'));
		add_action('wp_ajax_nopriv_gdm_cb_content', array($this, 'gdm_cb_content'));
		
		add_action('wp_ajax_gdm_set_embed_parent_owner', array($this, 'gdm_set_embed_parent_owner'));
		add_action('wp_ajax_nopriv_gdm_set_embed_parent_owner', array($this, 'gdm_set_embed_parent_owner'));
		
		add_action('wp_ajax_gdm_api_list_root_folders', array($this, 'gdm_api_list_root_folders'));
		
		add_action( 'admin_init', array($this, 'setup_permissions_metaboxes') );
		
		$cfh = $this->get_controlled_folders_helper();
		$cfh->setup_post_types();
	}

	public function gdm_gather_scopes($scopes) {
		// Switch 'drive.readonly' (from basic/premium versions) for full 'drive' scope
		// (Seems unecessary that Google would list both to the user)
		return array_merge(preg_grep("/drive\.readonly/", parent::gdm_gather_scopes($scopes), PREG_GREP_INVERT), 
						   Array('https://www.googleapis.com/auth/drive', 'https://www.googleapis.com/auth/drive.install'));
	}
	
	public function gdm_gather_serviceacct_reqs($reqs_array) {
		$reqs_array[] = array('Google Drive Embedder Enterprise',
				array('https://www.googleapis.com/auth/drive'
						=> 'Create folders and view file lists'));
		return $reqs_array;
	}
	
	// Add capabilities
	public function gdm_activation_hook($network_wide) {
		parent::gdm_activation_hook($network_wide);
		
		global $wp_roles;
		
		if (class_exists('WP_Roles')) {
			if (!isset($wp_roles)) {
				$wp_roles = new WP_Roles();
			}
		}
		
		if (is_object($wp_roles)) {
			foreach ($wp_roles->role_objects as $key => $role) {
				if ($role->has_cap('manage_options')) {
					$role->add_cap('manage_control_folders');
				}
			}
		}
	}
	
	// Shortcode
	
	public function gdm_shortcode_display_folder($atts, $content=null) {
		
		$returnhtml = $this->output_folder($atts);
		
		if (!is_null($content)) {
			$returnhtml .= do_shortcode($content);
		}
		return $returnhtml;
	}
	
	protected function output_folder($atts) {
		$options = $this->get_option_gdm();
		$post_id = -1;
		$folderid = '';
		if (isset($atts['cfid']) && is_numeric($atts['cfid'])) {
			$post_id = $atts['cfid'];
			$post = get_post($post_id);
			if (!$post) {
				return '<b>google-drive-folder: post id '.htmlentities($post_id).' does not exist</b>';
			}
		}
		elseif (isset($atts['id'])) {
			$folderid = $atts['id'];
		}
		
		if ($folderid == '' && $post_id == -1) {
			return '<b>google-drive-folder requires an id or a cfid attribute</b>';
		}
		
		$showupload = (isset($atts['showupload']) ? $this->give_true_false($atts['showupload']) : '0');
		// Get perms for post id
		$resolved_perms = array();
		if ($post_id != -1) {			
			$folderid = $this->get_attached_folderid($post_id, $options['gdm_base_folder'], false);
			
			if (!$folderid) {
				return '<b>google-drive-folder with postid '.htmlentities($post_id).' does not have an associated folder</b>';
			}
			
			foreach (array('view', 'upload') as $action) {
				$ph = $this->get_permissions_helper();
				$resolved_perms[$action] = $ph->get_resolved_permission($action, $post_id);
			}
			
			// Default to whether showupload might be useful
			if (!isset($atts['showupload'])) {
				// Can override it
				if (in_array($resolved_perms['upload'], array('drive', 'yes'))) {
					$showupload = '1';
				}
			}
			if ($resolved_perms['upload'] == 'no') {
				$showupload = '0'; // Always no, so override whether they want the browse box or not
			}
		}
		else {
			$resolved_perms = array('view' => 'drive', 'upload' => 'drive');
		}
		
		$this->insert_scripts();
		
		$extraclass = isset($atts['border']) ? ' gdm-ent-folder-border' : '';
		
		// Width/height?
		$stylestr = 'overflow: scroll; ';
		if (isset($atts['width'])) {
			$stylestr .= 'width: '.(is_numeric($atts['width']) ? $atts['width'].'px' : $atts['width']).'; ';
		}
		if (isset($atts['height'])) {
			$stylestr .= 'height: '.(is_numeric($atts['height']) ? $atts['height'].'px' : $atts['height']).'; ';
		}
		
		// Calculate a nonce context
		$nonce_context = ($post_id > 0 ? $post_id : $folderid).( is_multisite() ? '-'.get_current_blog_id() : '' );
		$returnhtml = '<div class="gdm-ent-folder'.$extraclass.'" '
				.(isset($atts['title']) ? ' data-gdm-base-title="'.esc_attr($atts['title']).'"' : '')
				.' data-gdm-breadcrumbs="'.(isset($atts['breadcrumbs']) ? $this->give_true_false($atts['breadcrumbs']) : '1').'"'
				.(isset($atts['maxresults']) && is_numeric($atts['maxresults']) ? ' data-gdm-maxresults="'.esc_attr($atts['maxresults']).'"' : '')
				.($stylestr != '' ? ' style="'.$stylestr.'"' : '')
				.' data-gdm-subfolders="'.(isset($atts['subfolders']) ? $this->give_true_false($atts['subfolders']) : '1').'"'
				.' data-gdm-attachmentstitle="'.(isset($atts['subfolders']) ? $this->give_true_false($atts['attachmentstitle']) : '0').'"'
				.' data-gdm-nonce="'.wp_create_nonce('gdm-proxy-nonce-'.$nonce_context).'"'		
				.' data-gdm-showupload="'.$showupload.'"';
		if (isset($atts['columns'])) {
			$returnhtml	.= ' data-gdm-columns="'.esc_attr($atts['columns']).'"';
		}
		if (isset($atts['sort'])) {
			$returnhtml	.= ' data-gdm-sort="'.esc_attr($atts['sort']).'"';
		}
		if (!is_null($resolved_perms)) {
			foreach ($resolved_perms as $action => $value) {
				$returnhtml .= ' data-gdm-perms-'.$action.'="'.$value.'"';
			}
		}
		if ($post_id != -1) {
			$returnhtml .= ' data-gdm-postid="'.$post_id.'"';
		}
		if ($folderid != '') {
			$returnhtml .= ' data-gdm-id="'.esc_attr($folderid).'"';
		}
		$returnhtml .= '>Loading...</div>';
		return $returnhtml;
	}
	
	protected function give_true_false($attstr) {
		if (in_array(strtolower($attstr), array('true', '1', 'yes', 'on'))) {
			return '1';
		}
		return '0';
	}
	
	// Automatically insert on relevant post types
	public function gdm_folders_the_content($content) {
		$options = $this->get_option_gdm();
		$post_id = get_the_ID();
		if (is_singular() && is_main_query() 
						&& $this->want_auto_create_on_post_type($post_type = (string)get_post_type(), $post_id)) {
			if ($options['gdm_base_folder']) {
			
				$page_uri = get_page_uri($post_id);
				
				$new_content = '';
				try {
					$folderid = $this->get_attached_folderid($post_id, $options['gdm_base_folder']);
					$folderparams = array('border' => '1', 'subfolders' => '1', 'breadcrumbs' => '0', 
											'cfid' => $post_id, 'attachmentstitle' => '1');
					
					$new_content = $this->output_folder($folderparams);
				}
				catch (gdm_Drive_Exception $de) {
					$new_content = '<p class="gdm-err-msg">'.htmlentities($de->getMessage()).'</p>';
				}
				
				$content .= $new_content;
			}
			else {
				$content .= '<p class="gdm-err-msg">Admin must set a Drive base folder, '
							.' or uncheck Auto-create attachments folder on Pages/Posts, '
							.' in Settings -&gt; Google Drive Embedder</p>';
			}
		}
		
		return $content;
	}
	
	protected function get_attached_folderid($post_id, $base_folderid, $autocreate=true) {
		$options = $this->get_option_gdm();
		
		$post_type = get_post_type($post_id);
		$folderid_metakey = $post_type == 'gdm_cfolder' ? 'gdm-folder-id' : 'gdm-folder-id-'.$base_folderid;
		
		$folderid = get_post_meta($post_id, $folderid_metakey, true);
		if (!$folderid && $autocreate) {
			// Iterate over hierarchy
			
			$post = get_post($post_id);
			
			if ($post) {
				$parent_folderid = $base_folderid;
				if ($post->ancestors && count($post->ancestors)) {
					$ancestor = $post->ancestors[count($post->ancestors)-1];
					$parent_folderid = $this->get_attached_folderid($ancestor, $base_folderid);
				}
				else {
					if (is_multisite()) {
						// For multisite, we want a top-level subsite folder within the base folder
						$parent_folderid = $this->get_subsite_folderid($base_folderid);
					}
					// Otherwise, for single site, the page hierarchy can begin at the base folder
				}
				
				if ($parent_folderid) {
					// Create current level of folder inside parent
					$dh = $this->get_drive_helper();
					
					$post_title = trim($post->post_title);
					if ($post_title == '') {
						$post_title = trim($post->post_name);
						if ($post_title == '') {
							$post_title = 'Post ID '.$post_id;
						}
					}
					
					$post_title = html_entity_decode($post_title, ENT_QUOTES, 'UTF-8');
					
					$author_email = '';
					if ($options['gdm_drive_set_attfolder_post_writer']) {
						// Set Drive WRITER to the post author (otherwise it would remain under Service Account)
						$author_email = $this->get_post_author_email($post);
					}
					
					$folderid = $dh->create_folder($parent_folderid, $post_title, '', $author_email, 
													apply_filters('gde_gather_custom_properties', null, 'post', $post_id));
					if ($folderid) {
						update_post_meta($post_id, $folderid_metakey, $folderid);
					}
				}
			}
		} 
		return $folderid ? $folderid : '';
	}
	
	protected function get_post_author_email($post) {
		$author_id = $post->post_author;
		if ($author_id > 0) {
			$author = get_user_by('id', $author_id);
			if ($author) {
				return $author->user_email;
			}
		}
		return '';
	}
	
	// Multisite only
	protected function get_subsite_folderid($base_folderid) {
		$blog_id = get_current_blog_id();
		$folderid_metakey = 'gdm-folder-id-'.$base_folderid;
		$folderid = get_option($folderid_metakey);
		if (!$folderid) {
			// Create a folder for the current subsite
			$blog_title = html_entity_decode(trim(get_bloginfo('name')), ENT_QUOTES, 'UTF-8');
			if ($blog_title == '') {
				$blog_title = 'Blog ID '.get_current_blog_id();
			}
			
			$dh = $this->get_drive_helper();
			$folderid = $dh->create_folder($base_folderid, $blog_title, '', '', apply_filters('gde_gather_custom_properties', null, 'blog', $blog_id));
			update_option($folderid_metakey, $folderid);
		}
		return $folderid;
	}
	
	protected $drive_helper = null;
	protected function get_drive_helper() {
		if ($this->drive_helper === null) {
			if (!class_exists('gdm_drive_helper')) {
				require_once( plugin_dir_path(__FILE__).'/core/folders/drive_helper.php' );
			}
			$this->drive_helper = new gdm_drive_helper();
		}
		return $this->drive_helper;
	}

	protected $permissions_helper = null;
	protected function get_permissions_helper() {
		if ($this->permissions_helper === null) {
			if (!class_exists('gdm_control_permissions_helper')) {
				require_once( plugin_dir_path(__FILE__).'/core/folders/permissions_helper.php' );
			}
			$this->permissions_helper = new gdm_control_permissions_helper($this->get_option_gdm());
		}
		return $this->permissions_helper;
	}
	
	protected $controlled_folders = null;
	protected function get_controlled_folders_helper() {
		if ($this->controlled_folders === null) {
			if (!class_exists('gdm_controlled_folders')) {
				require_once( plugin_dir_path(__FILE__).'/core/folders/controlled_folders.php' );
			}
			$this->controlled_folders = new gdm_controlled_folders($this->get_option_gdm(), $this->get_permissions_helper());
		}
		return $this->controlled_folders;
	}
	
	public function setup_permissions_metaboxes() {
		global $pagenow;
		if ($pagenow == 'post.php' || $pagenow == 'post-new.php') {
			$ph = $this->get_permissions_helper();
			$ph->setup_metaboxes();
		}
	}
	
	protected function want_auto_create_on_post_type($post_type, $post_id) {
		$ph = $this->get_permissions_helper();
		return $ph->get_resolved_auto_create_folders($post_type, $post_id);
	}
	
	protected $inserted_scripts = false;
	protected function insert_scripts() { // Front-end scripts
		if ($this->inserted_scripts) {
			return;
		}
		wp_enqueue_script( 'gdm_folders_js' );
		wp_localize_script( 'gdm_folders_js', 'gdm_trans', $this->get_translation_array() );
		wp_enqueue_script( 'google_js_api' );
		wp_enqueue_style( 'gdm_folders_css' );
		wp_enqueue_style( 'gdm_ui_folders_css' );
		wp_enqueue_style( 'gdm_colorbox_css' );
		$this->inserted_scripts = true;
	}
	
	protected function get_translation_array() {
		$options = $this->get_option_gdm();
		$ph = $this->get_permissions_helper();
		
		$post_id = null;
		global $post;
		if ($post && is_object($post)) {
			$post_id = $post->ID;
		}
		
		return array_merge(parent::get_translation_array(), array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'wp_roles' => $ph->get_wp_roles_array(),
			'cf_register_nonce' => wp_create_nonce('gdm-cf-register'),
			'gdm_drive_set_embed_sa_owner' => $options['gdm_drive_set_embed_sa_owner'],
			'gdm_drive_set_embed_parent' => $options['gdm_drive_set_embed_parent'],
			'post_id' => $post_id
		));
	}
	
	public function enqueue_rolepermissions_scripts() {
		// E.g. needed on Controlled Folder admin pages
		wp_enqueue_script( 'gdm_rolepermissions_js' );
		wp_enqueue_style( 'gdm_ui_folders_css' );
		wp_localize_script( 'gdm_rolepermissions_js', 'gdm_trans', $this->get_translation_array() );
	}
	
	public function enqueue_admin_settings_scripts() {
		wp_enqueue_script( 'gdm_admin_settings_js' );
		wp_enqueue_script( 'gdm_rolepermissions_js' );
		wp_enqueue_style( 'gdm_ui_folders_css' );
		parent::enqueue_admin_settings_scripts();
	}
	
	public function gdm_admin_load_scripts() {
		parent::gdm_admin_load_scripts();
		wp_enqueue_script( 'gdm_rolepermissions_js' );
	}
	
	public function gdm_register_scripts() {
		parent::gdm_register_scripts();
		// http://tablesorter.com/
		wp_register_script( 'gdm_tablesorter_js', $this->my_plugin_url().'js/folders/jquery.tablesorter.min.js', array('jquery') );
		wp_register_script( 'gdm_dateformat_js', $this->my_plugin_url().'js/folders/jquery-dateFormat.js', array('jquery') );
		wp_register_script( 'gdm_colorbox_js', $this->my_plugin_url().'js/folders/jquery.colorbox-min.js', array('jquery') );
		wp_register_script( 'gdm_premium_drivefile_js', $this->my_plugin_url().'js/gdm-premium-drivefile.js', array('jquery') );
		
		wp_register_script( 'gdm_folders_js', $this->my_plugin_url().'js/folders/gdm-folders.js', 
				array('jquery', 'gdm_tablesorter_js', 'gdm_dateformat_js', 'gdm_colorbox_js', 'gdm_premium_drivefile_js') );
		
		wp_register_script( 'google_js_api', 'https://apis.google.com/js/client.js?onload=gdmFolderGoogleClientLoad', 
										array('gdm_folders_js') );

		wp_register_script( 'gdm_rolepermissions_js', $this->my_plugin_url().'js/folders/jquery.rolepermissions.js', array('jquery') );
		
		wp_register_script( 'gdm_admin_settings_js', $this->my_plugin_url().'js/folders/gdm-admin-settings.js', array('jquery') );
		
		wp_register_style( 'gdm_folders_css', $this->my_plugin_url().'css/folders/gdm-folders.css' );
		wp_register_style( 'gdm_ui_folders_css', $this->my_plugin_url().'css/folders/gdm-ui-folders.css' );
		wp_register_style( 'gdm_colorbox_css', $this->my_plugin_url().'css/folders/gdm-colorbox.css' );
	}
	
	protected function allow_non_iframe_folders() {
		return true;
	}
	
	// File uploads
	
	public function gdm_file_upload() {
		$options = $this->get_option_gdm();
				
		if (!isset($_POST['folderId'])) {
			die(json_encode(array('error' => array('message' => 'No folderId provided'))));
		}
		
		$parent_folderid = $_POST['folderId'];
		
		if (!isset($_POST['postId']) || !is_numeric($_POST['postId'])) {
			die(json_encode(array('error' => array('message' => 'No valid postId provided'))));
		}
		
		$post_id = $_POST['postId'];
		
		$nonce_context = $post_id.( is_multisite() ? '-'.get_current_blog_id() : '' );
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gdm-proxy-nonce-'.$nonce_context)) {
			die (json_encode(Array('error'=> array('message' => 'Security problem trying to call Drive proxy (invalid nonce)'))));
		}
		
		// Need to check folderid hasn't been fiddled
		$dh = $this->get_drive_helper();
		try {
			$checkfolderid = $this->get_attached_folderid($post_id, $options['gdm_base_folder']);
			if (!$dh->is_ancestor($checkfolderid, $parent_folderid)) {
				die(json_encode(array('error' => array('message' => 'Folder id '.$parent_folderid.' is not a descendant of folder '.$checkfolderid))));
			}
		}
		catch (gdm_Drive_Exception $de) {
			die(json_encode(array('error' => array('message' => 'Problem obtaining folder id for post '.$post_id.": ".$de->getMessage()))));
		}
		
		// Need to get post_id and go via drive_helper
		$ph = $this->get_permissions_helper();
		if ($ph->get_resolved_permission('upload', $_POST['postId']) != 'yes') {
			die(json_encode(array('error' => array('message' => 'You do not have permission to upload to this controlled folder!'))));
		}
				
		try {
			$fileid = $dh->upload_file($parent_folderid, 'contentType', 'file');
			if ($fileid) {
				die(json_encode(array('fileid' => $fileid)));
			}
		}
		catch (gdm_Drive_Exception $de) {
			die(json_encode(array('error' => array('message' => $de->getMessage()))));
		}
				
		die(json_encode(array('error' => array('message' => 'Not sure what went wrong!'))));
	}
	
	public function gdm_api_proxy() {
		$options = $this->get_option_gdm();
		
		if (!isset($_POST['postId']) || !is_numeric($_POST['postId'])) {
			die(json_encode(array('error' => array('message' => 'No valid postId provided'))));
		}
		
		$post_id = $_POST['postId'];
		
		$nonce_context = $post_id.( is_multisite() ? '-'.get_current_blog_id() : '' );
		
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gdm-proxy-nonce-'.$nonce_context)) {
			die (json_encode(Array('error'=> array('message' => 'Security problem trying to call Drive proxy (invalid nonce)'))));
		}
		
		$path = '';
		$params = array();
		if (isset($_POST['options'])) {
			if (isset($_POST['options']['path'])) {
				$path = $_POST['options']['path'];
				if (isset($_POST['options']['params'])) {
					$params = $_POST['options']['params'];
				}
			}
		}
		if ($path == '') {
			die(json_encode(array('error' => array('message' => 'No path was specified in options'))));
		}
		
		// Need to check folderid hasn't been fiddled
		$dh = $this->get_drive_helper();
		try {
			//$post_type = get_post_type($post_id);
			$checkfolderid = $this->get_attached_folderid($post_id, $options['gdm_base_folder']);
			
			$incomingfolderid = '';
			if ($path == '/drive/v2/files/') {
				$q = isset($params['q']) ? $params['q'] : '';
				$incomingfolderid = $dh->get_folderid_from_q($q);
			}
			else {
				$incomingfolderid = $dh->get_folderid_from_path($path);
			}
			
			if (!$dh->is_ancestor($checkfolderid, $incomingfolderid)) {
				die(json_encode(array('error' => array('message' => 'Folder id '.$incomingfolderid.' is not a descendant of folder '.$checkfolderid))));
			}
		}
		catch (gdm_Drive_Exception $de) {
			die(json_encode(array('error' => array('message' => 'Problem obtaining folder id for post '.$post_id.": ".$de->getMessage()))));
		}
		
		$ph = $this->get_permissions_helper();
		if ($ph->get_resolved_permission('view', $post_id) != 'yes') {
			die(json_encode(array('error' => array('message' => 'You do not have permission to view this controlled folder!'))));
		}
		
		try {
			$json = $dh->proxy_api($path, $params);
			die(json_encode($json));
		}
		catch (gdm_Drive_Exception $de) {
			die(json_encode(array('error' => array('message' => $de->getMessage()))));
		}
	
		die(json_encode(array('error' => array('message' => 'Not sure what went wrong!'))));
	}
	
	public function gdm_set_embed_parent_owner() {
		$options = $this->get_option_gdm();
		
		if (!isset($_POST['drivefileid'])) {
			die(json_encode(array('error' => array('message' => 'No drivefileid provided'))));
		}
		
		$drivefileid = $_POST['drivefileid'];
		
		if (!isset($_POST['postId']) || !is_numeric($_POST['postId']) || $_POST['postId'] <= 0) {
			die(json_encode(array('error' => array('message' => 'No valid postId provided'))));
		}
		
		$post_id = $_POST['postId'];
		
		if (!isset($_POST['cf_register_nonce']) || !wp_verify_nonce($_POST['cf_register_nonce'], 'gdm-cf-register')) {
			die (json_encode(Array('error'=> array('message' => 'Security problem trying to call gdm_set_embed_parent_owner (invalid nonce)'))));
		}

		$current_user_email = '';
		$user = wp_get_current_user();
		if ($user && $user->exists()) {
			$current_user_email = $user->user_email;
		}
		else {
			// Assume not logged in, in which case they must be interacting with an Attachments Folder,
			// And we should check they are allowed to upload at all.
			// Need to get post_id and go via drive_helper
			$ph = $this->get_permissions_helper();
			if (!in_array($ph->get_resolved_permission('upload', $post_id), array('yes','drive'))) {
				die(json_encode(array('error' => array('message' => 'You do not have permission to upload to this folder!'))));
			} 
		}
		
		$want_parent_folderid = '';
		if ($options['gdm_drive_set_embed_parent'] && $options['gdm_base_folder'] != '') {
			// if ($this->want_auto_create_on_post_type($post_type = (string)get_post_type($post_id), $post_id)){
			// Actually - create the folder even if we don't normally display it
				
			// Want to add the post's Attachment Folder as an extra Drive parent for drivefileid
			$want_parent_folderid = $this->get_attached_folderid($post_id, $options['gdm_base_folder']);
		}
		
		// Want to change drivefileid's owner to Service Account, demote old owners to editors?
		
		if (!$options['gdm_drive_set_embed_sa_owner'] && $want_parent_folderid == '') {
			die(json_encode(array('success' => true)));
		}
		
		try {
			$dh = $this->get_drive_helper();
			$json = $dh->set_embed_parent_owner($drivefileid, $want_parent_folderid, 
												$options['gdm_drive_set_embed_sa_owner'],
												$current_user_email,
												apply_filters('gde_gather_custom_properties', null, 'post', $post_id)
												);
			die(json_encode($json));
		}
		catch (gdm_Drive_Exception $de) {
			die(json_encode(array('error' => array('message' => $de->getMessage()))));
		}
	}
	
	public function gdm_api_list_root_folders() {
		if (!current_user_can('manage_options')) {
			die(json_encode(array('error' => array('message' => 'You are not authorized to manage_options'))));
		}
		
		$path = '/drive/v2/files/';
		$params = array('q' => "'root' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder'");
		
		try {
			$dh = $this->get_drive_helper();
			$json = $dh->proxy_api($path, $params);
			die(json_encode($json));
		}
		catch (gdm_Drive_Exception $de) {
			die(json_encode(array('error' => array('message' => $de->getMessage()))));
		}
	}
	
	public function gdm_cb_content() {

	 	if (isset($_GET['embed']) && $_GET['embed'] != '') {
	 		if (isset($_GET['extra']) && $_GET['extra'] == 'image') {
	 			// Should now be taken care of in JS, so shouldn't end up here
	 			$aspectratio = '56';
	 			$maxwidth = '100%';
	 			$maxheight = '100%';
	 			if (isset($_GET['width']) && isset($_GET['height']) && is_numeric($_GET['width']) && is_numeric($_GET['height']) && $_GET['width'] > 0) {
	 				$aspectratio = (int)(100 * $_GET['height'] / $_GET['width']);
	 				$maxwidth = $_GET['width'].'px';
	 				$maxheight = $_GET['height'].'px';
	 			} 
	 			echo '<div style="position: relative; padding-bottom: '.$aspectratio.'%; padding-top: 25px; height: 0;">';
	 			echo '<img src="'.esc_attr($_GET['embed']).'" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; max-width: '.$maxwidth.'; max-height: '.$maxheight.'"></img>';
	 			echo '</div>';
	 		}
	 		else {
				echo '<iframe src="'.esc_attr($_GET['embed']).'"></iframe>';
	 		}
		}
		else {
			echo '<div class="gdm-nopreview"><p>No preview is available</p></div>';
		}
		exit();
	}
	
	// Dialog options for folder
	
	protected function admin_footer_extra() {
		parent::admin_footer_extra();
		?>
		<div id="gdm-more-options-folders" class="gdm-more-options" style="display: none;">
		
			<div class="gdm-foldertypes-div">
			<input type="radio" name="gdm-foldertype" id="gdm-foldertype-drive" checked="checked" />
			<label for="gdm-foldertype-drive">Require user to sign in to Drive</label>
			
				<div>
					<input type="checkbox" name="gdm-folder-showupload" id="gdm-folder-showupload" />
					<label for="gdm-folder-showupload">Show Browse button for file uploads</label>
					<br/>
					<input type="checkbox" name="gdm-folder-breadcrumbs" id="gdm-folder-breadcrumbs" checked />
					<label for="gdm-folder-breadcrumbs">Show trail of subfolder hierarchy</label>
				</div>
			</div>
			
			<div class="gdm-foldertypes-div">
			<input type="radio" name="gdm-foldertype" id="gdm-foldertype-control" <?php echo $this->can_user_register_cf() ? '' : 'disabled="disabled"'; ?> />
			<label for="gdm-foldertype-control">Register a Controlled Folder</label>
			
			<?php 
			if ($this->can_user_register_cf()) {
				$ph = $this->get_permissions_helper();
				$ph->output_permissions_table('', null, true); 
			}
			?>
			
			</div>
			
			<div class="gdm-foldertypes-div">
			<input type="radio" name="gdm-foldertype" id="gdm-foldertype-iframe" />
			<label for="gdm-foldertype-iframe">Embed as iframe</label>
			</div>
		
		</div>
		<?php
	}
	
	protected function can_user_register_cf() {
		return current_user_can('manage_control_folders');
	}
	
	// Options
	
	protected function output_instructions_button() {
		?>
		<p><a href="http://wp-glogin.com/drive/enterprise-instructions/?utm_source=EntDriveSetup&utm_medium=freemium&utm_campaign=Drive" id="gdm-personalinstrlink" class="button-secondary" target="gdminstr">
			Click here for setup instructions
			</a>
		</p>
		<?php
	}
	
	protected function draw_admin_settings_tabs_start() {
		?>
			<a href="#setup" id="setup-tab" class="nav-tab">Setup</a>
			<a href="#folder" id="folder-tab" class="nav-tab">Folder Controls</a>
			<a href="#ownership" id="ownership-tab" class="nav-tab">Drive Ownership</a>
		<?php 
	}
	
	protected function gdm_extrasection_text() {
		$options = $this->get_option_gdm();
		
		// SETUP
		
		echo '<div id="setup-section" class="gdmtab active">';
		
		echo '<h3>Drive Base Folder</h3>';
		echo '<p><i>Create or select a top-level folder in Drive to serve as the base for a hierarchy of folders'
 				.' for file attachments on posts/pages.</i></p>';
		
		echo '<label for="input_gdm_base_folder" class="textinput big">Base Drive Folder</label> &nbsp;';
		
		$display_style = '';
		echo '<span>';
		
		if ($options['gdm_base_folder'] != '') {
			echo '<a href="https://drive.google.com/drive/#folders/'.esc_attr($options['gdm_base_folder']).'" target="_blank">'
 					.htmlentities($options['gdm_base_folder_title'] != '' ? $options['gdm_base_folder_title'] : $options['gdm_base_folder']).'</a>';

			echo '&nbsp; <input type="button" id="gdm-change-base-folder" class="button"
					value="Change..." />';
			
			$display_style = ' style="display: none;"';
		}
		
		?>
		<div id="gdm-base-folder-selectdiv" <?php echo $display_style; ?>>
		
			<div class="gdm-base-folder-radiodiv">
				<input type="radio" name="<?php echo $this->get_options_name(); ?>[gdm_base_folder_type]" id="gdm-base-folder-type-new" value="new" checked="checked" />
				<label for="gdm-base-folder-type-new">Create new folder named </label>
				<input id="input_gdm_base_folder_new" class="textinput" name="<?php echo $this->get_options_name(); ?>[gdm_base_folder_new]" size="40" type="text" value="" />
			</div>
			
			<div class="gdm-base-folder-radiodiv">
				<input type="radio" name="<?php echo $this->get_options_name(); ?>[gdm_base_folder_type]" id="gdm-base-folder-type-existing" value="existing" />
				<label for="gdm-base-folder-type-existing">Use an existing folder </label>
				<select id="input_gdm_base_folder_existing" class="textinput" name="<?php echo $this->get_options_name(); ?>[gdm_base_folder_existing]">
				<option value="null">&lt;Loading...&gt;</option>
				</select>
				<input id="input_gdm_base_folder_existing_title" name="<?php echo $this->get_options_name(); ?>[gdm_base_folder_existing_title]" type="hidden" value="" />
				<p id="gdm-base-folder-existing-error"></p>
			</div>
			
			<input type="hidden" name="<?php echo $this->get_options_name(); ?>[gdm_base_folder]" value="<?php echo esc_attr($options['gdm_base_folder']); ?>" />
			<input type="hidden" name="<?php echo $this->get_options_name(); ?>[gdm_base_folder_title]" value="<?php echo esc_attr($options['gdm_base_folder_title']); ?>" />
					
		</div>
		
		<?php 
			if ($options['gdm_base_folder'] != '') {
				echo '<p><i>Note that changing the base folder will hide any existing post/page attachments.</i></p>';
			}
		?>

		<?php 
		
		echo '</span>';
				
		echo "</div>";
		
		// FOLDER OPTIONS
		
		echo '<div id="folder-section" class="gdmtab">';
		
		echo '<h3>Page/Post Attachment Folders</h3>';
		echo '<p><i>Folders can be automatically created in your Drive hierarchy to act as file storage areas'
 			 .' at the bottom of pages and posts. <br/>Requires a Service Account to be configured in Google Apps Login settings,'
 			 .' and a Base Folder to be set in the Setup tab here.</i></p>';
		
		$ph = $this->get_permissions_helper();
		
		foreach ($ph->get_public_post_type_names() as $post_type) {
		
			$gdm_auto_create_post_type = isset($options['gdm_auto_create']) && is_array($options['gdm_auto_create'])
										&& isset($options['gdm_auto_create'][$post_type]) && $options['gdm_auto_create'][$post_type];
		
			echo '<label for="input_gdm_auto_create_'.$post_type.'" class="textinput big">Auto-create attachments folder on '.$post_type.'</label> &nbsp;';
			echo "<input id='input_gdm_auto_create_'.$post_type.'' class='checkbox' name='".$this->get_options_name()."[gdm_auto_create_".$post_type."]' type='checkbox' "
					.($gdm_auto_create_post_type ? 'checked ' : '')."'/>";
			echo "<br />";
		}
		
		echo "<br />";
		
		echo '<label for="input_gdm_allow_overrides_autoc" class="textinput big">Allow content authors to override these auto-creation settings</label> &nbsp;';
		echo "<input id='input_gdm_allow_overrides_autoc' class='checkbox' name='".$this->get_options_name()."[gdm_allow_overrides_autoc]' type='checkbox' "
				.($options['gdm_allow_overrides_autoc'] ? 'checked ' : '')."'/>";
		
		echo '<br/><br/>';
		
		echo '<h3>Default Folder Permissions</h3>';
		echo '<p><i>These permissions serve as defaults for page/post attachment folders, plus controlled folders created via the Add Google File button.</i></p>';
		
		$ph = $this->get_permissions_helper();
		$ph->output_permissions_table($this->get_options_name(), $options['gdm_permissions']);
		
		echo '<p><i>Always = always allow using Service Account <br /> Drive = allow only if user has a Google Drive account with appropriate sharing settings <br /> Never = never allow.</i></p>';
		
		echo '<br/>';
		
		echo '<label for="input_gdm_allow_overrides_perms" class="textinput big">Allow page/post authors to override attachment folder permissions</label> &nbsp;';
		echo "<input id='input_gdm_allow_overrides_perms' class='checkbox' name='".$this->get_options_name()."[gdm_allow_overrides_perms]' type='checkbox' "
				.($options['gdm_allow_overrides_perms'] ? 'checked ' : '')."'/>";
		
		echo '</div>';
		
		
		// DRIVE OWNERSHIP
		
		echo '<div id="ownership-section" class="gdmtab">';
		
		echo '<h3>Drive Files Ownership</h3>';
		echo '<p><i>Google Drive Embedder can use your Service Account to change ownership and parent folders of files/folders that are used within your WordPress site. '
				.' This can help you keep track of which files and folders in Drive are relevant to your site, and to ensure a senior admin owns all such files/folders.</i></p>';
		
		echo '<label for="input_gdm_drive_set_attfolder_post_writer" class="textinput big">Set post/page author as writer of Attachment Folder when folder is auto-created in Drive</label> &nbsp;';
		echo "<input id='gdm_drive_set_attfolder_post_writer' class='checkbox' name='".$this->get_options_name()."[gdm_drive_set_attfolder_post_writer]' type='checkbox' "
				.($options['gdm_drive_set_attfolder_post_writer'] ? 'checked ' : '')."'/>";
		echo "<br /><br />";
		
		echo '<label for="input_gdm_drive_set_embed_sa_owner" class="textinput big">Embedded files/folders should become owned by Service Account admin in Drive (original owner becomes writer)</label> &nbsp;';
		echo "<input id='gdm_drive_set_embed_sa_owner' class='checkbox' name='".$this->get_options_name()."[gdm_drive_set_embed_sa_owner]' type='checkbox' "
				.($options['gdm_drive_set_embed_sa_owner'] ? 'checked ' : '')."'/>";
		echo "<br /><br />";
		
		echo '<label for="input_gdm_drive_set_embed_parent" class="textinput big">Embedded files/folders should have the page/post Attachment Folder added as a parent in Drive</label> &nbsp;';
		echo "<input id='gdm_drive_set_embed_parent' class='checkbox' name='".$this->get_options_name()."[gdm_drive_set_embed_parent]' type='checkbox' "
				.($options['gdm_drive_set_embed_parent'] ? 'checked ' : '')."'/>";
		echo "<br /><br />";
		
		echo '</div>';
		
		?>
		<script type="text/javascript">
			jQuery(document).ready(function ($) {
				// Main plugin settings page
				$('table.gdm-permissions-table').rolepermissions();
			});
		</script>
		<?php 
		
	}
		
	protected function get_default_options() {
		return array_merge( parent::get_default_options(),
				Array('gdm_base_folder' => '',
					  'gdm_base_folder_title' => '',
					  'gdm_auto_create' => array('post' => false, 'page' => false),
					  'gdm_permissions' => array(),
					  'gdm_allow_overrides_autoc' => false,
					  'gdm_allow_overrides_perms' => false,
					  'gdm_drive_set_attfolder_post_writer' => false,
					  'gdm_drive_set_embed_sa_owner' => false,
					  'gdm_drive_set_embed_parent' => false));
	}
	
	public function gdm_options_validate($input) {
		$newinput = parent::gdm_options_validate($input);
		$newinput['gdm_base_folder'] = isset($input['gdm_base_folder']) ? trim($input['gdm_base_folder']) : '';
		
		if (isset($input['gdm_auto_create']) && is_array($input['gdm_auto_create'])) {
			// Coming through update_options check on multisite, so just preserve the final style
			$newinput['gdm_auto_create'] = $input['gdm_auto_create'];
		}
		else {
			// Get settings based on HTML inputs
			$newinput['gdm_auto_create'] = array();
			$ph = $this->get_permissions_helper();
			
			foreach ($ph->get_public_post_type_names() as $post_type) {
				$newinput['gdm_auto_create'][$post_type] = (isset($input['gdm_auto_create_'.$post_type]) && $input['gdm_auto_create_'.$post_type]);
			}
		}

		if (isset($input['gdm_permissions']) && is_array($input['gdm_permissions'])) {
			// Coming through update_options check on multisite, so just preserve the final style
			$newinput['gdm_permissions'] = $input['gdm_permissions'];
		}
		else {
			// Get settings based on HTML inputs
			$alldefault = true;
			$ph = $this->get_permissions_helper();
			$perms = $ph->read_permissions_inputs($input, '', $alldefault, 'drive');
	
			$newinput['gdm_permissions'] = $perms;
		}
		
		$newinput['gdm_allow_overrides_autoc'] = isset($input['gdm_allow_overrides_autoc']) && $input['gdm_allow_overrides_autoc'];
		$newinput['gdm_allow_overrides_perms'] = isset($input['gdm_allow_overrides_perms']) && $input['gdm_allow_overrides_perms'];
		
		$newinput['gdm_drive_set_attfolder_post_writer'] = isset($input['gdm_drive_set_attfolder_post_writer']) && $input['gdm_drive_set_attfolder_post_writer'];
		$newinput['gdm_drive_set_embed_sa_owner'] = isset($input['gdm_drive_set_embed_sa_owner']) && $input['gdm_drive_set_embed_sa_owner'];
		$newinput['gdm_drive_set_embed_parent'] = isset($input['gdm_drive_set_embed_parent']) && $input['gdm_drive_set_embed_parent'];
		
		if (isset($input['gdm_base_folder_type']) && $input['gdm_base_folder_type'] == 'new' 
				&& isset($input['gdm_base_folder_new']) && trim($input['gdm_base_folder_new']) != '') {
			// Create a new Drive folder
			
			try {
				$dh = $this->get_drive_helper();
				$newfoldername = stripslashes(trim($input['gdm_base_folder_new']));
				$folderid = $dh->create_folder('root', $newfoldername, '', '', apply_filters('gde_gather_custom_properties', null, 'root', null));
				
				if ($folderid) {
					$newinput['gdm_base_folder'] = $folderid;
					$newinput['gdm_base_folder_title'] = $newfoldername;
				}
				else {
					add_settings_error(
						'gdm_base_folder',
						'folder_error',
						self::get_error_string('gdm_base_folder|folder_error'),
						'error'
					);
				}
			}
			catch (gdm_Drive_Exception $de) {
				error_log("gdm_Drive_Exception creating Drive folder: ".$de->getMessage());
				add_settings_error(
					'gdm_base_folder',
					'service_account',
					self::get_error_string('gdm_base_folder|service_account'),
					'error'
				);
			}
			
		}
		elseif (isset($input['gdm_base_folder_type']) && $input['gdm_base_folder_type'] == 'existing' 
				&& isset($input['gdm_base_folder_existing']) && $input['gdm_base_folder_existing'] != '' && $input['gdm_base_folder_existing'] != 'null') {
			// Selected an existing drive folder
			
			$newinput['gdm_base_folder'] = $input['gdm_base_folder_existing'];
			$newinput['gdm_base_folder_title'] = isset($input['gdm_base_folder_existing_title']) &&  $input['gdm_base_folder_existing_title'] != '' 
							? $input['gdm_base_folder_existing_title'] 
							: $input['gdm_base_folder_existing'];
		}
		else {
			// Read in previous hidden values
			$newinput['gdm_base_folder'] = isset($input['gdm_base_folder']) ? $input['gdm_base_folder'] :'';
			$newinput['gdm_base_folder_title'] = isset($input['gdm_base_folder_title']) ? $input['gdm_base_folder_title'] :'';
		}
		
		return $newinput;
	}
	
	protected function get_error_string($fielderror) {
		$enterprise_local_error_strings = Array(
				'gdm_base_folder|folder_error' => 'Error creating new Drive folder',
				'gdm_base_folder|service_account' => 'Unable to create Drive folder - please configure Service Account in Google Apps Login setup'
		);
		if (isset($enterprise_local_error_strings[$fielderror])) {
			return $enterprise_local_error_strings[$fielderror];
		}
		return parent::get_error_string($fielderror);
	}
		
	// AUX
	
	protected function my_plugin_basename() {
		$basename = plugin_basename(__FILE__);
		if ('/'.$basename == __FILE__) { // Maybe due to symlink
			$basename = basename(dirname(__FILE__)).'/'.basename(__FILE__);
		}
		return $basename;
	}
	
	protected function my_plugin_url() {
		$basename = plugin_basename(__FILE__);
		if ('/'.$basename == __FILE__) { // Maybe due to symlink
			return plugins_url().'/'.basename(dirname(__FILE__)).'/';
		}
		// Normal case (non symlink)
		return plugin_dir_url( __FILE__ );
	}
	
}

// Global accessor function to singleton
function GoogleDriveEmbedder() {
	return gdm_enterprise_google_drive_embedder::get_instance();
}

// Initialise at least once
GoogleDriveEmbedder();

?>