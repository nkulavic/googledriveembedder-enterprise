
var gdmDriveServiceHandler = {
		
	getAvailable : function() {return true;},
		
	getRequest : function(params) {
		params.trashed = false;
		return gapi.client.request({
			  'path': '/drive/v2/files',
			  'params': params
			});
	},
	
	isCorrectType : function(resp) {
		return resp.kind == 'drive#fileList';
	},
	
	getAllowSearch : function() { return true; },
	
	getUrlsAndReasons : function(drivefile) {
		if (drivefile.kind != 'drive#file') {
			return {};
		}
		
		var links = {
				id : drivefile.id,
				embed : { url : '', reason : '' },
				viewer : { url : drivefile.alternateLink ? drivefile.alternateLink : '', reason : '' },
				download : { url : drivefile.webContentLink ? drivefile.webContentLink : '' , reason : '' },
				title : drivefile.title,
				icon: { url : drivefile.iconLink }
		};
		
		if (drivefile.embedLink) {
			links.embed.url = drivefile.embedLink;
		}
		else {
			if (drivefile.mimeType == 'application/vnd.google-apps.folder') {
				links.download.reason = 'FOLDERDOWNLOAD';
				links.embed.url = 'https://drive.google.com/embeddedfolderview?authuser=0&hl=en&id='+drivefile.id+'#list';
				links.extra = 'folder';
				
				if (gdm_trans.allow_non_iframe_folders) {
					links.width = '';
					links.height = '';
				}
				
			} else if (drivefile.mimeType == 'application/vnd.google-apps.form') {
				/*
				 * Map e.g. https://docs.google.com/a/danlester.com/forms/d/<driveid>/edit?usp=drivesdk
				 * to       https://docs.google.com/a/danlester.com/forms/d/<driveid>/viewform?embedded=true 
				 */
				links.embed.url = drivefile.alternateLink.replace(/\/edit(\?|$)/g, '/viewform?embedded=true&');
				links.embed.reason = '';
			} else if (drivefile.mimeType.match(/^image\//) && drivefile.webContentLink) {
				links.embed.url = drivefile.webContentLink;
				links.extra = 'image';
				if (drivefile.imageMediaMetadata) {
					if (drivefile.imageMediaMetadata.width) {
						links.width = drivefile.imageMediaMetadata.width;
					}
					if (drivefile.imageMediaMetadata.height) {
						links.height = drivefile.imageMediaMetadata.height;
					}
				}
			}
			else {
				if (drivefile.alternateLink) {
					links.embed.url = drivefile.alternateLink.replace(/\/edit(\?|$)/g, '/preview?');
				}
				else if (drivefile.webContentLink) {
					// Old-style Google Doc Viewer as fallback
					links.embed.url = '//docs.google.com/viewer?embedded=true&url=' + encodeURIComponent(drivefile.webContentLink);
				}
				else {
					links.embed.reason = 'WEBCONTENT';
				}
			}
		}
		
		// Video needs special attention
		if (drivefile.mimeType.match(/^video\//) && drivefile.alternateLink) {
			links.embed.url = drivefile.alternateLink.replace(/\/edit(\?|$)/g, '/preview?');
			links.embed.reason = '';
		}
		
		// Drawings are better as PNGs
		if (drivefile.mimeType == 'application/vnd.google-apps.drawing' && links.download.url) {
			links.embed.url = links.download.url;
			links.embed.reason = links.download.reason;
			links.extra = 'image';
			links.width = 0;
			links.height = 0;
		}
		
		if (!links.download.url && drivefile.exportLinks) {
			links.download.exportkey = drivefile.id;
			var newexportLinks = {};
			for (prop in drivefile.exportLinks) {
				var exporturl = drivefile.exportLinks[prop];
				var fileext = '';
				var match = exporturl.match(/&exportFormat=([a-zA-Z0-9_]+)$/);
				if (match) {
					fileext = match[1].toUpperCase();
				}
				else {
					fileext = prop.substr(0, 15);
				}
				newexportLinks[fileext] = exporturl;
			}
			links.download.exports = newexportLinks;
		}
	    
	    return links;
	},
	
	getReasonText : function(reason) {
		switch (reason) {
			case 'SHARE':
				return 'To enable embedding, set Sharing to \'Anyone with the link can view\'';
				break;
				
			case 'FOLDERDOWNLOAD':
				return 'Not possible to download this type';
				break;
				
			case 'WEBCONTENT':
				return 'There is no content available';
				break;
				
			default:
				return 'Not possible for this file type';
		}
	},
	
	allowSetEmbedOwnerParent : function() {
		return (gdm_trans.post_id 
				&& (gdm_trans.gdm_drive_set_embed_sa_owner || gdm_trans.gdm_drive_set_embed_parent));
	},
	
	showOwnerEditorWarning : function() {
		// Assume a file/folder has been selected
		return gdm_trans.gdm_drive_set_embed_sa_owner;
	},
	
	allowInsertDriveFile : function() {
		
		if (gdm_trans.gdm_drive_set_embed_sa_owner 
				&& jQuery('#gdm-ack-owner-editor-checkbox').prop("checked")==false) {
			alert("Please tick the acknowledgement that your file (or folder and its immediate children) will have its owner changed to be your administrator, "
						+"and you will be demoted to having editing privileges (but not full ownership).");
			return false;
		}
		
		return true;
	}

};

var gdmCalendarServiceHandler = {
		
	getAvailable : function() {return true;},
	
	allowSetEmbedOwnerParent : function() {return false;},
	showOwnerEditorWarning : function() {return false;},
	allowInsertDriveFile : function() {return true;},
	
	getRequest : function(params) {
		return gapi.client.request({
			  'path': '/calendar/v3/users/me/calendarList',
			  'params': params
			});
	},
	
	isCorrectType : function(resp) {
		return resp.kind == 'calendar#calendarList';
	},
	
	getUrlsAndReasons : function(calendar) {
		if (calendar.kind != 'calendar#calendarListEntry') {
			return {};
		}
		
		var embedUrl = '//www.google.com/calendar/embed?src='+encodeURIComponent(calendar.id)
						+'&ctz='+encodeURIComponent(calendar.timeZone);
		
		var exportLinks = {
				'ICAL' : 'http://www.google.com/calendar/ical/'+encodeURIComponent(calendar.id)+'/public/basic.ics',
				'XML' : 'http://www.google.com/calendar/feeds/'+encodeURIComponent(calendar.id)+'/public/basic'
		};
		
		var links = {
				id : calendar.id,
				embed : { url : embedUrl, reason : '' },
				viewer : { url : embedUrl, reason : '' },
				download : { exports : exportLinks , url : '', reason : '' },
				title : calendar.summary,
				icon : { url : gdm_trans.ical_png_url, color : calendar.backgroundColor },
				extra : 'calendar'
		};
		return links;
	},
	
	getAllowSearch : function() { return false; }

};

// For Enterprise mainly
function gdmInsertFolderShortcode(links) {
	// Can assume links.extra == folder, and they wanted non-iframe style embed
	
	var isControlled = jQuery('#gdm-foldertype-control').prop("checked");
	
	var send_shortcode_fn = function(folderid) {
		var extraattrs = '';
		var width = gdmDriveMgr.gdmValidateDimension(jQuery('#gdm-linktype-embed-width').attr('value'), '');
		var height = gdmDriveMgr.gdmValidateDimension(jQuery('#gdm-linktype-embed-height').attr('value'), '');
		if (width) {
			extraattrs += ' width="'+width+'"';
		}
		if (height) {
			extraattrs += ' height="'+height+'"';
		}
		
		if (!isControlled && jQuery('#gdm-folder-showupload').prop("checked")) {
			extraattrs += ' showupload="true"';
		}
		if (!isControlled && !jQuery('#gdm-folder-breadcrumbs').prop("checked")) {
			extraattrs += ' breadcrumbs="false"';
		}
		
		window.send_to_editor('[google-drive-folder'
							  +(folderid ? ' cfid="'+folderid+'"' : ' id="'+links.id+'"')
							  +' title="'+gdmDriveMgr.stripQuots(links.title)+'"'
							  +extraattrs
							  +']');
		
		// Set file parent/owner in Enterprise version
		if (gdmDriveMgr.getServiceHandler().allowSetEmbedOwnerParent()) {
			gdmSetEmbedSAOwnerParent(links.id); // Drive ID always
		}
	};
		
	if (isControlled) {
		// If a controlled folder, need to register it
		gdmRegisterControlledFolder(links.title, links.id, gdmReadPermissions(), function(resp){
			if (resp.id) {
				send_shortcode_fn(resp.id);
			}
			else {
				alert("Problem registering Controlled Folder");
			}
		});
	}
	else {
		// Just respect Drive perms
		send_shortcode_fn();
	}
	
}

function gdmReadPermissions() {
	var permissions = {};
	jQuery('#gdm-more-options-folders table.gdm-permissions-table select').each(function() {
		var $this = jQuery(this);
		permissions[$this.attr('name')] = $this.val();
	});
	return permissions;
}

function gdmRegisterControlledFolder(title, folderid, permissions, callback) {
	var data = permissions;
	// Add on other json properties
	data.action = 'gdm_register_controlled_folder';
	data.title = title;
	data.folderid = folderid;
	data.cf_register_nonce = gdm_trans.cf_register_nonce;
	
	jQuery.ajax({
	  url: gdm_trans.ajaxurl,
	  data: data,
	  dataType: 'json',
	  type: 'POST',
	  success: function(resp){
	    callback(resp);
	  }
	}).fail(function(){
		callback({error: {message: 'Problem contacting the web server'}});
	});
}

// Set Parent or Owner on server side

function gdmSetEmbedSAOwnerParent(drivefileid) {
	var data = {};
	// Add on other json properties
	data.action = 'gdm_set_embed_parent_owner';
	data.drivefileid = drivefileid;
	data.cf_register_nonce = gdm_trans.cf_register_nonce;
	data.postId = gdm_trans.post_id;
	
	var callback = function(resp) {
		if (resp.error) {
			alert(resp.error.message);
		}
	};
	
	jQuery.ajax({
	  url: gdm_trans.ajaxurl,
	  data: data,
	  dataType: 'json',
	  type: 'POST',
	  success: function(resp){
	    callback(resp);
	  }
	}).fail(function(){
		callback({error: {message: 'Problem contacting the web server for registration'}});
	});
}

// Folder More Options
jQuery(document).ready(function ($) {
	var permstables = $('div.gdm-foldertypes-div table.gdm-permissions-table');
	
	if (permstables.length > 0) {
		var gdmCheckRelevantSelect = function() {
			if (!jQuery('input#gdm-foldertype-control').attr('checked')) {
				jQuery('input#gdm-foldertype-control').attr('checked', 'checked');
			}
		};
	
		$('div.gdm-foldertypes-div table.gdm-permissions-table')
					.rolepermissions()
					.on('rolepermissions.addedrow', function() { gdmCheckRelevantSelect(); gdmThickDims(); })
					.on('rolepermissions.deletedrow', function() { gdmCheckRelevantSelect(); gdmThickDims(); })
					.on('rolepermissions.selectchanged', gdmCheckRelevantSelect);
	}
});

