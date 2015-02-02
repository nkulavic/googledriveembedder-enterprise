<?php

require_once( plugin_dir_path(__FILE__).'/core_google_drive_embedder.php' );

class commercial_google_drive_embedder extends core_google_drive_embedder {
	
	// Premium specific
	
	protected function get_extra_js_name() {
		return 'premium';
	}
	
	public function gdm_gather_scopes($scopes) {
		return array_merge(parent::gdm_gather_scopes($scopes), Array('https://www.googleapis.com/auth/calendar.readonly'));
	}
	
	// Shortcodes
	
	public function gdm_shortcode_display_drivefile($atts, $content=null) {
		if (!isset($atts['url'])) {
			return '<b>gdm-drivefile requires a url attribute</b>';
		}
		$url = $atts['url'];
	
		$linkstyle = isset($atts['style']) && in_array($atts['style'], Array('normal', 'plain', 'download', 'embed'))
							? $atts['style'] : 'normal';
		$returnhtml = '';
	
		$extra = isset($atts['extra']) ? $atts['extra'] : '';
	
		if ($linkstyle == 'embed' && in_array($extra, Array('folder', 'image', 'calendar'))) {
	
			switch ($extra) {
				case 'folder':
					$title = isset($atts['title']) ? $atts['title'] : 'Drive Folder'; // Should be html-encoded already
						
					$width = isset($atts['width']) ? $atts['width'] : '100%';
					$height = isset($atts['height']) ? $atts['height'] : '400';
						
					if (is_numeric($width)) { $width .= 'px'; }
					if (is_numeric($height)) { $height .= 'px'; }
						
					$folderurl = str_replace('embeddedfolderview', 'folderview', $url);
					$returnhtml =
					"<div class=\"gdm-user-folder-embed\" style=\"width: ${width}; height: ${height}; overflow: hidden; border: 1px solid black;\" >"
					."<div style=\"width: 100%; border-bottom: 1px solid black; padding: 2px 8px;\">"
							."<a href=\"${folderurl}\" target=\"_blank\">${title}</a></div>"
							."<div style=\"width: 100%; height: 100%; overflow: hidden;\" >"
									."<iframe width='100%' height='100%' frameborder='0' scrolling='yes' src='${url}'></iframe>"
									."</div></div>"		;
					break;
	
					case 'image':
					$width = isset($atts['width']) ? $atts['width'] : '';
					$height = isset($atts['height']) ? $atts['height'] : '';
							$sizeattrs = '';
							if ($width) {
							$sizeattrs .= " width=\"${width}\"";
					}
					if ($height) {
						$sizeattrs .= " height=\"${height}\"";
					}
					$returnhtml = "<img src=\"${url}\"${sizeattrs} />";
					break;
					
				case 'calendar':
					$width = isset($atts['width']) ? $atts['width'] : '100%';
					$height = isset($atts['height']) ? $atts['height'] : '400';
					$returnhtml = "<iframe src='${url}' style='border: 0' width='${width}' height='${height}' "
						."frameborder='0' scrolling='no'></iframe>";
						
					break;
					
			}
						
		}
		else {
			$returnhtml = parent::gdm_shortcode_display_drivefile($atts, $content);
		}
				
		if (!is_null($content)) {
			$returnhtml .= do_shortcode($content);
		}
		return $returnhtml.(is_null($content));
	}
	
	protected function get_translation_array() {
		$options = $this->get_option_gdm();
		return array_merge(parent::get_translation_array(),
			Array('ical_png_url' => $this->my_plugin_url().'images/icalsmalltrans.png',
				  'gdm_allow_account_switch' => (bool)$options['gdm_allow_account_switch']));
	}
	
