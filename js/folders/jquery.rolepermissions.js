(function( $ ) {

    $.fn.rolepermissions = function(options) {
    	
    	var opts = $.extend( {}, $.fn.rolepermissions.defaults, options );
    	
    	return this.each(function () {
    		// if no thead or tbody quit.
            if (!this.tHead || !this.tBodies) {
            	return;
            }
            
            addToolColumn(this);
            addSelectChangeEvent(this);
    	});
    	
    	function insertNewRow($table, rolekey, rolename) {
    		var $firstrow = $table.find('tbody tr').first();
    		var $newrow = $firstrow.clone();
    		$newrow.attr('data-gdm-rp-row', rolekey);
    		
    		var $newrowcells = $newrow.find('td');
    		$newrowcells.first().empty().append(document.createTextNode(rolename));
    		$newrowcells.last().empty();
    		
    		// Rename ids and names
    		$newrowcells.find('select').each(function(){
    			var $sel = $(this);
    			$sel.attr('name', $sel.attr('name').replace('_users__', rolekey+'_'))
    				  .attr('id', $sel.attr('id').replace('_users__', rolekey+'_'));
    		});
    		
    		addDeleteButton($newrow);
    		
    		$newrow.insertAfter($firstrow);
    		
    		addSelectChangeEvent($newrow);
    		
    		$table.trigger('rolepermissions.addedrow', {rolename: rolename, rolekey: rolekey});
    	};
    	
    	function addRoleMenuItem($table, $rolemenu, rolekey, rolename) {
    		var $menuitem = $('<li></li>', {'data-rolekey': rolekey})
								.append(document.createTextNode(rolename));
    		
			$menuitem.on('click', function(e){
				insertNewRow($table, $(this).attr('data-rolekey'), this.innerHTML);
				$rolemenu.hide();
				$menuitem.remove();
			});
			$rolemenu.append($menuitem);
    	};
 
	    function addToolColumn(table) {
	    	var $table = $(table);
	    	$table.find('thead tr').append($('<th></th>', {class: 'gdmrp-toolcol'}));
	    	$table.find('tbody tr').append($('<td></td>', {class: 'gdmrp-toolcol'}));
	    	
	    	// Add New Role to first row
	    	var $firstrowtoolcell = $table.find('tbody tr td.gdmrp-toolcol').first();
	    	var $rolemenu = $('<ul></ul>', {class: 'gdm-rolemenu'}).css({display: 'none', position: 'absolute'});
	    	
	    	for (var rolekey in opts.availableroles) {
	    		// Only add if not already available in the table
	    		if ($table.find('tr[data-gdm-rp-row='+rolekey+']').length == 0) {
	    			addRoleMenuItem($table, $rolemenu, rolekey, opts.availableroles[rolekey]);
	    		}
	    	}
	    	
	    	var $addbutton = $('<a href="#" title="Add a new row for a specific WordPress role">Add Role</a>').on('click', function(e){
	    		$rolemenu.toggle();
	    		e.preventDefault();
	    	});
	    	$firstrowtoolcell.append($addbutton).append($rolemenu);
	    	
	    	// Add Delete buttons to all cells that correspond to roles
	    	$table.find('tbody tr').each(function() {
	    		var $this = $(this);
	    		var rolekey = $this.attr('data-gdm-rp-row');
	    		if (opts.availableroles.hasOwnProperty(rolekey)) {
	    			addDeleteButton($this);
	    		}
	    	});
	    };
	    
	    function addSelectChangeEvent(tableorrow) {
	    	var $tableorrow = $(tableorrow);
	    	$tableorrow.find('select').on('change', function(e) {
	    		$tableorrow.trigger('rolepermissions.selectchanged');
	    	});
	    };
	    
	    function addDeleteButton($row) {
			$row.find('td.gdmrp-toolcol').append($('<a href="#" title="Delete row">X</a>').on('click', function(e){
				// Add it back to dropdown list
				var rolekey = $row.attr('data-gdm-rp-row');
				var rolename = $row.find('td').first().html();
				var $table = $row.parents('table');
				var $rolemenu = $table.find('tr td.gdmrp-toolcol ul');
				addRoleMenuItem($table, $rolemenu, rolekey, rolename);
				
				$row.remove();
				e.preventDefault();
	    		$table.trigger('rolepermissions.deletedrow', {rolename: rolename, rolekey: rolekey});
			}));
	    };
	    
        return this;
    };
    
    $.fn.rolepermissions.defaults = {
    	'availableroles': typeof gdm_trans != 'undefined' ? gdm_trans.wp_roles : {}
    };
    
}( jQuery ));
