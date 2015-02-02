<?php

class gdm_drive_helper {
	
	public function create_folder($parent_folderid, $post_name, $owner_email='', $writer_email='', $custom_properties=null) {
		return $this->wrap_drivesa_call(array($this, 'do_create_folder'),
				array('parent_folderid' => $parent_folderid,
						'post_name' => $post_name,
						'owner_email' => $owner_email,
						'writer_email' => $writer_email,
						'custom_properties' => $custom_properties));
	}
	
	protected function do_create_folder($driveservice, $params) {
		extract($params);
		
		$parentfile = new GoogleGAL_Service_Drive_ParentReference();
		$parentfile->setId($parent_folderid);
			
		$drivefile = new GoogleGAL_Service_Drive_DriveFile();
		$drivefile->setParents(array($parentfile));
		$drivefile->setTitle($post_name);
		$drivefile->setMimeType('application/vnd.google-apps.folder');
		$this->addDriveFileProperties($drivefile, $custom_properties);
			
		$resp = $driveservice->files->insert($drivefile);
		
		if (property_exists($resp, 'id')) {
			$id = $resp->id;
			
			if ($owner_email != '') {
				// Is desired owner already listed? (Probably because owner is Service Acct 'sub' user
				$owners = $resp->getOwners();
				for ($i=0 ; $i<count($owners) && $owner_email != '' ; ++$i) {
					if ($owner_email == $owners[$i]->emailAddress) {
						$owner_email = '';
					}
				} 
				
				// If not already an owner, set owner_email to be a Drive file owner
				if ($owner_email != '') {
					try {
						$perm = new GoogleGAL_Service_Drive_Permission();
	
						$perm->setValue($owner_email);
						$perm->setType('user');
						$perm->setRole('owner');
						
						$pr = $driveservice->permissions->insert($id, $perm);
					} catch (Exception $e) {
						error_log("An error occurred setting folder ".$id." owner to ".$owner_email.": " . $e->getMessage());
					}
				}
			}
			
			if ($writer_email != '') {
				try {
					$perm = new GoogleGAL_Service_Drive_Permission();
				
					$perm->setValue($writer_email);
					$perm->setType('user');
					$perm->setRole('writer');
				
					$pr = $driveservice->permissions->insert($id, $perm);
				} catch (Exception $e) {
					error_log("An error occurred setting folder ".$id." writer to ".$writer_email.": " . $e->getMessage());
				}
			}
			
			return $id;
		}
	}
	
	protected function addDriveFileProperties($drivefile, $custom_properties) {
		if (!is_null($custom_properties) && is_array($custom_properties) && count($custom_properties) > 0) {
			$realprops = array();
			foreach ($custom_properties as $rawprop) {
				if (is_array($rawprop) && isset($rawprop['key']) && isset($rawprop['value'])) {
					$realprop = new GoogleGAL_Service_Drive_Property();
					$realprop->setKey($rawprop['key']);
					$realprop->setValue($rawprop['value']);
					$vis = isset($rawprop['visibility']) && in_array($rawprop['visibility'], array('PUBLIC', 'PRIVATE'))
					 			? $rawprop['visibility'] : 'PUBLIC';
					$realprop->setVisibility($vis);
					$realprops[] = $realprop;
				}
			}
			if (count($realprops) > 0) {
				$drivefile->setProperties($realprops);
			}
		}
	}
	
	public function proxy_api($path, $params) {
		return $this->wrap_drivesa_call(array($this, 'do_proxy_api'),
				array('path' => $path,
						'params' => $params));
	}
	
	protected function do_proxy_api($driveservice, $_params) {
		extract($_params);
		
		$resp = null;
		if ($path == '/drive/v2/files/') {
			
			$params['q'] = stripslashes(isset($params['q']) ? $params['q'] : '');
			
			if ($this->get_folderid_from_q($params['q']) == '') {
				throw new gdm_Drive_Exception("Drive API query not permitted: ".$params['q']);
			}
			
			$obj = $driveservice->files->listFiles($params);
			
			// Convert object to an array
			$resp = get_object_vars($obj);
			// Get items separately because it was a protected member of the object
			$resp['items'] = $obj->getItems(); 
			

		}
		else {
			// Assume it will be '/drive/v2/files/<folderid>'
			$folderid = $this->get_folderid_from_path($path);
			if ($folderid != '') {
				$params['updateViewedDate'] = false; // JS client could have overwritten this
				$obj = $driveservice->files->get($folderid);
				
				$resp = get_object_vars($obj);
				$resp['labels'] = $obj->getLabels();
			}
		}

		return $resp;
	}
	
	public function get_folderid_from_path($path) {
		$matches = array();
		if (preg_match('~^/drive/v2/files/([0-9a-zA-Z_\-]+)$~', $path, $matches)) {
			return $matches[1];
		}
		return '';
	}
	
