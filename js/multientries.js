jQuery(function(){
	// Check for bulk field - Only one is allowed per form!
	var bulk_element = jQuery('li.bulk_section_begin');
	var bulk_data = jQuery('.gfield .bulk_data');
	var json_store;

	if (bulk_element.length == 1) {
		var gform = bulk_element.parents('form');
		var bulk_add_button = jQuery('<span class="gf_bulk_add"><img src="/wp-content/plugins/gravityforms-multientries-field/images/add.png" />' + gf_bulk_field.addLabel + '</span>');

		/**
		 * First: Create a cloneable (and empty!) prototype
		 */
		var elements = bulk_element.nextUntil('.bulk_section_end');
		elements.detach();

		// Remove validation messages
		elements.removeClass('gfield_error').children('.validation_message').remove();
		// Remove element values
		jQuery(':input', elements).val('');

		// bulk form with Add button!
		var bulk_form = jQuery('<div id="bulk-form"></div>');
		bulk_form.insertAfter('li.bulk_section_begin');

		var sub_form_prototype = jQuery('<div class="gf_bulk_form"><ul></ul></div>');

		jQuery.each(elements, function(a, b) {
			sub_form_prototype.children('ul').append(jQuery(b).clone().show().removeAttr('id'));
		});

		/**
		 * Second: Create callback for creating new sub forms
		 */
		var new_sub_form = function(is_mandatory) {
			var entry_count = jQuery('.gf_bulk_form').length;
			var subform = sub_form_prototype.clone().appendTo(bulk_form);
			jQuery('<div class="gf_bulk_header"><span class="gf_bulk_title">' + gf_bulk_field.bulkLabel + ' ' + ++entry_count + '</span></div>').insertBefore(subform.children().first());
			jQuery('.gf_bulk_header', subform).append(bulk_add_button.clone(true, true));

			if (is_mandatory !== true) {
				jQuery('.gf_bulk_header', subform).append('<span class="gf_bulk_remove"><img src="/wp-content/plugins/gravityforms-multientries-field/images/delete.png" />' + gf_bulk_field.removeLabel + '</span>');
				jQuery('.gf_bulk_remove', subform).click(function(a,b,c){
					// jQuery('.gf_bulk_add', subform).insertBefore(jQuery('.gf_bulk_form').eq(-2).children('.gf_bulk_header').children('.gf_bulk_remove'));
					// Remove SubForm
					jQuery(this).parent().parentsUntil('#bulk-form').remove();

					// Renumber all remaining SubForms
					jQuery('div.gf_bulk_form').children('p').each(function(i, element){
						jQuery(element).text(gf_bulk_field.bulkLabel + ' ' + (i+1));
					});
				});
			}

			return subform;
		};

		// Add Button
		bulk_add_button.bind('click', function() {
			var subform = new_sub_form(false)
			jQuery('body').scrollTop(
					subform.offset().top - jQuery('body').offset().top - 100
			);
		});

		// SubmitHandler for serialization and validation (!!)
		var validate = function () {
			var data = [];

			jQuery('#bulk-form .gf_bulk_form').each(function(i, subform) {
				var data_entry = {};
				jQuery('li.gfield', subform).each(function(j, element) {
					var field = jQuery('.ginput_container', element).children();
					data_entry[field.attr('name')] = {
						value: field.val()
					};
				});
				data.push(data_entry);
			})

			bulk_element.children('input').val(JSON.stringify(data));
		};

		jQuery('input[type=submit]',gform).bind('click', validate);

		/**
		 * Last: Fill the form with the provided data
		 *  - Data can come from:
		 *    - Previous submit (with validation errors)
		 *    - Bulk field's default values
		 */
		json_store = (jQuery('input', bulk_element).val())
			? JSON.parse(jQuery('input', bulk_element).val())
			: new Array();

		if (json_store.length > 0) {
			var json_index = 1;
			jQuery.each(json_store, function(i, line){
				var subform = (gf_bulk_field.required === 1 && json_index == 1)
					? new_sub_form(true)
					: new_sub_form(false);

				// Populate subform
				jQuery.each(line, function(field, data) {
					bulk_value_element = jQuery('input[name="' + field + '"]', subform);

					if(bulk_value_element.prop('tagName') != undefined) {
						bulk_value_element.val(data.value);
					} else {
						jQuery('select[name="' + field + '"]', subform).val(data.value);
					}

					if (typeof data.error != 'undefined') {
						bulk_value_element.parent().parent().addClass('gfield_error').append(
							jQuery('<div class="gfield_description validation_message">'+data.error+'</div>')
						);
					}
				});

				json_index++;
			});
		} else if (gf_bulk_field.required === 1) {
			new_sub_form(true);
		}
	}
});