	protected function admin_footer_extra() {
	?>
		<div id="gdm-more-options-calendar" class="gdm-more-options" style="display: none;">

		<table class="gdm-more-table">
		<tr>
		<td>
		<div class="gdm-more-field-name">Calendar Title
		<input name="showTitle" id="gdm-more-showTitle" class="gdm-more-boolean" type="checkbox" checked />
		</div>
		<div class="gdm-more-field-value">
		<input name="gdm-more-title" id="gdm-more-title" value="">
		</div>
		</td>
		
		<td>
		<div class="gdm-more-field-name">Default View</div>
		<div class="gdm-more-field-value">
		<input name="gdm-more-mode" id="gdm-more-mode-week" type="radio" value="WEEK" /><label for="gdm-more-mode-week">Week</label>
		<input name="gdm-more-mode" id="gdm-more-mode-month" type="radio" checked value="MONTH" /><label for="gdm-more-mode-month">Month</label>
		<input name="gdm-more-mode" id="gdm-more-mode-agenda" type="radio" value="AGENDA" /><label for="gdm-more-mode-agenda">Agenda</label>
		</div>
		</td>
		
		<td>
		<div class="gdm-more-field-name">Week Starts On</div>
		<div class="gdm-more-field-value"><select name="wkst" id="gdm-more-wkst"><option value="1">Sunday</option>
		<option value="2">Monday</option>
		<option value="7">Saturday</option></select>
		</td>
		</tr>
		
		<tr>
		<td>
		<input name="showNav" id="gdm-more-showNav" class="gdm-more-boolean" type="checkbox" checked />
		<label for="showNav">Navigation buttons</label>
		</td>
		<td>
		<input name="showDate" id="gdm-more-showDate" class="gdm-more-boolean" type="checkbox" checked />
		<label for="showDate">Date</label>
		</td>
		<td>
		<input name="showPrint" id="gdm-more-showPrint" class="gdm-more-boolean" type="checkbox" checked />
		<label for="showPrint">Print icon</label>
		</td>
		</tr>
		
		<tr>
		<td>
		<input name="showTabs" id="gdm-more-showTabs" class="gdm-more-boolean" type="checkbox" checked />
		<label for="showTabs">Tabs</label>
		</td>
		<td>
		<input name="showCalendars" id="gdm-more-showCalendars" class="gdm-more-boolean" type="checkbox" checked />
		<label for="showCalendars">Calendar list</label>
		</td>
		<td>
		<input name="showTz" id="gdm-more-showTz" class="gdm-more-boolean" type="checkbox" checked />
		<label for="showTz">Time zone</label>
		</td>
		</tr>
		
		</table>
		
		</div>
		<?php
	}
	
	// EDD auto-updates
	
	public function gdm_admin_init() {
		$this->edd_plugin_updater();
		parent::gdm_admin_init();
	}
	
	protected function edd_plugin_updater() {
		$options = $this->get_option_gdm();
		$license_key = $options['gdm_license_key'];
		
		if ($license_key != '') {
	
			if( !class_exists( 'EDD_SL_Plugin_Updater' ) ) {
				// load our custom updater
				include( dirname( __FILE__ ) . '/EDD_SL_Plugin_Updater.php' );
			}
			
			// setup the updater
			$edd_updater = new EDD_SL_Plugin_Updater( WPGLOGIN_GDM_STORE_URL, $this->my_plugin_basename(), 
				array(
					'version' 	=> $this->PLUGIN_VERSION,
					'license' 	=> $license_key,
					'item_name' => WPGLOGIN_GDM_ITEM_NAME,
					'author' 	=> 'Dan Lester'
				)
			);

		}
	
	}
	
	protected function edd_license_activate($license_key) {
		// data to send in our API request
		$api_params = array(
				'edd_action'=> 'activate_license',
				'license' 	=> $license_key,
				'item_name' => urlencode( WPGLOGIN_GDM_ITEM_NAME )
		);
		
		// Call the custom API.
		$response = wp_remote_get( add_query_arg( $api_params, WPGLOGIN_GDM_STORE_URL ), 
								   array( 'timeout' => 15, 'sslverify' => false ) );
		
		// make sure the response came back okay
		if ( is_wp_error( $response ) )
			return false;
		
		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );
		
