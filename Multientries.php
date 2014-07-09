<?php
/*
Plugin Name: Gravity Forms Multientries Field Addon
Plugin URI: http://25th-floor.com/
Author: 25th-floor - Operating Custom Solutions KG, Martin Prebio
Author URI: http://25th-floor.com/
Version: 0.3
*/

GF_Plugin_Multientries::init();
GF_Plugin_Multientries::admin_init();

/**
 * Extends GF to support bulk datasets
 */
class GF_Plugin_Multientries {
	/**
	 * Initialize this plugin
	 *
	 * @static
	 * @return void
	 */
	public static function init() {
		// Add JS
		add_action( 'gform_enqueue_scripts', array(__CLASS__, 'enqueue_bulk_scripts' ), 10, 2);
		add_filter( 'gform_add_field_buttons',  array( __CLASS__, 'add_bulk_field_button' ) );
		add_action( 'gform_field_input', array( __CLASS__, 'bulk_field_input' ), 10, 5 );
		add_action( 'gform_field_content',  array( __CLASS__, 'bulk_field_content' ), 10, 3 );
		add_action( 'gform_field_css_class',  array(__CLASS__, 'bulk_field_css_class' ), 10, 3 );
		add_action( 'gform_editor_js', array( __CLASS__, 'bulk_field_editor_script' ) );
		add_action( 'gform_field_standard_settings', array( __CLASS__, 'bulk_field_standard_settings' ), 10, 2 );
		add_filter( 'gform_tooltips', array( __CLASS__, 'add_bulk_field_tooltips' ) );
		add_filter( 'gform_tabindex', create_function( '', 'return false;' ) );
		add_filter( 'gform_get_field_value', array( __CLASS__, 'unfold_bulk_field_value' ), 10, 3 );
		add_filter( 'gform_notification', array( __CLASS__, 'bulk_notification_email' ), 10, 2 );
		// Add custom validation
		add_filter( 'gform_validation', array( __CLASS__, 'validate' ) );

		add_filter( 'gform_pre_submission_filter', array( __CLASS__, 'bulk_pre_submission' ) );
		add_filter( 'gform_entry_field_value', array( __CLASS__, 'bulk_data' ), 10, 4 );
		add_action( 'gform_admin_pre_render', array( __CLASS__, 'remove_bulk_fields_from_detail' ) );
	}

