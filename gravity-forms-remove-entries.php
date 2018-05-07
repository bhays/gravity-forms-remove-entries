<?php
/*
Plugin Name: Gravity Forms Remove Entries
Plugin URI: https://github.com/bhays/gravity-forms-remove-entries
Description: Remove multiple entries from Gravity Forms. Optionally select a timeframe of removals or remove all.
Version: 0.5
Author: Ben Hays
Author URI: http://benhays.com

------------------------------------------------------------------------
Copyright 2018 Ben Hays

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('init',  array('GFRemove', 'init'));
register_activation_hook( __FILE__, array("GFRemove", "add_permissions"));

class GFRemove {

    private static $path                        = "gravity-forms-remove-entries/gravity-forms-remove-entries.php";
    private static $url                         = "http://www.gravityforms.com";
    private static $slug                        = "gravity-forms-remove-entries";
    private static $version                     = "0.3.3";
    private static $min_gravityforms_version    = "1.5";

    public static function init(){
		//supports logging
		add_filter("gform_logging_supported", array("GFRemove", "set_logging_supported"));

		if(basename($_SERVER['PHP_SELF']) == "plugins.php") {
            //loading translations
            load_plugin_textdomain('gravity-forms-remove-entires', FALSE, '/gravity-forms-remove-entires/languages' );
        }

        if(!self::is_gravityforms_supported()){
           return;
        }

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravity-forms-remove-entires', FALSE, '/gravity-forms-remove-entires/languages' );

            add_action('install_plugins_pre_plugin-information', array('GFRemove', 'display_changelog'));
        }

        //integrating with Members plugin
		if(function_exists('members_get_capabilities')){
			add_filter('plugin_name_capability', array("GFRemove", "gf_remove_get_capabilities"));
			add_filter('members_get_capabilities', array("GFRemove", "gf_remove_extra_caps"));
		}

        //creates the subnav left menu
        add_filter("gform_addon_navigation", array('GFRemove', 'create_menu'));

        if(self::is_remove_page()){

            //loading Gravity Forms tooltips
            require_once(GFCommon::get_base_path() . "/tooltips.php");
            add_filter('gform_tooltips', array('GFRemove', 'tooltips'));

        } else {
	        // Nothing else, it's all in the admin
		}
    }

    // Return member role capability
    public static function gf_remove_get_capabilities(){
	    return 'gravity_forms_remove_entries';
    }

	public static function gf_remove_extra_caps( $caps ) {
		$caps[] = 'gravity_forms_remove_entries';
		return $caps;
	}

    // Page that does the magic
    public static function remove_page()
    {
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("Remove Entries Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravity-forms-remove"));
        }
        if( isset($_POST['gf_remove_submit']) && empty($_POST["gf_remove_form"]) ){
        ?>
            <div class="updated fade" style="padding:6px"><?php _e("Please select a form first.", "gravity-forms-remove") ?></a></div>
		<?php
        }
        elseif (!empty($_POST["gf_remove_form"])){

            check_admin_referer("list_action", "gf_remove_survey");
            $form = absint($_POST["gf_remove_form"]);

			if( $_POST['gf_remove_type'] == 'date' )
			{
				$entries = self::remove_entries_by_date($form, self::date_make_pretty('begin'), self::date_make_pretty('end'));
			}
			else
			{
				$entries = self::remove_all_entries($form);
			}
            if( !$entries )
            {
	            self::log_debug("Remove all entries returned false");
            }

            ?>
	    	<style>
				.gfield_required{color:red;}
				.feeds_validation_error{ background-color:#FFDFDF;}
				.feeds_validation_error td{ margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border-top:1px dotted #C89797; border-bottom:1px dotted #C89797}
				.left_header{float:left; width:200px;}
				.margin_vertical_10{margin: 10px 0;}
				#remove_doubleoptin_warning{padding-left: 5px; padding-bottom:4px; font-size: 10px;}
				.remove_group_condition{padding-bottom:6px; padding-left:20px;}
			</style>

            <div class="updated fade" style="padding:6px"><?php printf(__("%d entries removed.", "gravity-forms-remove"), $entries) ?> <a href="admin.php?page=gf_entries&view=entries&id=<?php echo $form ?>&filter=trash"><?php _e('Visit your newly trashed entries.', 'gravity-forms-remove') ?></a></div>
            <?php
        }

        ?>
		<div class="wrap">

			<h2><?php _e("Remove Entries", "gravity-forms-remove"); ?></h2>
			<p><?php _e("Selecting a form and hitting 'Remove Entires' will mark entries as trashed. Entries will not be completely removed, this can be done via remove all in the trash.",'gravity-forms-remove') ?></p>
			<form id="remove_entries_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_remove_survey') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

				<div id="remove_form_container" valign="top" class="margin_vertical_10">

					<label for="gf_remove_form" class="left_header"><?php _e("Gravity Form", "gravity-forms-remove"); ?> <?php gform_tooltip("remove_gravity_form") ?></label>

					<select id="gf_remove_form" name="gf_remove_form">
						<option value=""><?php _e("Select a form", "gravity-forms-remove"); ?> </option>
						<?php
						$forms = RGFormsModel::get_forms();
						foreach($forms as $form):
						?>
						<option value="<?php echo absint($form->id) ?>"><?php echo esc_html($form->title) ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div id="remove_form_type" valign="top" class="margin_vertical_10">

					<label for="gf_remove_type" class="left_header"><?php _e("Remove which entries?", "gravity-forms-remove"); ?> <?php gform_tooltip("remove_gravity_type") ?></label>

					<select id="gf_remove_type" name="gf_remove_type">
						<option value="all">All Entries</option>
						<option value="date">By Date</option>
						<?php /* not yet <option value="conditional">By Condition</option>*/ ?>
					</select>
				</div>
				<div id="date_wrap">
					<div class="margin_vertical_10">
						<label for="gf_remove_date_begin" class="left_header"><?php _e("Beginning on", "gravity-forms-remove"); ?> <?php gform_tooltip("remove_gravity_begin") ?></label>
						<?php echo GFRemove::time_selection_display('begin') ?>
					</div>
					<div class="margin_vertical_10">
						<label for="gf_remove_date_end" class="left_header"><?php _e("Ending on", "gravity-forms-remove"); ?> <?php gform_tooltip("remove_gravity_end") ?></label>
						<?php echo GFRemove::time_selection_display('end') ?>
					</div>
					<p class="description"><?php _e("To remove all entries before or after a certain date, set the other date value far in the future or past.",'gravity-forms-remove') ?></p>
				</div>
				<div id="remove_submit_container" class="margin_vertical_10">
					<input type="submit" name="gf_remove_submit" value="<?php _e("Remove Entries", "gravity-forms-remove") ?>" class="button-primary"/>
				</div>
            </form>
        </div>
        <script type="text/javascript">
        	var v;
        	jQuery(document).ready(function($){
	        	$('#date_wrap, #conditional_wrap').hide();
	        	$('form').on('change','select#gf_remove_type',function(){
					v = $(this).val();
					if( v == 'date' ){
						$('#date_wrap').slideDown();
						$('#conditional_wrap').hide();
					} else if( v == 'conditional' ) {
						$('#conditional_wrap').slideDown();
						$('#date_wrap').hide();
					} else {
						$('#date_wrap, #conditional_wrap').hide();
					}
	        	});
        	});
        </script>
        <?php
    }

	//Adds feed tooltips to the list of tooltips
	public static function tooltips($tooltips){
		$remove_tooltips = array(
			"remove_contact_list" => "<h6>" . __("remove Survey", "gravity-forms-remove") . "</h6>" . __("Select the remove survey you would like to add your contacts to.", "gravity-forms-remove"),
			"remove_gravity_form" => "<h6>" . __("Gravity Form", "gravity-forms-remove") . "</h6>" . __("Select the Gravity Form you would like to integrate with remove. Contacts generated by this form will be automatically added to your remove constituents account.", "gravity-forms-remove"),
			"remove_gravity_begin" => "<h6>" . __("Beginning Date", "gravity-forms-remove") . "</h6>" . __("Remove any entries after this date."),
			"remove_gravity_end" => "<h6>" . __("Ending Date", "gravity-forms-remove") . "</h6>" . __("Remove any entries up until this date", "gravity-forms-remove"),
			"remove_gravity_type" => "<h6>" . __("Type of Removal", "gravity-forms-remove") . "</h6>" . __("Choose to remove all entries for a form, or a subset based on dates.", "gravity-forms-remove"),
		);
		return array_merge($tooltips, $remove_tooltips);
	}

    //Creates Remove Entries left nav menu under Forms
    public static function create_menu($menus)
    {
	    // Adding submenu if user has access
        $permission = self::has_access("gravityforms_remove");
        if(!empty($permission))
            $menus[] = array("name" => "gf_remove", "label" => __("Remove Entries", "gravity-forms-remove-entries"), "callback" =>  array("GFRemove", "remove_page"), "permission" => $permission);

        return $menus;
    }

    // Remove all entries for a given form (set status as trash)
    private static function remove_all_entries($form)
    {
		global $wpdb;
		$form_table_name = version_compare( RGFormsModel::get_database_version(), '2.3-dev-1', '<' ) ? RGFormsModel::get_lead_table_name() : RGFormsModel::get_entry_table_name();

	    $data = array('status' => 'trash');
	    $where = array('form_id' => $form);

		$update = $wpdb->update($form_table_name, $data, $where, '%s', '%d');

		return $update;
    }

    private static function remove_entries_by_date($form, $begin, $end)
    {
		global $wpdb;
		$form_table_name = version_compare( RGFormsModel::get_database_version(), '2.3-dev-1', '<' ) ? RGFormsModel::get_lead_table_name() : RGFormsModel::get_entry_table_name();

	    $sql = "UPDATE $form_table_name SET status = 'trash' WHERE form_id = '$form' AND date_created > '$begin' AND date_created < '$end'";
	    return $wpdb->query($sql);
    }

	private static function is_remove_page(){
		$current_page = trim(strtolower(rgget("page")));
		$remove_pages = array("gf_remove");

		return in_array($current_page, $remove_pages);
	}

	private static function date_make_pretty($pre='')
	{
		$jj = zeroise(absint($_POST[$pre.'jj']), 2);
		$mm = zeroise(absint($_POST[$pre.'mm']), 2);
		$aa = zeroise(absint($_POST[$pre.'aa']), 2);
		$hh = zeroise(absint($_POST[$pre.'hh']), 2);
		$mn = zeroise(absint($_POST[$pre.'mn']), 2);
		$ss = zeroise(absint($_POST[$pre.'ss']), 2);

		// date_created 2013-02-04 08:19:33
		return sprintf("%s-%s-%s %s:%s:%s", $aa, $mm, $jj, $hh, $mn, $ss);

	}
    private static function time_selection_display($pre = '')
    {
    	global $wp_locale;

		$time_adj = current_time('timestamp');

		$jj = gmdate( 'd', $time_adj );
		$mm = gmdate( 'm', $time_adj );
		$aa = gmdate( 'Y', $time_adj );
		$hh = gmdate( 'H', $time_adj );
		$mn = gmdate( 'i', $time_adj );
		$ss = gmdate( 's', $time_adj );

		$month = '<select id="'.$pre.'mm" name="'.$pre.'mm" >'."\n";
		for ( $i = 1; $i < 13; $i = $i +1 ) {
			$monthnum = zeroise($i, 2);
			$month .= "\t\t\t".'<option value="'.$monthnum.'"';
			if ( $i == $mm )
				$month .= ' selected="selected"';
			/* translators: 1: month number (01, 02, etc.), 2: month abbreviation */
			$month .= '>'.sprintf( __( '%1$s-%2$s' ), $monthnum, $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) ) )."</option>\n";
		}
		$month .= '</select>';

		$day = '<input type="text" id="'.$pre.'jj" name="'.$pre.'jj" value="'.$jj.'" size="2" maxlength="2" autocomplete="off" />';
		$year = '<input type="text" id="'.$pre.'aa" name="'.$pre.'aa" value="'.$aa.'" size="4" maxlength="4" autocomplete="off" />';
		$hour = '<input type="text" id="'.$pre.'hh" name="'.$pre.'hh" value="'.$hh.'" size="2" maxlength="2" autocomplete="off" />';
		$minute = '<input type="text" id="'.$pre.'mn" name="'.$pre.'mn" value="'.$mn.'" size="2" maxlength="2" autocomplete="off" />';
		$second = '<input type="text" id="'.$pre.'ss" name="'.$pre.'ss" value="'.$ss.'" size="2" maxlength="2" autocomplete="off" />';

		$output = '<div class="gf-timestamp-wrap">';
		$output .= printf(__('%1$s%2$s, %3$s @ %4$s : %5$s : %6$s'), $month, $day, $year, $hour, $minute, $second);
		$output .='</div>';

		return $output;
    }

	private static function is_gravityforms_installed(){
		return class_exists("RGForms");
	}

	private static function is_gravityforms_supported(){
		if(class_exists("GFCommon")){
		    $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
		    return $is_correct_version;
		}
		else{
		    return false;
		}
	}

	protected static function has_access($required_permission){
		$has_members_plugin = function_exists('members_get_capabilities');
		$has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
		if($has_access)
		    return $has_members_plugin ? $required_permission : "level_7";
		else
		    return false;
	}

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_remove");
        $wp_roles->add_cap("administrator", "gravityforms_remove_uninstall");
    }

	//Returns the url of the plugin's root folder
	protected function get_base_url(){
		return plugins_url(null, __FILE__);
	}

	//Returns the physical path of the plugin's root folder
	protected function get_base_path(){
		$folder = basename(dirname(__FILE__));
		return WP_PLUGIN_DIR . "/" . $folder;
	}

	function set_logging_supported($plugins)
	{
		$plugins[self::$slug] = "Remove Entries";
		return $plugins;
	}

	private static function log_error($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
		}
	}

	private static function log_debug($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
		}
	}
}