		if (isset($license_data->license) && $license_data->license == 'valid') {
			return true;
		}
		return false;
	}
	
	// PLUGINS PAGE
	
	public function gdm_plugin_action_links( $links, $file ) {
		if ($file == $this->my_plugin_basename()) {
			$settings_link = '<a href="'.$this->get_settings_url().'">Settings</a>';
			array_unshift( $links, $settings_link );
		}
	
		return $links;
	}
	
	// ADMIN
	
	protected function get_options_name() {
		return 'gdm_premium';
	}
	
	protected function draw_admin_settings_tabs() {
		?>
		<h2 id="gdm-tabs" class="nav-tab-wrapper">
			<?php $this->draw_admin_settings_tabs_start(); ?>
			<a href="#advanced" id="advanced-tab" class="nav-tab">Advanced Options</a>
			<a href="#license" id="license-tab" class="nav-tab">License</a>
		</h2>
		<?php
	}
	
	// Override in Enterprise
	protected function draw_admin_settings_tabs_start() {
	}
	
	protected function gdm_mainsection_text() {
		$options = $this->get_option_gdm();
		
		$this->enqueue_admin_settings_scripts();

		echo '<div id="advanced-section" class="gdmtab active">';
		
		echo "<h3>Google Accounts for Drive Access</h3>";
		echo '<label for="input_gdm_allow_account_switch" class="textinput big">Allow user to choose Google Account independently of WordPress email</label> &nbsp;';
		echo "<input id='input_gdm_allow_account_switch' class='checkbox' name='".$this->get_options_name()."[gdm_allow_account_switch]' type='checkbox' ".($options['gdm_allow_account_switch'] ? 'checked ' : '')."'/>";
		echo '</div>';
		
		
		echo '<div id="license-section" class="gdmtab">';
		
		echo "<h3>License Registration</h3>";
		
		echo '<p>You should have received a license key when you purchased this professional version of Google Drive Embedder. </p>'
				.'<p>Please enter it below to enable automatic updates, or <a href="mailto:contact@wp-glogin.com">email us</a> if you do not have one.</p>';
		
		echo '<label for="input_gdm_license_key" class="textinput big">License Key</label> &nbsp;';
		echo "<input id='input_gdm_license_key' class='textinput' name='".$this->get_options_name()."[gdm_license_key]' size='40' type='text' value='{$options['gdm_license_key']}' />";
		
		echo "</div>";
	}
	
	public function gdm_register_scripts() {
		wp_register_style( 'gdm_admin_settings_css', $this->my_plugin_url().'css/gdm-admin-settings.css' );
		wp_register_script( 'gdm_admin_settings_tabs_js', $this->my_plugin_url().'js/gdm-admin-settings-tabs.js', array('jquery') );
	}
	
	public function enqueue_admin_settings_scripts() {
		wp_enqueue_style( 'gdm_admin_settings_css' );
		wp_enqueue_script( 'gdm_admin_settings_tabs_js' );
		wp_localize_script( 'gdm_admin_settings_js', 'gdm_trans', $this->get_translation_array() );
	}
	
	protected function add_actions() {
		parent::add_actions();
		add_action('init', array($this,'gdm_register_scripts'));
		if (is_admin()) {
			if (is_multisite()) {
				add_filter('network_admin_plugin_action_links', array($this, 'gdm_plugin_action_links'), 10, 2 );
			}
			else {
				add_filter( 'plugin_action_links', array($this, 'gdm_plugin_action_links'), 10, 2 );
			}
		}
	}
	
	// OPTIONS
	
	protected function get_default_options() {
		return array_merge( parent::get_default_options(),
				Array('gdm_license_key' => '',
					  'gdm_allow_account_switch' => false));
	}
	
	public function gdm_options_validate($input) {
		$newinput = parent::gdm_options_validate($input);
		$newinput['gdm_license_key'] = trim($input['gdm_license_key']);
		if ($newinput['gdm_license_key'] != '') {
			if (!preg_match('/^.{32}.*$/i', $newinput['gdm_license_key'])) {
				add_settings_error(
					'gdm_license_key',
					'tooshort_texterror',
					self::get_error_string('gdm_license_key|tooshort_texterror'),
					'error'
				);
			}
			else {
				$oldoptions = $this->get_option_gdm();
				if ($oldoptions['gdm_license_key'] != $newinput['gdm_license_key']) {
					if (!$this->edd_license_activate($newinput['gdm_license_key'])) {
							add_settings_error(
							'gdm_license_key',
							'invalid',
							self::get_error_string('gdm_license_key|invalid'),
							'error'
						);
					}
				}
			}
		}
		$newinput['gdm_allow_account_switch'] = isset($input['gdm_allow_account_switch']) && $input['gdm_allow_account_switch'];
		return $newinput;
	}
	
	protected function get_error_string($fielderror) {
		$premium_local_error_strings = Array(
				'gdm_license_key|tooshort_texterror' => 'License key is too short',
				'gdm_license_key|invalid' => 'License key failed to activate'
		);
		if (isset($premium_local_error_strings[$fielderror])) {
			return $premium_local_error_strings[$fielderror];
		}
		return parent::get_error_string($fielderror);
	}

}