	/**
	 * @static
	 * @return void
	 */
	public static function admin_init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'load_bulk_admin_scripts') );
	}

	public static function load_bulk_admin_scripts() {
		if ( RG_CURRENT_VIEW === 'entry' && isset( $_GET[ 'lid' ] )) {
			wp_enqueue_script( 'datatables' , self::_get_base_url() . '/js/jquery.dataTables.min.js', array( 'jquery' ), '1.8' );
		}
	}

	/**
	 * 'gform_admin_pre_render' hook: removing bulk-fields from form
	 *
	 * @todo inform user that editing these values is not possible
	 * @static
	 * @param  array $form
	 * @return array $form
	 */
	public static function remove_bulk_fields_from_detail( $form ) {
		$bulk_form = array();
		
		// only strip bulk fields in entry detail view
		if ( RG_CURRENT_VIEW !== 'entry' )
			return $form;

		self::splice_form( $form );

		return $form;
	}

	/**
	 * Add bulk-section button to GravityForms builder
	 *
	 * @static
	 * @param  $field_groups
	 * @return
	 */
	public static function add_bulk_field_button( $field_groups) {
	    foreach ( $field_groups as &$group ) {
	        if ( $group[ 'name' ] === 'advanced_fields' ) {
	            $group[ 'fields' ][] = array(
		            'class' => 'button',
		            'value' => __( 'Bulk-Section', 'gravityforms'),
		            'onclick' => "StartAddBulkField('bulk_section');"
	            );
	            break;
	        }
	    }

	    return $field_groups;
	}

	/**
	 * @static
	 * @param  $position
	 * @param  $form_id
	 * @return void
	 */
	public static function bulk_field_standard_settings( $position, $form_id ) {
		// create settings on position 50 (right after Admin Label)
		if( $position == 50 ) {
			?>
			<li class="bulk_required_setting field_setting">
				<?php _e( 'Rules', 'gravityforms'); ?><br/>
				<input type="checkbox" id="field_bulk_required" onclick="SetFieldProperty( 'required', this.checked );" />
				<label for="field_bulk_required" class="inline">
					<?php _e( 'Required', 'gravityforms'); ?>
					<?php gform_tooltip( 'form_field_bulk_required' ) ?>
				</label>
			</li>
			<li class="bulk_labels_setting field_setting">
				<div class="bulk_label_setting field_setting">
					<label for="field_bulk_label" class="inline">
						<?php _e( 'Block label', 'gravityforms' ); ?>
						<?php gform_tooltip("form_field_bulk_label") ?>
					</label>
					<input type="text" id="field_bulk_label" onkeyup="SetFieldProperty('bulkLabel', this.value);" />
				</div>
				<div class="bulk_addlabel_setting field_setting">
					<label for="field_bulk_addlabel" class="inline" style>
						<?php _e( 'Add label', 'gravityforms' ); ?>
						<?php gform_tooltip("form_field_bulk_addlabel") ?>
					</label>
					<input type="text" id="field_bulk_addlabel" onkeyup="SetFieldProperty('addLabel', this.value);" />
				</div>
				<div class="bulk_removelabel_setting field_setting">
					<label for="field_bulk_removelabel" class="inline">
						<?php _e( 'Remove label', 'gravityforms' ); ?>
						<?php gform_tooltip("form_field_bulk_removelabel") ?>
					</label>
					<input type="text" id="field_bulk_removelabel" onkeyup="SetFieldProperty('removeLabel', this.value);" />
				</div>
			</li>
			<?php
		}
	}

	/**
	 * Filter for 'gform_entry_field_value' that will render bulk-entries in a nice dataTable
	 *
	 * @see http://www.datatables.net
	 * @static
	 * @param  $value
	 * @param  $field
	 * @param  $lead
	 * @param  $form
	 * @return string
	 */
	public static function bulk_data( $value, $field, $lead, $form ) {
		if ( $field[ 'type' ] === 'bulk_section_begin' ) {
			// get bulk-store, $value can't be used because it's shortened (GFORMS_MAX_FIELD_LENGTH)
			$store_data = json_decode( RGFormsModel::get_lead_field_value( $lead, $field ) , true );

			// looping through bulk store to inject each field's label
			// @todo: nested ref klaeren
			foreach ( $store_data as &$store_entry ) {
				foreach ( $store_entry as $entry_key => &$entry_value) {
					if ( preg_match( '(\d+)', $entry_key, $matches ) ) {
						$bulk_field = RGFormsModel::get_field( $form, $matches[0] );
						$entry_value[ 'label' ] = str_replace( ':', '', $bulk_field[ 'label' ] );
					}
				}
			}

			// encode modified bulk store in JSON (again) for passing it to dataTables
			$json_data = json_encode( $store_data );

			$value = <<<HTML
				<table cellpadding="0" cellspacing="0" border="0" class="bulk-data"></table>
				<script type="text/javascript">
					var json_store = JSON.parse('$json_data');
					var aTableData = new Array();
					var aTableMeta = new Array({ sTitle: '#' });

					jQuery.each(json_store, function(i, line) {
						var aTableRow = new Array('');
						jQuery.each(line, function(field, data) {
							aTableRow.push(data.value);
							if(i === 0) {
								aTableMeta.push({ sTitle: data.label });
							}
						});

						aTableData.push(aTableRow);
					});

					jQuery('.bulk-data').dataTable({
						sDom: '',
						aaData: aTableData,
						aoColumns: aTableMeta,
						aoColumnDefs: [
							{ "bSortable": false, "aTargets": [ 0 ] }
						],
						fnDrawCallback: function(oSettings) {
							if(oSettings.bSorted || oSettings.bFiltered) {
								for(var i = 0, iLen = oSettings.aiDisplay.length ; i < iLen ; i++) {
									jQuery('td:eq(0)', oSettings.aoData[oSettings.aiDisplay[i]].nTr ).html(i+1);
								}
							}
						}
					});
				</script>
HTML;
		}

		return $value;
	}

	/**
	 * @static
	 * @param array $tooltips
	 * @return array
	 */
	public static function add_bulk_field_tooltips( $tooltips ) {
		$tooltips[ 'form_field_bulk_required' ] = "<h6>Constraint</h6>Check this box to require at least one bulk section input";
		$tooltips[ 'form_field_bulk_label' ] = "<h6>Block label</h6>Title for each bulk section/block";
		$tooltips[ 'form_field_bulk_addlabel' ] = "<h6>Add label</h6>Text for the button to add a new section/block";
		$tooltips[ 'form_field_bulk_removelabel' ] = "<h6>Delete label</h6>Text for the button to delete the current section/block";

		return $tooltips;
	}

	/**
	 * Unfolds the bulk fields value (JSON) for e-Mail notification
	 * 
	 * @static
	 * @param $value
	 * @param $lead
	 * @param $field
	 * @return string
	 */
	public static function unfold_bulk_field_value ( $value, $lead, $field ) {
		if( IS_ADMIN === true )
			return $value;

		if( $field['type'] === 'bulk_section_begin' ) {
			$form = RGFormsModel::get_form_meta( $field['formId'] );
			$store_data = json_decode(
				$value,
				true
			);
			$email_content = '';
			$entry_counter = 1;

			foreach ( $store_data as $store_entry ) {
				$bulk_header = sprintf( "%s %s", $field['bulkLabel'], $entry_counter++ );
				$email_content .= sprintf( "%s\n", $bulk_header );
				for( $i = 1; $i < strlen($bulk_header); $i++ ) {
					$email_content .= "-";
				}
				$email_content .= "\n";

				foreach ( $store_entry as $entry_key => $entry_value) {
					if ( preg_match( '/^.*_([0-9]+)$/', $entry_key, $matches ) ) {
						$bulk_field = RGFormsModel::get_field( $form, $matches[1] );
						$email_content .= sprintf( "%s %s\n", $bulk_field['label'], $entry_value['value'] );
					}
				}

				$email_content .= "\n";
			}

			return $email_content;
		} else {
			return $value;
		}
	}

	/**
	 * @static
	 * @param  $content
	 * @param  $field
	 * @param  $form_id
	 * @return string
	 */
	public static function bulk_field_content( $content, $field, $form_id ) {
		$id = $field['id'];
		$delete_field_link = "<a class='field_delete_icon' id='gfield_delete_$id' title='" . __("click to delete this field", "gravityforms") . "' href='javascript:void(0);' onclick='StartDeleteField(this);'>" . __("Delete", "gravityforms") . "</a>";
		$src1 = GFCommon::get_base_url() . "/images/gf_pagebreak_first.png";
		$src2 = GFCommon::get_base_url() . "/images/gf_pagebreak_end.png";
		$page_label = __( 'Bulk Section', 'gravityforms');
		$admin_buttons = IS_ADMIN ? $delete_field_link . " <a class='field_edit_icon edit_icon_collapsed' title='" . __("click to edit this field", "gravityforms") . "'>" . __("Edit", "gravityforms") . "</a>" : "";
		if ( $field['type'] === 'bulk_section_begin') {
			if ( IS_ADMIN ) {
				if ( $_GET['page'] === 'gf_entries' ) {
					return $content;
				} else {
					return sprintf( "%s <label class='gfield_label'>&nbsp;</label><img src='%s' alt='%s' title='%s' />{FIELD}", $admin_buttons, $src1, $page_label, $page_label);
				}
			} else {
				// adding field and passing some behaviour settings to the fields JavaScript code (multientries.js)
				$content = <<<HTML
					{FIELD}
					<script type="text/javascript">
						gf_bulk_field = {
							id: {$field['id']},
							label: "{$field['label']}",
							required: {$field['required']},
							bulkLabel: "{$field['bulkLabel']}",
							addLabel: "{$field['addLabel']}",
							removeLabel: "{$field['removeLabel']}"
						};
					</script>
HTML;
			}
		} elseif ( $field['type'] === 'bulk_section_end') {
			return ( IS_ADMIN)
				? sprintf( "%s <label class='gfield_label'>&nbsp;</label><img src='%s' alt='%s' title='%s' />{FIELD}", $admin_buttons, $src2, $page_label, $page_label)
				: '{FIELD}';
		}

		return $content;
	}

	/**
	 * @static
	 * @param  $input
	 * @param  $field
	 * @param  $value
	 * @param  $lead_id
	 * @param  $form_id
	 * @return string
	 */
	public static function bulk_field_input( $input, $field, $value, $lead_id, $form_id ) {
		if ( $field['type'] === 'bulk_section_begin' ) {
	        $field_id = ( IS_ADMIN || $form_id == 0 ) ? "input_$id" : 'input_' . $form_id . '_' . $field['id'];
			$disabled_text = ( IS_ADMIN && RG_CURRENT_VIEW !== 'entry' ) ? "disabled='disabled'" : '';

			$input = sprintf( "<input name='input_%d' id='%s' type='hidden' class='gform_hidden' value='%s' %s/>",
			                 $field['id'], $field_id, esc_attr( $value ), $disabled_text);
		}

		return $input;
	}

	/**
	 * Insert custom JavaScript (PHP parsed) when displaying a form in admin
	 *
	 * @return void
	 */
	public static function bulk_field_editor_script() {
		require_once( self::_get_base_path() . '/js/multientries.php' );
	}

	/**
	 * Add custom JavaScript when displaying form
	 *
	 * @param  $form
	 * @param  $is_ajax
	 * @return void
	 */
	public function enqueue_bulk_scripts( $form, $is_ajax ) {
		wp_enqueue_script( 'custom_script', '/wp-content/plugins/' . basename(dirname(__FILE__)) . '/js/multientries.js' );
	}

	/**
	 * @static
	 * @param  $classes
	 * @param  $field
	 * @param  $form
	 * @return string
	 */
	public static function bulk_field_css_class( $classes, $field, $form ) {
		if ( $field['type'] === 'bulk_section_begin' ) {
			$classes .= ' bulk_section_begin';
		} elseif ( $field['type'] === 'bulk_section_end' ) {
			$classes .= ' bulk_section_end';
		}

		return $classes;
	}

	/**
	 * Validates the nested JSON data
	 *
	 * @see http://www.gravityhelp.com/documentation/page/Using_the_Gravity_Forms_%22gform_valiation%22_Hook
	 * @param  array $validation_result
	 * @return array
	 */
	public function validate( $validation_result ) {
		static $is_recursive;
		$bulk_form = array();

		// avoid recursive calls because GFFormDisplay::validate() will execute this filter again
		if ( $is_recursive === true ) {
			return $validation_result;
		} else {
			$is_recursive = true;
		}

		// Get the form object from the validation result
		$form = $bulk_form = $validation_result["form"];

		// Get the current page being validated
		$current_page = rgpost( "gform_source_page_number_{$form['id']}" ) ? rgpost( "gform_source_page_number_{$form['id']}" ) : 1;

		// form-splicing
		$bulk_field_store_id = self::splice_form( $form, $bulk_form );

		// validate form (without bulk fields)
		$form_is_valid = GFFormDisplay::validate( $form, '', $current_page);

		if ( !$bulk_field_store_id ) {
			$validation_result['is_valid'] = $form_is_valid;
			return $validation_result;
		}

		// decode bulk store
		$store_data = json_decode(
			stripslashes_deep( rgpost( "input_{$bulk_field_store_id}" ) ),
			true
		);
		if ( !is_array( $store_data ) ) {
			$validation_result['is_valid'] = $form_is_valid;
			return $validation_result;
		}

		/* validate each and every bulk entry (from store) - each $story_entry contains one bulk entry.
		   GFormDisplay::validate() uses $stripped_form metadata (fields) to validate corresponding $_POST data. */
		foreach ( $store_data as &$store_entry ) {
			// reset validation state of bulk form fields
			foreach ($bulk_form['fields'] AS &$field) {
				$field['failed_validation'] = '';
				$field['validation_message'] = null;
			}

			// inject each bulk entry data into POST for correct validation
			foreach ( $store_entry as $entry_key => $entry_value) {
				$_POST[ $entry_key ] = $entry_value['value'];
			}

			// validate bulk fields only
			$bulk_form_is_valid = GFFormDisplay::validate( $bulk_form, '', $current_page);

			// injecting validation data into bulk store
			if ( $bulk_form_is_valid === false ) {
				foreach ( $bulk_form['fields'] as $field) {
					if ( $field['failed_validation'] === true ) {
						$store_entry[ "input_{$field['id']}" ][ 'error' ] = $field['validation_message'];
					}
				}
			}

			// inject field label to json-store; required for displaying the entry afterwards
			// $store_entry[ ]
		}

		// encode bulk store again and update POST data
		$store_data = json_encode( $store_data );
		$_POST[ "input_{$bulk_field_store_id}" ] = addslashes( $store_data );

		$validation_result['is_valid'] = $form_is_valid && $bulk_form_is_valid;

		return $validation_result;
	}

	/**
	 * Returns plugin base directory
	 *
	 * @static
	 * @return string
	 */
	private static function _get_base_path() {
		return WP_PLUGIN_DIR . "/" . basename(dirname(__FILE__));
	}

	private static function _get_base_url() {
		return plugins_url( basename( dirname( __FILE__ ) ) );
 	}

	/**
	 * This filter-hook will avoid bulk-fields from being stored to database. Only the hidden field with
	 * the JSON data will be stored.
	 *
	 * @static
	 * @param  array $form full GravityForms form meta-information
	 * @return array $form GravityForms form meta-information without bulk fields
	 */
	public static function bulk_pre_submission( $form ) {
		self::splice_form( $form );

		return $form;
	}

	/**
	 * Modifies autoresponder recipients: if the defined autoresponder field is within the bulk section
	 * the corresponding field values (referenced by $field['id']) will be used for the e-mail autoresponse.
	 *
	 * e.g.: person1@mail.com, person2@mail.com, person3@mail.com
	 *
	 * Caution:
	 * for the bulk section field to be available to chose in the notification section it has to be mandatory!
	 *
	 * @static
	 * @param $notification
	 * @param $form
	 */
	public static function bulk_notification_email( $notification, $form ) {
		$mailto = Array();

		if ( $notification['name'] === 'Administrator-Benachrichtigung' ) {
			return $notification;
		}

		// get json-serialized hidden field id
		foreach( $form['fields'] as $field_index => $field ) {
			if ( $field['type'] === 'bulk_section_begin' )
				$bulk_store_id = $field['id'];
		}

		if ( !isset( $bulk_store_id ) )
			return $notification;

		$store_data = json_decode(
			stripslashes_deep( $_POST['input_' . $bulk_store_id] ),
			true
		);

		foreach ( $store_data as $store_entry ) {
			foreach ( $store_entry as $entry_key => $entry_value) {
				if ( !preg_match( '/^.*_([0-9]+)$/', $entry_key, $matches ) )
					continue;

				if ( $form['autoResponder']['toField'] == $matches[1] ) {
					$mailto[] = $entry_value['value'];
				}
			}
		}

		$notification['toType'] = 'custom';
		$notification['to'] = join( ',', $mailto );

		return $notification;
	}

	/**
	 * Splices bulk fields from $form and populates $bulk_form['fields'] with them
	 * 
	 * @static
	 * @param $form
	 * @param $bulk_form
	 * @param $splice
	 * @return mixed $bulk_store_id false or id of the bulk store field
	 */
	public static function splice_form( &$form, &$bulk_form = array(), $splice = true) {
		// ... this is strange, why reset $bulk_form to an empty array?
		$bulk_form = array();
		$bulk_store_id = false;
		$bulk_begin_index = 0;
		$bulk_end_index = null;

		// get bulk section begin and end index as well as the json-serialized hidden field id
		foreach( $form['fields'] as $field_index => $field ) {
			// bulk section begins
			if ( $field['type'] === 'bulk_section_begin' ) {
				$bulk_begin_index = $field_index + 1;
				$bulk_store_id = $field['id'];
			// bulk section ends
			} elseif ( $field['type'] === 'bulk_section_end' ) {
				$bulk_end_index = $field_index;
			}
		}

		if( $splice ) {
			$bulk_form['fields'] = array_splice(
				$form['fields'],
				$bulk_begin_index,
				$bulk_end_index - $bulk_begin_index
			);
		} else {
			$bulk_form['fields'] = array_slice(
				$form['fields'],
				$bulk_begin_index,
				$bulk_end_index - $bulk_begin_index
			);
		}

		// re-inject some necessary data
		$bulk_form['id'] = $form['id'];

		return $bulk_store_id;
	}
}