	public function get_folderid_from_q($q) {
		$q = stripslashes($q);
		$matches = array();
		if (preg_match("~^'([0-9a-zA-Z_\-]+)' in parents and trashed \= false( and mimeType = \'application\/vnd\.google\-apps\.folder\')?$~", $q, $matches)) {
			return $matches[1];
		}
		return '';
	}
	
	public function upload_file($parent_folderid, $mime_type_index, $fileindex) {
		if (!isset($_POST[$mime_type_index])) {
			throw new gdm_Drive_Exception("mime type was not specified");
		}
		$mime_type = $_POST[$mime_type_index];
		return $this->wrap_drivesa_call(array($this, 'do_upload_file'),
				array('parent_folderid' => $parent_folderid,
						'mime_type' => $mime_type,
						'fileindex' => $fileindex));
	}

	protected function do_upload_file($driveservice, $params) {
		extract($params);
		
		$parentfile = new GoogleGAL_Service_Drive_ParentReference();
		$parentfile->setId($parent_folderid);
			
		$drivefile = new GoogleGAL_Service_Drive_DriveFile();
		$drivefile->setParents(array($parentfile));
		$drivefile->setTitle($_FILES[$fileindex]['name']);
		$drivefile->setMimeType($mime_type);
		$data = @file_get_contents($_FILES[$fileindex]['tmp_name']);
			
		$resp = $driveservice->files->insert($drivefile, array(
				'mimeType' => $mime_type, 
				'data' => $data,
				'uploadType' => 'multipart'
		));
			
		if (property_exists($resp, 'id')) {
			return $resp->id;
		}
	}
	
	public function is_ancestor($parentfolderid, $childfolderid, $depth=0) {
		if ($parentfolderid == $childfolderid) {
			return true;
		}
		
		if ($parentfolderid == '' || $childfolderid == '') {
			return false;
		}
		
		$trychildren = $this->get_child_folders($parentfolderid);
		
		if (in_array($childfolderid, $trychildren)) {
			return true;
		}
		// Search in each child
		if ($depth < 5) {
			foreach ($trychildren as $tryid) {
				if ($this->is_ancestor($tryid, $childfolderid, $depth+1)) {
					return true;
				}
			}
		}
		else {
			error_log("Depth reached in search for descendants");
			throw new gdm_Drive_Exception("Folder ".$childfolderid." may not be a descendant of folder ".$parentfolderid." (but stopped searching due to depth)");	
		}
		
		return false;
	}
	
	protected function get_child_folders($parentfolderid) {
		return $this->wrap_drivesa_call(array($this, 'do_get_child_folders'), 
				array('parentfolderid' => $parentfolderid));
	}
	
	protected function do_get_child_folders($driveservice, $_params) {
		extract($_params);
	
		$params = array('q' => "mimeType = 'application/vnd.google-apps.folder' and '".$parentfolderid."' in parents and trashed = false");
		$obj = $driveservice->files->listFiles($params);
				
		$childfolders = array();
		foreach ($obj->getItems() as $child) {
			$childfolders[] = $child->id;
		}
				
		return $childfolders;
	}
	
	public function set_embed_parent_owner($drivefileid, $want_parent_folderid, $want_set_owner, $current_user_email, $custom_properties=null) {
		try {
			$this->wrap_drivesa_call(array($this, 'do_set_embed_parent_owner'),
				array('drivefileid' => $drivefileid,
					  'want_parent_folderid' => $want_parent_folderid,
					  'want_set_owner' => $want_set_owner,
					  'current_user_email' => $current_user_email,
					  'custom_properties' => $custom_properties),
						$current_user_email); // Current user must be able to see the file since they selected it in UI
			return(array('success'=>true));
		}
		catch (gdm_Drive_Exception $de) {
			die(json_encode(array('error' => array('message' => 'parent_owner error sub '.$current_user_email.': '.$de->getMessage()))));
		}
	}
	
