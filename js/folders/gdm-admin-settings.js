
jQuery(document).ready(function($) {

	var changebtn = $('#gdm-change-base-folder');
	
	if (changebtn.length > 0) {
		$('#gdm-change-base-folder').on('click', function() {
			$(this).hide();
			$('#gdm-base-folder-selectdiv').show();
			loadFolders();
		});
	}
	else {
		loadFolders();
	}
	
	var folderselect = $('#input_gdm_base_folder_existing');
	
	folderselect.on('change', function(e){
		// When an existing folder is selected,
		// grab its title to a hidden field.
		// And update radio button to 'existing'
		$('#input_gdm_base_folder_existing_title').val(
			$(this).find('option:selected').text()
		);
		$('#gdm-base-folder-type-existing').prop("checked", true);
	});
	
	$('#input_gdm_base_folder_new').on('click', function(e){
		$('#gdm-base-folder-type-new').prop("checked", true);
	});
	
	function loadFolders() {
    	var callback = function(resp) {
    		folderselect.empty();
    		if (resp.error) {
    			$('#gdm-base-folder-existing-error').append(document.createTextNode(resp.error.message));
    			folderselect.append($('<option></option>', {value: 'null'})
    									.append(document.createTextNode('<Error...>')));
    		}
    		else {
    			folderselect.append($('<option></option>', {value: 'null'})
    									.append(document.createTextNode('<Select...>')));
    			for (var i=0 ; i<resp.items.length ; ++i) {
    				if (resp.items[i].mimeType == 'application/vnd.google-apps.folder') {
    					folderselect.append($('<option></option>', {value: resp.items[i].id})
    										.append(document.createTextNode(resp.items[i].title)));
    				}
    			}
    		}
    	};
    	
  	  	$.ajax({
		  url: gdm_trans.ajaxurl,
		  data: {action: 'gdm_api_list_root_folders'},
		  dataType: 'json',
		  //processData: false,
		  //contentType: false,
		  type: 'POST',
		  success: function(resp){
		    callback(resp);
		  }
		}).fail(function(){
			callback({error: {message: 'Problem contacting the web server'}});
		});
	}

});
