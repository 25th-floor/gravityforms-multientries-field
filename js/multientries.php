<script type="text/javascript">
	function StartAddBulkField(type){
		bulk_elements = new Array('begin', 'end');
		var nextId = GetNextFieldId();

		for (var i = 0; i < bulk_elements.length; i++) {
			bulk_type = type + "_" + bulk_elements[i];
			if(! CanFieldBeAdded(bulk_type))
				return;

			var field = CreateField(nextId, bulk_type);
			field[ 'displayOnly' ] = true;

			var mysack = new sack("<?php echo admin_url("admin-ajax.php")?>?id=" + form.id);
			mysack.execute = 1;
			mysack.method = 'POST';
			mysack.setVar( "action", "rg_add_field" );
			mysack.setVar( "rg_add_field", "<?php echo wp_create_nonce("rg_add_field") ?>" );
			mysack.setVar( "field", jQuery.toJSON(field) );
			mysack.encVar( "cookie", document.cookie, false );
			mysack.onError = function() { alert('<?php echo esc_js(__("Ajax error while adding field", "gravityforms")) ?>' )};
			mysack.runAJAX();

			nextId++;
		}

		return true;
	}

	// adding setting to fields of type "text"
	fieldSettings['bulk_section_begin'] += ', .bulk_required_setting, .bulk_labels_setting, .bulk_label_setting, .bulk_addlabel_setting, .bulk_removelabel_setting, .label_setting';

	// binding to the load field settings event to initialize the checkbox
	jQuery(document).bind('gform_load_field_settings', function(event, field, form) {
		jQuery('#field_bulk_required').attr('checked', field['required'] === true);
		jQuery('#field_bulk_label').val(field['bulkLabel']);
		jQuery('#field_bulk_addlabel').val(field['addLabel']);
		jQuery('#field_bulk_removelabel').val(field['removeLabel']);
	});
</script>