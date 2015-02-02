
var gdmFolderViewer = {
	
	CLIENT_ID : gdm_trans.clientid,
	SCOPES : 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/drive.install',

	/**
	 * Called when the client library is loaded to start the auth flow.
	 */
	handleClientLoad : function() {
	  window.setTimeout(function() { gdmFolderViewer.checkAuth(true); }, 1);
	},

	/**
	 * Check if the current user has authorized the application.
	 */
	checkAuth : function(immediate) {
	  var params = {client_id: gdmFolderViewer.CLIENT_ID, scope: gdmFolderViewer.SCOPES, immediate: immediate,  
			   include_granted_scopes: true,
			   authuser: -1
			   };
	  
	  if (!gdm_trans.gdm_allow_account_switch && gdm_trans.useremail != '') {
		  params.login_hint = gdm_trans.useremail;
  	  }
	  gapi.auth.authorize(params, gdmFolderViewer.handleAuthResult);
	},
	

    /**
     * Called when authorization server replies.
     *
     * @param {Object} authResult Authorization result.
     */
    handleAuthResult : function(authResult) {
      if (authResult && !authResult.error) {
        // Access token has been successfully retrieved, requests can be sent to the API.
    	  gdmFolderViewer.showFolders(false);    	  
      } else {
        // No access token could be retrieved, show the button to start the authorization flow.
    	  gdmFolderViewer.showAuthButtons();
    	  gdmFolderViewer.showFolders(true); // Show controlled folders
      }
    },
    
    showAuthButtons : function() {
    	var authButton = jQuery('<a href="#">Click to authorize Google Drive</a>');
        
        authButton.on('click', function(e) {
        	gdmFolderViewer.turnParentToLoading(e.target);
        	gdmFolderViewer.checkAuth(false);
            e.preventDefault();
        });
        
        jQuery('.gdm-ent-folder').filter('[data-gdm-perms-view=drive],[data-gdm-perms-upload=drive]').empty().append(jQuery('<div></div>', {class: 'gdm-btndiv gdm-authbtndiv'}).append(authButton));
    },
    
    showFolders : function(useproxyonly) {
    	var wantfolders = jQuery('.gdm-ent-folder');
    	wantfolders = wantfolders.not('[data-gdm-init=1]'); // exclude any already inited
    	
    	// Do the rest of the init only on proxy folders if requested
    	if (useproxyonly) {
    		wantfolders = wantfolders.not('[data-gdm-perms-view=drive],[data-gdm-perms-upload=drive]');
    	}
    	
    	wantfolders.each(function(index, folderdiv) {
    		var jfolderdiv = jQuery(folderdiv);
    		var folderid = jfolderdiv.attr('data-gdm-id');
    		var newtext = jQuery('<div></div>', {class: 'gdm-folder-filelist'}).append(jQuery('<p>Fetching files...</p>'));
    		jfolderdiv.empty().append(newtext);
    		
    		if (jfolderdiv.attr('data-gdm-perms-upload') != 'no') {
	    		gdmFileUploader.makeDropZone(jfolderdiv, function(jfd){
	    			var hist = gdmFolderViewer.getHistory(jfolderdiv);
	    			if (hist.length >= 1) {
	    				gdmFolderViewer.loadFolderContents(jfd, hist[hist.length-1]);
	    			}
	    		});
    		}
    		gdmFolderViewer.loadBreadcrumbs(jfolderdiv, jfolderdiv.attr('data-gdm-base-title'));
    		gdmFolderViewer.loadFolderContents(jfolderdiv, folderid);
    		jfolderdiv.attr('data-gdm-init', 1);
    	});

    	// Do Drive Attachments title where needed
    	jQuery('.gdm-ent-folder[data-gdm-attachmentstitle=1]').each(function(index, folderdiv) {
			jQuery(folderdiv).prepend(jQuery('<p></p>', {class: 'gdm-folder-attachments-title'})
								.append(document.createTextNode('Drive Attachments')));
    	});
		
    },
    
    loadBreadcrumbs : function(jfolderdiv, basetitle) {
    	
    	if (jfolderdiv.attr('data-gdm-breadcrumbs') == '0') {
    		// User doesn't want breadcrumbs
    		return;
    	}
    	
    	if (!basetitle) {
    		basetitle = "Drive Folder";
    	}
    	
    	var folderid = jfolderdiv.attr('data-gdm-id');
    	
    	gdmFolderViewer.setFolderTitle(folderid, basetitle);
    	    	
    	jfolderdiv.prepend(jQuery('<ul></ul>', {class: 'gdm-folder-breadcrumbs'}));
    },
    
    loadFolderCheck : function(jfolderdiv, folderid) {
		
		var requestFunction = (jfolderdiv.attr('data-gdm-perms-view') == 'drive' 
			? gapi.client.request
			: ( jfolderdiv.attr('data-gdm-perms-view') == 'yes' ? gdmProxyRequest(jfolderdiv.attr('data-gdm-postid'), 
																				  jfolderdiv.attr('data-gdm-nonce')) 
																: gdmDisallowedRequest));
	
		var path = '/drive/v2/files/'+folderid,
			params = {};

		var restRequest = requestFunction({
			  'path': path,
			  'params': params
			});
	
  		restRequest.execute(function(resp) {
  			if (jfolderdiv.attr('gdm-folder-currentid') != folderid) {
  				// We've moved on now
  				return;
  			}
  			
  			var errmsg = '';
  			if (!resp.error) {
  				if (resp.labels && resp.labels.trashed) {
  					errmsg = "Folder is in the trash";
  				}
  				if (gdmFolderViewer.getFolderTitle(resp.id) != resp.title) {
	  				gdmFolderViewer.setFolderTitle(resp.id, resp.title);
	  				gdmFolderViewer.updateBreadcrumbs(jfolderdiv);
  				}
  			}
  			else {
  				errmsg = resp.error.message;
  			}
  			
  			if (errmsg != '') {
  				var foldercheckerr = jfolderdiv.find('div.gdm-folder-checkerr');
  				if (foldercheckerr.length == 0) {
  					foldercheckerr = jQuery('<div></div>', {class: 'gdm-folder-checkerr'})
  											.insertBefore(jfolderdiv.find('div.gdm-folder-filelist'));
  				}
  				else {
  					foldercheckerr.empty();
  				}
  				foldercheckerr.append(jQuery('<p></p>', {class: 'gdm-err-msg'}).append(document.createTextNode(errmsg)));
  			}
  		});
    },
    
    updateBreadcrumbs : function(jfolderdiv) {
    	var hist = gdmFolderViewer.getHistory(jfolderdiv);

    	var breadcrumbs = jfolderdiv.find('ul.gdm-folder-breadcrumbs');
    	if (breadcrumbs.length == 0) {
    		// User doesn't want breadcrumbs
    	    	
			if (hist.length > 1) {
				// Need a back button instead
					// We are drilled down
					
				var backlink = jQuery('<a href="#">Go back</a>').on('click', function(e) {
					var lastfolderid = gdmFolderViewer.backHistory(jfolderdiv, hist.length-2); // Go to one at end
					gdmFolderViewer.turnParentToLoading(e.target);
					gdmFolderViewer.loadFolderContents(jfolderdiv, lastfolderid);
					e.preventDefault();
				});
				
				var backdiv = jfolderdiv.find('div.gdm-backbtndiv');
				if (backdiv.length == 0) {
					backdiv = jQuery('<div></div>', {class: 'gdm-btndiv gdm-backbtndiv'}).prependTo(jfolderdiv);
				}
				else {
					backdiv.empty();
				}
				backdiv.append(backlink);
			}
			else {
				jfolderdiv.find('div.gdm-backbtndiv').remove();
			}
    	}
		else {
	    	breadcrumbs.empty();
    	
	    	for (var i=0 ; i < hist.length ; ++i) {
	    		var title = gdmFolderViewer.getFolderTitle(hist[i]);
	    		var alink = jQuery('<a></a>', {href: 'https://docs.google.com/a/danlester.com/folderview?id='+hist[i]})
	    								.append(document.createTextNode(title));
	    		var li = jQuery('<li></li>').append(alink).appendTo(breadcrumbs);
	    		(function(destIndex, myli){
		    		alink.on('click', function(e) {
		    			var dofolderid = gdmFolderViewer.backHistory(jfolderdiv, destIndex);
		    			if (dofolderid != '') {
							gdmFolderViewer.turnParentToLoading(e.target);
							
							// Remove subsequent li's so they can't be clicked
							myli.nextAll().remove();
							
							gdmFolderViewer.loadFolderContents(jfolderdiv, dofolderid);
		    			}
						e.preventDefault();
		    		});
	    		})(i, li);
	    	}
		}
    },
    
    loadFolderContents : function(jfolderdiv, folderid, nextPageToken) {
    	var basefolderid = jfolderdiv.attr('data-gdm-id');
    	var wantSubfolders = jfolderdiv.attr('data-gdm-subfolders') != '0';
    	    	
    	// For some reason trashed alone doesn't seem to work
    	var params = {q: "'"+folderid+"' in parents and trashed = false"};
    	
    	var maxResults = jfolderdiv.attr('data-gdm-maxresults');
    	if (maxResults > 0) {
    		params['maxResults'] = parseInt(maxResults);
    	}

    	if (nextPageToken) {
    		params.pageToken = nextPageToken;
    	}
    	
    	// Clear errors
    	jfolderdiv.find('div.gdm-folder-checkerr').empty();
    	
		// Update current folderid - returning calls should check this is still current
		jfolderdiv.attr('gdm-folder-currentid', folderid);
    	
    	// Set off check for folder itself
    	gdmFolderViewer.loadFolderCheck(jfolderdiv, folderid);
    	
    	// Search for folder contents
    	var path = '/drive/v2/files/';
		
		var requestFunction = (jfolderdiv.attr('data-gdm-perms-view') == 'drive' 
			? gapi.client.request
			: ( jfolderdiv.attr('data-gdm-perms-view') == 'yes' ? gdmProxyRequest(jfolderdiv.attr('data-gdm-postid'),
																				  jfolderdiv.attr('data-gdm-nonce')) 
																: gdmDisallowedRequest));
	
		gdmFolderViewer.disableLinks(jfolderdiv);

		var restRequest = requestFunction({
			  'path': path,
			  'params': params
			});
		
		restRequest.execute(function(resp) {
			if (jfolderdiv.attr('gdm-folder-currentid') != folderid) {
				// We've moved on now
				return;
			}
			
			if (resp == 0) {
				// There should have been some items, even if there was no reported error - report now
				resp = { error : {message: 'No items were returned'} };
			}
			
			if (resp.error) {
				// Tell them the problem
				jfolderdiv.find('.gdm-folder-filelist').empty().append(jQuery("<p>There was an error: </p>", 
									{class: 'gdm-folder-error'}).append(document.createTextNode(resp.error.message)));
				return;
			}
						

			var newtable = null;
			var newtablebody = null;
			
			var columns = gdmFolderViewer.getColumnNames(jfolderdiv.attr('data-gdm-columns'));
			var sort_cols = gdmFolderViewer.getSortNames(jfolderdiv.attr('data-gdm-sort'));
			
			var sort_column = sort_cols[0];
			var sort_direction = sort_cols[1];
			var sort_index = -1;
			
			// Not just a More... request
			if (!nextPageToken) {
				var tablehead = jQuery('<tr></tr>');
				var displayHeadings = {'title' : 'Title', 'owner' : 'Owner', 'lastmodified': 'Last Modified', 'size' : 'Size'};
				for (var j=0 ; j<columns.length ; ++j) {
					// <th>Title</th><th>Owner</th><th>Last Modified</th>
					var heading = columns[j] in displayHeadings ? displayHeadings[columns[j]] : columns[j];
					tablehead.append(jQuery('<th></th>').append(document.createTextNode(heading)));
					if (columns[j] == sort_column) {
						sort_index = j;
					}
				}
				newtable = jQuery('<table></table>', {class: 'gdm-folders-table'})
								.append(jQuery('<thead></thead>').append(tablehead));
				newtablebody = jQuery('<tbody></tbody>').appendTo(newtable);
			}
			else {
				newtable = jfolderdiv.find('table.gdm-folders-table');
				newtablebody = newtable.find('tbody');
			}
		
			for (var i=0 ; i<resp.items.length ; ++i) {
				
				var isFolder = resp.items[i].mimeType == 'application/vnd.google-apps.folder';
				
				if (wantSubfolders || !isFolder) {
					var newrow = jQuery('<tr></tr>');
				
					var aparams = {};
					
					var cburls = gdmFolderViewer.generateColorboxAjaxURL(resp.items[i]);
					if (cburls.cburl != '' && !isFolder) {
						aparams = {href: cburls.cburl,
								data_gdm_downloadurl : cburls.downloadurl,
								data_gdm_viewerurl : cburls.viewerurl,
								data_gdm_title : cburls.title,
									class: 'gdm-previewlink'};
					}
					else {
						aparams = {href: resp.items[i].alternateLink, target: '_blank'};
					}
					
					var titlea = jQuery('<a></a>', aparams)
										.append(document.createTextNode(resp.items[i].title));
					
					//var fileid = resp.items[i].id;
					if (isFolder) {
						(function() {
							var fileid = resp.items[i].id;
							titlea.on('click', function(e){
								gdmFolderViewer.loadFolderContents(jfolderdiv, fileid);
								e.preventDefault();
							});
						})();
						gdmFolderViewer.setFolderTitle(resp.items[i].id, resp.items[i].title);
					}
					
					for (var j=0 ; j<columns.length ; ++j) {
						if (columns[j]=='title') {
							newrow.append(jQuery('<td></td>')
											.append(jQuery('<img></img> &nbsp; ', {src: resp.items[i].iconLink, class: 'gdm-file-icon'}))
											.append(titlea));
						}
						if (columns[j]=='owner') {
							newrow.append(jQuery('<td></td>').append(document.createTextNode(resp.items[i].ownerNames.join())));
						}
						if (columns[j]=='lastmodified') {
							newrow.append(jQuery('<td></td>').append(document.createTextNode(gdmFolderViewer.formatDateTime(resp.items[i].modifiedDate))));
						}
						if (columns[j]=='size') {
							newrow.append(jQuery('<td></td>').append(document.createTextNode(
									typeof(resp.items[i].fileSize) == 'undefined' ? '-' : gdmFolderViewer.bytesToSize(resp.items[i].fileSize))));
						}
					}
			        newtablebody.append(newrow);
				}
			}
			
			if (!nextPageToken) {
				// Was a fresh load, so everything was cleared
				if (newtable) {
					if (newtable.find('tbody tr').length > 0) {
						var tablesorterconfig = {};
						if (sort_index != -1) {
							tablesorterconfig.sortList = [[sort_index, sort_direction]];
						}
						newtable.tablesorter(tablesorterconfig);
						var filelistdiv = jfolderdiv.find('.gdm-folder-filelist');
						filelistdiv.empty();
						filelistdiv.append(newtable);
					}
					else {
						jfolderdiv.find('.gdm-folder-filelist').empty().append(jQuery("<p>There are no files for you to view</p>", 
								{class: 'gdm-folder-error'}));
					}
				}
				
				// Update breadcrumbs
				gdmFolderViewer.storeHistory(jfolderdiv, folderid);
				gdmFolderViewer.updateBreadcrumbs(jfolderdiv);
			}
			else {
				newtable.trigger("update");
				// Remove more link
			}
			
			newtable.find('a.gdm-previewlink').colorbox({innerWidth: '95%', innerHeight: '95%', 
														 rel: 'previewgroup'+gdmFolderViewer.generatePreviewGroupUniqueId(), 
														 current: "{current} of {total}",
														 title: gdmFolderViewer.generateColorboxTitle});
			
			jfolderdiv.find('div.gdm-morebtndiv').remove();
			
			// More...?
			if (resp.nextPageToken) {
				var morelink = jQuery('<a href="#" class="gdm-morelink">More...</a>').on('click', function(e) {
					gdmFolderViewer.turnParentToLoading(e.target);
					gdmFolderViewer.loadFolderContents(jfolderdiv, folderid, resp.nextPageToken);
					e.preventDefault();
				});
				jfolderdiv.append(jQuery('<div></div>', {class: 'gdm-btndiv gdm-morebtndiv'}).append(morelink));
			}
		});
    },
    
    validColumnNames : Array('title', 'owner', 'lastmodified', 'size'),
    
    getColumnNames : function(colNames) {
    	var defaultcols = Array('title', 'owner', 'lastmodified');
    	var cols = (typeof(colNames)=="string" ? colNames.split(",") : Array());
    	var outcols = Array();
    	for (var i=0 ; i<cols.length ; ++i) {
    		var colname = cols[i].trim().toLowerCase();
    		if (jQuery.inArray(colname, gdmFolderViewer.validColumnNames) != -1) {
    			outcols.push(colname);
    		}
    	}
    	if (outcols.length > 0) {
    		return outcols;
    	}
    	return defaultcols;
    },
    
	getSortNames : function(sortcolstr) {
		var sort_column = sortcolstr;
		var sort_direction = 0;

		if (typeof sort_column != "undefined" && sort_column != '') {
			sort_direction = sort_column.substring(0,1) == '-' ? 1 : 0;
			if (sort_direction == 1) {
				sort_column = sort_column.substring(1,sort_column.length); // Remove leading "-"
			}
			if (jQuery.inArray(sort_column, gdmFolderViewer.validColumnNames) == -1) {
				sort_column = '';
			}
		}
		
		return [sort_column, sort_direction];
	},
    
    bytesToSize : function (bytes)
    {  
        var kilobyte = 1024;
        var megabyte = kilobyte * 1024;
        var gigabyte = megabyte * 1024;
        var terabyte = gigabyte * 1024;
       
        if ((bytes >= 0) && (bytes < kilobyte)) {
            return bytes + ' B';
     
        } else if ((bytes >= kilobyte) && (bytes < megabyte)) {
            return (bytes / kilobyte).toFixed(0) + ' KB';
     
        } else if ((bytes >= megabyte) && (bytes < gigabyte)) {
            return (bytes / megabyte).toFixed(2) + ' MB';
     
        } else if ((bytes >= gigabyte) && (bytes < terabyte)) {
            return (bytes / gigabyte).toFixed(2) + ' GB';
     
        } else if (bytes >= terabyte) {
            return (bytes / terabyte).toFixed(2) + ' TB';
     
        } else {
            return bytes + ' B';
        }
    },
    
    _uniquePreviewGroupId : 0,
    generatePreviewGroupUniqueId : function() {
    	return ++gdmFolderViewer._uniquePreviewGroupId;
    },
    
    generateColorboxTitle : function() {
    	var self = jQuery(this);
		var url = self.attr('href');
		var title = gdmFolderViewer.escapeHTML(self.attr('data_gdm_title'));
		var downloadurl = self.attr('data_gdm_downloadurl');
		var viewerurl = self.attr('data_gdm_viewerurl');
		if (viewerurl) {
			title += ' &nbsp;&nbsp; <a href="' + viewerurl + '" target="_blank" class="gdm-open-btn">Open</a>';
		}
		if (downloadurl) {
			title += ' &nbsp;&nbsp; <a href="' + downloadurl + '" class="gdm-download-btn">Download</a>';
		}
		return title;
	},
	
	entityMap : {
	    "&": "&amp;",
	    "<": "&lt;",
	    ">": "&gt;",
	    '"': '&quot;',
	    "'": '&#39;',
	    "/": '&#x2F;'
	  },

	escapeHTML : function(str) {		
	    return String(str).replace(/[&<>"'\/]/g, function (s) {
	      return gdmFolderViewer.entityMap[s];
	    });
	},
    
    generateColorboxAjaxURL : function(drivefile) {
		var dflinks = gdmDriveServiceHandler.getUrlsAndReasons(drivefile);
		
		var cburl = '';
		if (dflinks.extra == 'image') {
			// Fool colorbox into thinking this is an image (which it is actually...)
			cburl = dflinks.embed.url + '&gdm-type=.jpg';
		}
		else {
			cburl = gdm_trans.ajaxurl + '?action=gdm_cb_content'
							+ '&embed'+'='+encodeURIComponent(dflinks.embed.native_url ? dflinks.embed.native_url : dflinks.embed.url);
		}

		return { cburl : cburl, viewerurl : dflinks.viewer.url, downloadurl : dflinks.download.url, title: dflinks.title };
    },
    
    storeHistory : function(jfolderdiv, folderid) {
    	var histStore = jfolderdiv.data('history');
    	if (!histStore) {
    		histStore = [];
    	}
    	if (histStore.length == 0 || histStore[histStore.length-1] != folderid) {
    		histStore.push(folderid);
    	}
    	jfolderdiv.data('history', histStore);
    },
    
    // indexToLand is the 0-based index in hist that you want to land on
    // will remove that entry and all following from hist
    // (since reload will add that folder to hist again once ready)
    backHistory : function(jfolderdiv, indexToLand) {
    	var histStore = jfolderdiv.data('history');
    	if (!histStore || histStore.length == 0 || indexToLand >= histStore.length || indexToLand < 0) {
    		return '';
    	}
    	
    	var destFolderId = histStore[indexToLand];
    	histStore = histStore.slice(0, indexToLand);
    	jfolderdiv.data('history', histStore);
    	return destFolderId;
    },
    
    hasHistory : function(jfolderdiv) {
    	var histStore = jfolderdiv.data('history');
    	return histStore ? histStore.length : 0;
    },

    getHistory : function(jfolderdiv) {
    	var histStore = jfolderdiv.data('history');
    	if (!histStore) {
    		histStore = {};
    	}
    	return histStore;
    },

    _folderTitleStore : {},
    
    setFolderTitle : function(folderid, foldertitle) {
    	gdmFolderViewer._folderTitleStore[folderid] = foldertitle;
    },
    
    getFolderTitle : function(folderid) {
    	return gdmFolderViewer._folderTitleStore[folderid];
    },
    
    turnParentToLoading : function(atarget) {
    	jQuery(atarget).replaceWith(document.createTextNode('Loading...'));
    },
    
    disableLinks : function(jfolderdiv) {
    	jfolderdiv.find('a').attr('disabled', 'disabled');
    },
    
    enableLinks : function(jfolderdiv) {
    	jfolderdiv.find('a').removeAttr('disabled');
    },
    
    formatDateTime : function(dtstr) {
    	var dt = new Date(dtstr);
    	return jQuery.format.date(dt, 'MMM d, yyyy h:mm a');
	},
	
	getAppId : function() {
		var splitar = gdmFolderViewer.CLIENT_ID.split("-");
		if (splitar.length > 1) {
			return splitar[0];
		}
		return '';
	}

};