	protected function do_set_embed_parent_owner($driveservice, $_params) {
		extract($_params);
	
		$resp = $driveservice->files->get($drivefileid);
	
		if (property_exists($resp, 'id') && $resp->id == $drivefileid) {
			$current_owner = '';
			
			if ($want_set_owner) {
				try {
					$owners = $resp->getOwners();
					if (count($owners) > 0) {
						$first_owner = $owners[0];
						$current_owner = $first_owner->emailAddress;
					}
					
					$this->wrap_drivesa_call(array($this, 'do_set_embed_owner'),
							array('drivefileid' => $drivefileid,
									'resp' => $resp,
									'want_set_owner' => $want_set_owner,
									'current_owner' => $current_owner),
									$current_owner); // sub should be current owner to be allowed to change owner to admin
				}
				catch (gdm_Drive_Exception $de) {
					$m = 'owner error changing from '.$current_owner.': '.$de->getMessage();
					die(json_encode(array('error' => array('message' => $m))));
				}
			}
			
			if ($want_parent_folderid != '') {
				// In practice, we may need for the owner to be set first above, for the parent to be added successfully
				try {
					$this->wrap_drivesa_call(array($this, 'do_set_embed_parent'),
												array('drivefileid' => $drivefileid,
														'resp' => $resp,
														'want_parent_folderid' => $want_parent_folderid,
														'custom_properties' => $custom_properties));
				}
				catch (gdm_Drive_Exception $de) {
					die(json_encode(array('error' => array('message' => 'parent error: '.$de->getMessage()))));
				}
			}
			
			// Is it a folder?
			if (property_exists($resp, 'mimeType') && $resp->mimeType == 'application/vnd.google-apps.folder' 
						&& $want_set_owner) {
				
				// Then change owner for sub-items too
				try {						
					$this->wrap_drivesa_call(array($this, 'do_set_embed_child_owner'),
							array('drivefileid' => $drivefileid,
									'resp' => $resp,
									'want_set_owner' => $want_set_owner,
									'current_owner' => $current_owner),
							$current_owner); // Guess that children are owned by the same user as the folder
				}
				catch (gdm_Drive_Exception $de) {
					$m = 'ownerchild error changing child owners to '.$want_set_owner.': '.$de->getMessage();
					die(json_encode(array('error' => array('message' => $m))));
				}
			}
			
		}
		else {
			die(json_encode(array('error' => array('message' => "Drivefile id ".$drivefileid." does not equal returned resp id if any"))));
		}
	}
	
	protected function do_set_embed_parent($driveservice, $_params) {
		extract($_params);
		
		// Set parent
		
		// Is folder already set as a parent?
		for ($i=0 ; $i < count($resp->parents) ; ++$i) {
			$parentchild = $resp->parents[$i];
			if ($parentchild->id == $want_parent_folderid) {
				return;
			}
		} 
		
		$patch_file = new GoogleGAL_Service_Drive_DriveFile();
		//if (!is_null($custom_properties) && is_array($custom_properties) && count($custom_properties) > 0) {
			$this->addDriveFileProperties($patch_file, $custom_properties);
		//}
		$driveservice->files->patch($drivefileid, $patch_file, array('addParents' => $want_parent_folderid));
	}
	
	protected function get_sa_admin_email() {
		$gal = GoogleAppsLogin();
		
		if (!method_exists($gal, 'get_option_galogin')) {
			throw new Exception('Requires version 2.7.1+ of Google Apps Login');
		}
		
		$ga_options = $gal->get_option_galogin();
		return isset($ga_options['ga_domainadmin']) ? $ga_options['ga_domainadmin'] : ''; 
	}
	
	protected function do_set_embed_owner($driveservice, $_params) {
		extract($_params);
		
		// Set owners
		$want_owner_email = $this->get_sa_admin_email();
		
		// Is desired owner already listed? (Probably because owner is Service Acct 'sub' user)
		$owners = $resp->getOwners();
		for ($i=0 ; $i<count($owners) && $want_owner_email != '' ; ++$i) {
			if ($want_owner_email == $owners[$i]->emailAddress) {
				$want_owner_email = '';
			}
		}
	
		// If not already an owner, set owner_email to be a Drive file owner
		if ($want_owner_email != '') {
			try {
				// Set new owner
				$perm = new GoogleGAL_Service_Drive_Permission();
	
				$perm->setValue($want_owner_email);
				$perm->setType('user');
				$perm->setRole('owner');
	
				$pr = $driveservice->permissions->insert($drivefileid, $perm);
				
				$perm = new GoogleGAL_Service_Drive_Permission();
				
				// Now demote previous owner to writer
				if ($current_owner != '') {
					$perm->setValue($current_owner);
					$perm->setType('user');
					$perm->setRole('writer');
					
					$pr = $driveservice->permissions->insert($drivefileid, $perm);
				}
				
			} catch (Exception $e) {
				error_log("An error occurred setting file ".$drivefileid." owner to ".$want_owner_email.": " . $e->getMessage());
			}
		}	
		
	}
	