function gdmProxyRequest(postid, nonce) {
	
	return function(opts) {

		return {
			execute : function(callback) {
				
		    	  jQuery.ajax({
		    		  url: gdm_trans.ajaxurl,
		    		  data: {action: 'gdm_api_proxy', options: opts, postId: postid, nonce: nonce},
		    		  dataType: 'json',
		    		  type: 'POST',
		    		  success: function(resp){
		    		    callback(resp);
		    		  }
		    		}).fail(function(){
		    			callback({error: {message: 'Problem contacting the web server'}});
		    		});
				
			}
		};
	};
};

function gdmDisallowedRequest(opts) {
	return {
		execute : function(callback) {
	    	callback({error: {message: 'You do not have permissions to view this controlled folder'}});
		}
	};
};

var gdmFileUploader = {
		
	makeDropZone : function(jfolderdiv, finalcallback) {
		// Enable drag and drop of files
		jfolderdiv.on('dragover', function (e) 
		{
		     e.stopPropagation();
		     e.preventDefault();
		     jQuery(this).addClass('gdm-folders-dropzone');
		});
		jfolderdiv.on('dragleave', function (e)
		{
			jQuery(this).removeClass('gdm-folders-dropzone');
		     e.preventDefault();
		});
		jfolderdiv.on('drop', function (e) 
		{
			jQuery(this).removeClass('gdm-folders-dropzone');
			e.stopPropagation();
		     e.preventDefault();
		     var files = e.originalEvent.dataTransfer.files;
		 
		     //We need to send dropped files to Server
		     if (files.length) {
		    	 gdmFileUploader.doFileUploads(files, jfolderdiv, finalcallback);
		     }
		});
		
		// Add the Browse.. button version if desired
		if (jfolderdiv.attr('data-gdm-showupload') == '1') {
			var createBrowseButton = function() {
				var btn = jQuery('<input type="file" name="gdm-browse-button" class="gdm-browse-button" multiple>');
				btn.on('change', function(e){
				     //var files = e.originalEvent.dataTransfer.files;
				     var files = e.target.files;
				     
				     //We need to send dropped files to Server
				     if (files.length) {
				    	 jQuery(e.target).replaceWith(createBrowseButton());
				    	 gdmFileUploader.doFileUploads(files, jfolderdiv, finalcallback);
				     }
				});
				return btn;
			};
			
			jfolderdiv.append(jQuery('<div class="gdm-folders-upload-area"><span>Upload: </span></div>')
									.append(createBrowseButton()));
			
		}
	},
	
	doFileUploads : function(files, jfolderdiv, finalcallback) {
		var folderid = jfolderdiv.attr('gdm-folder-currentid');
		
		var uploadstatusdiv = jQuery('<div></div>', {class: 'gdm-folders-uploading'});
		jfolderdiv.append(uploadstatusdiv);
		
		
		var uploadFunction = (jfolderdiv.attr('data-gdm-perms-upload') == 'drive' 
						? gdmFileUploader.insertFileDirect
						: (jfolderdiv.attr('data-gdm-perms-upload') == 'yes' 
								? gdmFileUploader.insertFileProxy(jfolderdiv.attr('data-gdm-postid'), jfolderdiv.attr('data-gdm-nonce')) 
								: gdmFileUploader.insertFileDisallowed));
				
		var filesuploaded = 0;
		var numfiles = files.length;
		var waittime = 1500;
		
		for (var i = 0; i < files.length; i++) 
		{
			var filename = files[i].name;
			
			(function() {
				var onefileupload = jQuery('<p></p>').append(document.createTextNode("Uploading "+filename+"..."));
				uploadstatusdiv.append(onefileupload);
				
				var callback = function(resp) {
					onefileupload.empty();
					if (resp.error) {
						onefileupload.append(document.createTextNode("Error uploading "+filename+" - "+resp.error.message));
						onefileupload.css({'background-color': 'red', 'color': 'white'});
						waittime = 5000;
					}
					else {
						onefileupload.append(document.createTextNode("Finished "+filename));
					}
					++filesuploaded;
					if (filesuploaded == numfiles) {
						setTimeout( function() {
							uploadstatusdiv.remove();
							
							// probably runs gdmFolderViewer.loadFolderContents(jfolderdiv, folderid);
							finalcallback(jfolderdiv);
							
						}, waittime);
					}
				};
				
				uploadFunction(files[i], folderid, callback);
			})();
		}
	},
	
  /**
   * Insert new file.
   *
   * @param {File} fileData File object to read data from.
   * @param {Function} callback Function to call when the request is complete.
   */	
	insertFileDirect : function(fileData, folderid, callback) {
        const boundary = '-------314159265358979323846';
        const delimiter = "\r\n--" + boundary + "\r\n";
        const close_delim = "\r\n--" + boundary + "--";

        var reader = new FileReader();
        reader.readAsBinaryString(fileData);
        reader.onload = function(e) {
          var contentType = fileData.type || 'application/octet-stream';
          var metadata = {
            'title': fileData.name,
            'mimeType': contentType,
            'parents': [{'id': folderid}]
          };

          var base64Data = btoa(reader.result);
          var multipartRequestBody =
              delimiter +
              'Content-Type: application/json\r\n\r\n' +
              JSON.stringify(metadata) +
              delimiter +
              'Content-Type: ' + contentType + '\r\n' +
              'Content-Transfer-Encoding: base64\r\n' +
              '\r\n' +
              base64Data +
              close_delim;

          var request = gapi.client.request({
              'path': '/upload/drive/v2/files',
              'method': 'POST',
              'params': {'uploadType': 'multipart'},
              'headers': {
                'Content-Type': 'multipart/mixed; boundary="' + boundary + '"'
              },
              'body': multipartRequestBody});
          var callbackWrapper = function(resp) {
        	  	  if (!resp.error) {
        	  		  // Direct Drive uploads may need us to notify WP server to change owner/parent of file
        	  		  
        	  		if (gdm_trans.post_id 
        					&& (gdm_trans.gdm_drive_set_embed_sa_owner || gdm_trans.gdm_drive_set_embed_parent)) {
        	  			// console.log(resp);
        	  			var drivefileid = resp.id;
        				gdmSetEmbedSAOwnerParent(drivefileid); // Drive ID always
        			}
        	  		
        	  	  }
	        	  callback(resp);
	          };
          
          request.execute(callbackWrapper);
        }
      },
      
      // Need a way on server to validate we're allowed to use Service Account
      insertFileProxy : function(postid, nonce) {
    	  
    	  return function(fileData, folderid, callback) {
	        	  
	    	  var formData = new FormData();
	    	  
	    	  formData.append('action', 'gdm_file_upload');
	    	  
	    	  formData.append('title', fileData.name);
	    	  var contentType = fileData.type || 'application/octet-stream';
	    	  formData.append('contentType', contentType);
	    	  
	    	  formData.append('folderId', folderid);
	      	    	  
	    	  formData.append('postId', postid);
	    	  formData.append('nonce', nonce);
	    	  
	    	  formData.append('file', fileData, fileData.name);
	    	  
	    	  jQuery.ajax({
	    		  url: gdm_trans.ajaxurl,
	    		  data: formData,
	    		  dataType: 'json',
	    		  processData: false,
	    		  contentType: false,
	    		  type: 'POST',
	    		  success: function(resp){
	    		    callback(resp);
	    		  }
	    		}).fail(function(){
	    			callback({error: {message: 'Problem contacting the web server'}});
	    		});
    	  };
 
      },
      
      insertFileDisallowed : function(fileData, folderid, callback) {
    	  callback({error: {message: 'You do not have permissions to upload to a controlled folder'}});
      } 
};

function gdmFolderGoogleClientLoad() {
	gdmFolderViewer.handleClientLoad();
}

// Stop the rest of the page responding to drag/drop in case user misses the folder dropzone
jQuery(document).ready(function ($) {
	$(document).on('dragenter', function (e) 
	{
	    e.stopPropagation();
	    e.preventDefault();
	});
	$(document).on('dragover', function (e) 
	{
	  e.stopPropagation();
	  e.preventDefault();
	});
	$(document).on('drop', function (e) 
	{
	    e.stopPropagation();
	    e.preventDefault();
	});
});