	protected function do_set_embed_child_owner($driveservice, $_params) {
		extract($_params);
	
		// Set owners
		$want_owner_email = $this->get_sa_admin_email();
	
		// If not already an owner, set owner_email to be a Drive file owner
		if ($want_owner_email != '') {
			
			$childlist = $driveservice->children->listChildren($drivefileid);
			$children = $childlist->getItems();
			
			if (count($children) > 0) {
				
				if (!class_exists('GoogleGAL_Http_Batch')) {
					require_once( 'Google/Http/Batch.php' );
				}				
			
				$gclient = $driveservice->getClient();
				$gclient->setUseBatch(true);
				$batch = new GoogleGAL_Http_Batch($gclient);
	
				$perm_own = new GoogleGAL_Service_Drive_Permission();
				$perm_own->setValue($want_owner_email);
				$perm_own->setType('user');
				$perm_own->setRole('owner');

				$perm_write = new GoogleGAL_Service_Drive_Permission();
				$perm_write->setValue($current_owner);
				$perm_write->setType('user');
				$perm_write->setRole('writer');
				
				foreach ($children as $child) {
					
					$req_own = $driveservice->permissions->insert($child->id, $perm_own);
					$batch->add($req_own, $child->id.'-owner');
					$req_write = $driveservice->permissions->insert($child->id, $perm_write);
					$batch->add($req_write, $child->id.'-writer');
					
				}
				
				$results = $batch->execute();
				
				$gclient->setUseBatch(false);
			}
		}
	
	}
	
	protected $ga_cred = null;
	protected function wrap_drivesa_call($callable, $params, $sub_email='') {
		$msg = '';
		
		if (!function_exists('GoogleAppsLogin')) {
			$msg = "Google Apps Login plugin needs to be activated and configured";
			die ($msg);
		}
			
		try {
			$gal = GoogleAppsLogin();
				
			if (!method_exists($gal, 'get_Auth_AssertionCredentials')) {
				throw new Exception('Requires version 2.5.2+ of Google Apps Login');
			}
		
			$cred = $gal->get_Auth_AssertionCredentials(
					array('https://www.googleapis.com/auth/drive'), $sub_email);
			
			$this->ga_cred = $cred;
			
			$serviceclient = $gal->get_Google_Client();
		
			$serviceclient->setAssertionCredentials($cred);
		
			// Include paths were set when client was created
			if (!class_exists('GoogleGAL_Service_Drive')) {
				require_once( 'Google/Service/Drive.php' );
			}
			
			$driveservice = new GoogleGAL_Service_Drive($serviceclient);
			
			return call_user_func($callable, $driveservice, $params);
			
		} catch (GoogleGAL_Service_Exception $ge) {
			$errors = $ge->getErrors();
			$doneerr = false;
			if (is_array($errors) && count($errors) > 0) {
				if (isset($errors[0]['reason'])) {
					switch ($errors[0]['reason']) {
						case 'insufficientPermissions':
							$msg = 'User had insufficient permission to fetch Google Drive data';
							$doneerr = true;
							break;
		
						case 'accessNotConfigured':
							$msg = 'You need to enable Drive API for your project in Google Cloud Console';
							$doneerr = true;
							break;
								
						case 'forbidden':
							$msg = 'Forbidden - are you sure the user you entered in Service Account settings is a Google Apps admin?';
							$doneerr = true;
							break;
					}
				}
			}
		
			if (!$doneerr) {
				$msg = 'Service Error calling Google Drive: '.$ge->getMessage();
			}
		
		} catch (GoogleGAL_Auth_Exception $ge) {
			$error = $ge->getMessage();
			if (preg_match('/Error refreshing the OAuth2 token.+invalid_grant/s', $error)) {
				/*
				 * When keys don't match etc
				* Error refreshing the OAuth2 token, message: '{ "error" : "invalid_grant" }'
				*/
				$msg = 'Error - please check your private key and service account email are correct in Settings -> Google Apps Login (Service Account settings)';
			}
			else if (preg_match('/Error refreshing the OAuth2 token.+unauthorized_client/s', $error)) {
				/*
				 * When sub is wrong
				* Error refreshing the OAuth2 token, message: '{ "error" : "unauthorized_client", "error_description" : "Unauthorized client or scope in request." }'
				*/
				$msg = 'Error - please check you have named a Google Apps admin\'s email address in Settings -> Google Apps Login (Service Account settings)';
			}
			else if (preg_match('/Error refreshing the OAuth2 token.+access_denied/s', $error)) {
				/*
				 * When scope not entered
				* Google Auth Error fetching Users: Error refreshing the OAuth2 token, message: '{
				* "error" : "access_denied", "error_description" : "Requested client not authorized."}'
				*/
				$msg = 'Error - please check you have added the required permissions scope to your Google Cloud Console project. See Settings -> Google Apps Login (Service Account settings).';
			}
			else {
				$msg = "Google Auth Error fetching Drive data: ".$ge->getMessage();
			}
		}
		catch (GAL_Service_Exception $e) {
			$msg = "GAL Error fetching Google Drive data: ".$e->getMessage();
		}
		catch (Exception $e) {
			$msg = "General Error fetching Google Drive data: ".$e->getMessage();
		}
		
		if ($msg != '') {
			throw new gdm_Drive_Exception($msg);
		}
	
	}
}


