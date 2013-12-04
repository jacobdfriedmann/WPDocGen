<?php
/**
 * Plugin Name: WPDocGen
 * Plugin URI: http://jacobfriedmann.com/wpdocgen
 * Description: Generates documentation for a WordPress Theme
 * Version: 0.1
 * Author: Jacob Friedmann
 * Author URI: http://JacobFriedmann.com
 * License: GPL2
 */

/*  Copyright 2013  Jacob Friedmann  (email : jacobdfriedmann@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * TODO:
 * 1. Display File page information now that it is getting there.
 * 2. Analyze and send Javascript files.
 * 3. Make Table of Contents better.
 * 4. Try to speed up the content loading. (maybe respond via html and json?)
 * 5. Create breadcrumbs.
 * 6. Create section specific pages?
 */

// Globals
global $wpdocgen_db_version;
global $wpdocgen_files;
global $wpdocgen_sections;
global $wpdocgen_section_meta;
global $wpdb;

$wpdocgen_db_version = "0.1";
$wpdocgen_files = $wpdb->prefix."wpdocgen_files";
$wpdocgen_sections = $wpdb->prefix."wpdocgen_sections";
$wpdocgen_section_meta = $wpdb->prefix."wpdocgen_meta";

/**
 * Installs plugin upon first activation. Creates databases (or updates them if this is an update).
 * @return void
 */
function wpdocgen_install() {
	global $wpdb;
	global $wpdocgen_db_version;

	global $wpdocgen_files;
	global $wpdocgen_sections;
	global $wpdocgen_section_meta;

	
	// Create Databases
	$sql = "CREATE TABLE $wpdocgen_files (
	  id mediumint(9) NOT NULL AUTO_INCREMENT,
	  name tinytext NOT NULL,
	  description text,
	  type tinytext NOT NULL,
	  UNIQUE KEY id (id)
	);
	CREATE TABLE $wpdocgen_sections (
	  id mediumint(9) NOT NULL AUTO_INCREMENT,
	  name tinytext NOT NULL,
	  description text,
	  file_id mediumint(9) NOT NULL,
	  UNIQUE KEY id (id)
	);
	CREATE TABLE $wpdocgen_section_meta (
	  id mediumint(9) NOT NULL AUTO_INCREMENT,
	  meta_key tinytext NOT NULL,
	  value text NOT NULL,
	  type tinytext NOT NULL,
	  for_id mediumint(9) NOT NULL,
	  UNIQUE KEY id (id)
	);";

	// Uses dbDelta to update databases
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );

	add_option( "wpdocgen_db_version", $wpdocgen_db_version );
}
register_activation_hook(__FILE__, 'wpdocgen_install');


/**
 * Analyzes a CSS file and saves information in the database.
 * 
 * @param string $file The file to be analyzed (full path) 
 * @return void
 */	
function wpdocgen_analyze_css($file) {
	global $wpdb;
	global $wpdocgen_files;
	global $wpdocgen_sections;
	global $wpdocgen_section_meta;

	// Save file in database
	$wpdb->insert($wpdocgen_files, array("name" => basename($file), "type" => "css"));
	$file_id = $wpdb->insert_id;

	// Retrieve all CSS comments and remove table of contents
	$contents = file_get_contents($file);
	preg_match_all("/\/\*\*.*?[0-9]+?\.[0-9]+?.*?\*\//s", $contents, $comments);
	$sections = $comments[0];
	unset($sections[0]);
	
	// Parse CSS coments and save them in database
	foreach ($sections as $section) {
		preg_match("/[0-9]+\.[0-9]+/", $section, $section_number);
		preg_match("/[0-9]+\.[0-9]+.*/", $section, $section_title);
		$section_number = $section_number[0];
		$section_title = $section_title[0];
		$section_description = preg_replace("/\*/", "", $section);
		$section_description = preg_replace("/-[-]+/", "", $section_description);
		$section_description = substr($section_description, strlen($section_title) + 4, -1);
		$section_description = trim($section_description);
		$section_title = substr($section_title, strlen($section_number));
		if (explode(".", $section_number)[1] != "0")
			$section_parent = explode(".", $section_number)[0].".0";
		$wpdb->insert($wpdocgen_sections, array("name"=> $section_number. " - ". $section_title, "description" => $section_description, "file_id"=>$file_id));
		$section_id = $wpdb->insert_id;
		$wpdb->insert($wpdocgen_section_meta, array("meta_key"=> "basename", "value" => $section_title, "for_id" => $section_id, "type" => "section"));
		$wpdb->insert($wpdocgen_section_meta, array("meta_key"=> "number", "value" => $section_number, "for_id" => $section_id, "type" => "section"));
		if ($section_parent)
			$wpdb->insert($wpdocgen_section_meta, array("meta_key"=> "section_parent", "value" => $section_parent, "for_id" => $section_id, "type" => "section"));
	}

}

/**
 * Analyzes a PHP file and stores information in the database.
 * 
 * @param  string $file The path to the php file to be analyzed.
 * @return void
 */
function wpdocgen_analyze_php($file) {
	global $wpdb;
	global $wpdocgen_files;
	global $wpdocgen_sections;
	global $wpdocgen_section_meta;

	// Gather all php comments and functions
	$contents = file_get_contents($file);
	preg_match("/\/\*\*.*?\*\//s", $contents, $header);
	preg_match_all("/\/\*\*(?:(?!\/\*\*).)*?\*\/(\r)?(\n)?(\s)?function\s[^\s]+\([^{]*\)?/s", $contents, $comments);

	// Analyze file meta
	$docblock = array();
	$i = 1;
	$docblock["description"] = wpdocgen_get_description($header[0]);
	preg_match_all("/ @[a-z]* .*/", $header[0], $meta);
	foreach ($meta[0] as $data) {
		$docblock[$i] = wpdocgen_analyze_file_header($data);
		$i++;
	}
	
	// Insert file into database
	$wpdb->insert($wpdocgen_files, array("name" => basename($file), "description" => $docblock["description"]["short"], "type" => "php"));
	$file_id = $wpdb->insert_id;
	if ($docblock["description"]["long"] != "")
		$wpdb->insert($wpdocgen_section_meta, array("meta_key"=> "long_description", "value" => $docblock["description"]["long"], "for_id" => $file_id, "type" => "file"));
	reset($docblock);
	foreach ($docblock as $key => $value) {
		if ($key != "description") {
			reset($value);
			$wpdb->insert($wpdocgen_section_meta, array("meta_key"=> key($value), "value" => $value[key($value)]['primary'], "for_id" => $file_id, "type" => "file"));
		}
	}

	for ($i = 0; $i < count($comments[0]); $i++) {
		// Parse information
		preg_match("/\/\*\*.*?\*\//s", $comments[0][$i], $comment);
		$comment = $comment[0];
		$profile = substr($comments[0][$i], strlen($comment) + 10);
		$basename = substr($profile, 0, strrpos($profile, "("));
		$number = ($i+1).".0";
		$name = $number ." - ". $basename;
		$docblock = array();
		$z = 1;
		$docblock["description"] = wpdocgen_get_description($comment);
		preg_match_all("/ @[a-z]* .*/", $comment, $meta);
		foreach ($meta[0] as $data) {
			$docblock[$z] = wpdocgen_analyze_docblock($data);
			$z++;
		}
		// Save it to database
		$wpdb->insert($wpdocgen_sections, array("name"=> $name, "description" =>$docblock["description"]["short"], "file_id"=>$file_id));
		$section_id = $wpdb->insert_id;
		$wpdb->insert($wpdocgen_section_meta, array("meta_key"=> "basename", "value" => $basename, "for_id" => $section_id, "type"=>"section"));
		$wpdb->insert($wpdocgen_section_meta, array("meta_key"=> "number", "value" => $number, "for_id" => $section_id, "type"=>"section"));
		$wpdb->insert($wpdocgen_section_meta, array("meta_key"=> "profile", "value" => $profile, "for_id" => $section_id, "type"=>"section"));
		$wpdb->insert($wpdocgen_section_meta, array("meta_key"=> "long_description", "value" => $docblock["description"]["long"], "section_id" => $section_id));
		foreach ($docblock as $key => $value) {
			if ($key != "description") {
				$wpdb->insert($wpdocgen_section_meta, array("meta_key"=> key($value), "value" => $value[key($value)]['primary'], "for_id" => $section_id, "type" => "section"));
				$meta_id = $wpdb->insert_id;
				foreach ($value[key($value)] as $deep_key => $deep_value) {
					if ($deep_key != "primary" && $deep_value != "")
						$wpdb->insert($wpdocgen_section_meta, array("meta_key"=> $deep_key, "value" => $deep_value, "for_id" => $meta_id, "type" => "meta"));
				}
			}
		}
			
	
	}
	
}

/**
 * Analyzes annotations in the header block of a file.
 * 
 * @param  string $data The header of a file as a string.
 * @return array       An array representing an annotation.
 */
function wpdocgen_analyze_file_header($data) {
	preg_match("/@[a-z]*/", $data, $annotation);
	$annotation = $annotation[0];
	$annotation = substr($annotation, 1);
	$primary = substr($data, strlen($annotation) + 2);
	$primary = trim($primary);
	return array($annotation => array("primary" => $primary));
}

/**
 * Analyzes a comment to find the description.
 * 
 * @param  string $comment A php comment in the form of a string.
 * @return array          An array containing both the short and long description.
 */
function wpdocgen_get_description($comment) {
	$description = trim(preg_replace("/\*/", "", substr($comment, 3, strpos($comment, "@")-3)));
	$short = (strpos($description, ".") ? substr($description, 0, strpos($description, ".")+1) : $description);
	$long = trim(substr($description, strlen($short)));
	return array("short"=>$short, "long"=>$long);
}

/**
 * Analyzes annotations in a documentation comment and stores information in the database.
 * 
 * @param  string $data A line identified as an annotation.
 * @return array       An array containing information about this annotation.
 */
function wpdocgen_analyze_docblock($data) {
	preg_match("/@[a-z]*/", $data, $annotation);
	$annotation = $annotation[0];
	$annotation = substr($annotation, 1);
	if ($annotation == "return" || $annotation == "uses") {
		$primary = substr($data, strlen($annotation) + 3);
		$primary = (strpos($primary, " ") ? substr($primary, 0, strpos($primary, " ")) : $primary);
		$primary = trim($primary);
		$description = substr($data, strlen($primary) + strlen($annotation) + 4);
		return array($annotation => array("primary" => $primary, "description" => $description));
	}
	elseif ($annotation == "param") {
		$type = substr($data, strlen($annotation) + 3);
		$type = (strpos($type, " ") ? substr($type, 0, strpos($type, " ")) : $type);
		$type = trim($type);
		$primary = substr($data, strlen($type) + strlen($annotation) + 4);
		$primary = (strpos($primary, " ") ? substr($primary, 0, strpos($primary, " ")) : $primary);
		$primary = trim($primary);
		$description = substr($data, strlen($type) + strlen($annotation) + strlen($primary) + 5);
		return array($annotation => array("primary" => $primary, "description" => $description, "type" => $type));
	}
	else {
		$primary = substr($data, strlen($annotation) + 2);
		$primary = trim($primary);
		return array($annotation => array("primary" => $primary));
	}
}

/**
 * Function to return the theme documentation table of contents.
 *
 * Sends a JSON object response.
 * 
 * @return void
 */
function wpdocgen_get_toc(){
	global $wpdb;
	global $wpdocgen_files;
	global $wpdocgen_sections;
	global $wpdocgen_section_meta;

	$toc = array();
	$files = $wpdb->get_results("SELECT * FROM $wpdocgen_files");
	$sections = $wpdb->get_results("SELECT * FROM $wpdocgen_sections");
	$subsections = $wpdb->get_results("SELECT * FROM $wpdocgen_section_meta INNER JOIN $wpdocgen_sections ON $wpdocgen_section_meta.for_id = $wpdocgen_sections.id WHERE meta_key = 'section_parent'");

	// Sort files
	foreach ($files as $file) {
		$toc[$file->id]['name'] = $file->name;
		$toc[$file->id]['type'] = $file->type;
		$toc[$file->id]['desc'] = $file->description;
	}
	foreach ($sections as $section) {
		$toc[$section->file_id][$section->id]['name'] = $section->name;
		$toc[$section->file_id][$section->id]['description'] = $section->description;
	}
	foreach ($subsections as $subsection) {
		$toc[$subsection->file_id][$subsection->for_id]['parent'] = $subsection->value;
	}
	header('Content-type: application/json');
	echo json_encode($toc);
	die();
}
add_action("wp_ajax_wpdocgen_get_toc", "wpdocgen_get_toc");

/**
 * Retrieves a file from the database and responds in JSON. 
 * 
 * @return void
 */
function wpdocgen_get_file() {
	global $wpdb;
	global $wpdocgen_files;
	global $wpdocgen_sections;
	global $wpdocgen_section_meta;

	// Grab file and sections
	$file_id = intval($_POST['file']);
	$file = $wpdb->get_row("SELECT * FROM $wpdocgen_files WHERE id = $file_id");
	$sections = $wpdb->get_results("SELECT * FROM $wpdocgen_sections WHERE file_id = $file_id");
	$file_meta = $wpdb->get_results("SELECT * FROM $wpdocgen_section_meta WHERE type='file' AND for_id=".$file->id);

	// Create array to hold response
	$file_array = array();
	$file_array["name"] = $file->name;
	$file_array["description"] = $file->description;

	// Put file meta in the array
	foreach ($file_meta as $fm) {
		$file_array[$fm->meta_key] = $fm->value;	
	}

	foreach($sections as $section) {
		$file_array[$section->id]["name"] = $section->name;
		$file_array[$section->id]["description"] = $section->description;
		$section_meta = $wpdb->get_results("SELECT * FROM $wpdocgen_section_meta WHERE type='section' AND for_id=".intval($section->id));
		foreach ($section_meta as $sm) {
			$file_array[$section->id][$sm->id]["key"] = $sm->meta_key;
			$file_array[$section->id][$sm->id]["value"] = $sm->value;
			$meta_meta = $wpdb->get_results("SELECT * FROM $wpdocgen_section_meta WHERE type='meta' AND for_id=".intval($sm->id));
			foreach ($meta_meta as $mm) {
				$file_array[$section->id][$sm->id][$mm->meta_key] = $mm->value;			
			}
		}
	}

	header('Content-type: application/json');
	echo json_encode($file_array);
	die();
}
add_action("wp_ajax_wpdocgen_get_file", "wpdocgen_get_file");

/**
 * Analyzes entire theme and saves information in database. Generated only when prompted by user.
 * 
 * @param string $directory Directory of the theme (defaults to current theme)
 * @return void
 */
function wpdocgen_analyze_theme($directory = null) {
	global $wpdb;
	global $wpdocgen_files;
	global $wpdocgen_sections;
	global $wpdocgen_section_meta;

	// Clear database and reset auto_increment (wpdb doesn't allow TRUNCATE)
	$wpdb->query("DELETE FROM $wpdocgen_section_meta");
	$wpdb->query("DELETE FROM $wpdocgen_sections");
	$wpdb->query("DELETE FROM $wpdocgen_files");
	$wpdb->query("ALTER TABLE $wpdocgen_section_meta AUTO_INCREMENT=1");
	$wpdb->query("ALTER TABLE $wpdocgen_sections AUTO_INCREMENT=1");
	$wpdb->query("ALTER TABLE $wpdocgen_files AUTO_INCREMENT=1");

	// Set directory to current theme if one is not provided
	if (!$directory) {
		$directory = get_template_directory()."/";
	}
	
	
	
	// Gather all other files and sort them into arrays
	$files = scandir($directory);
	$css = array();
	$php = array();
	$js = array();

	foreach ($files as $file) {
		if (strpos($file, ".css") && $file != "style.css") {
			$css[] = $file;
		}
		if (strpos($file, ".php") && $file != "functions.php") {
			$php[] = $file;
		}
	}

	// Analyze the main stylesheet
	wpdocgen_analyze_css($directory."style.css");

	// Analyze all CSS files
	foreach ($css as $file) {
		wpdocgen_analyze_css($directory.$file);
	}

	// Analyze functions.php
	wpdocgen_analyze_php($directory."functions.php");

	// Analyze all PHP files
	foreach ($php as $file) {
		wpdocgen_analyze_php($directory.$file);
	}


}

/**
 * Adds WPDocGen to the "Tools" admin menu
 * @return void
 */
function wpdocgen_plugin_menu() {
	add_management_page( 'WordPress Documentation Generator', 'WPDocGen', 'manage_options', 'wpdocgen', 'wpdocgen_admin' );
}

/**
 * Creates and processes the WPDocGen admin page
 * @return void
 */
function wpdocgen_admin() {
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
    elseif (isset($_POST['wpdocgen-generate'])) {
        // wpdocgen_analyze_theme();
        wpdocgen_print_toc();
    }
    else {
    	?>
	    <div class="wrap">
	    	<div class="icon32 icon-page"><br></div>
	    	<h2>WP Documentation Generator
                <a class="add-new-h2" id="wpdocgen-generate" href="#">Generate Documentation</a>
            </h2>
		</div>
    	
    <?php
    }
    
}
add_action( 'admin_menu', 'wpdocgen_plugin_menu' );

/**
 * Echos the table of contents page for WP Doc Gen. 
 *
 * Create this page by using the shortcode [wpdocgen].
 * 
 * @return void
 */
function wpdocgen_process_request() {
	global $wpdocgen_add_script;
	$wpdocgen_add_script = true;
	$theme = wp_get_theme();
	$url = $_SERVER['REQUEST_URI'];
	$url = preg_replace("/&file=[0-9]*/", "", $url);
	?>
	<script type="text/javascript">
			var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
			var wpdocgen_base_url = '<?php echo $url; ?>';
	</script>
	<div id="wpdocgen-header">
		<h1><a href="<?php echo $url; ?>"><?php echo $theme->get("Name"); ?></a></h1>
		<p class="wpdocgen-meta">Version: <?php echo $theme->get("Version"); ?></p>
		<p class="wpdocgen-meta">Author: <a href="<?php echo $theme->get('AuthorURI'); ?>" target="_blank"><?php echo $theme->get("Author"); ?></a></p>
		<p class="wpdocgen-meta"><?php echo $theme->get("Description"); ?></p>
	</div>
	<div id="wpdocgen-content">
	<?php if (!isset($_GET['file'])) { ?>

		<script type="text/javascript">
			var wpdocgen_toc = true;
		</script>
		<h2>Table of Contents</h2>
		<ul id="wpdocgen-toc" style="display:none;"\>
	    	<li id="wpdocgen-toc-css">
				<h3>CSS</h3>
	    		<ul id="wpdocgen-toc-css-list" style="display:none;"></ul>
	    	</li>

	    	<li id="wpdocgen-toc-php">
				<h3>PHP</h3>
	    		<ul id="wpdocgen-toc-php-list" style="display:none;"></ul>
	    	</li>

	    	<li id="wpdocgen-toc-js">
				<h3>JavaScript</h3>
	    		<ul id="wpdocgen-toc-js-list" style="display:none;"></ul>
	    	</li>
	    </ul>
		
	<?php
	} else {
		wpdocgen_print_file($_GET['file']);
	}
	?>
	</div>
	<?php
}
add_shortcode( 'wpdocgen', 'wpdocgen_process_request' );

/**
 * Template for a 'file' page. Loads actual data via an ajax call on the front end.
 * @param  int $file The file id.
 * @return void
 */
function wpdocgen_print_file($file) {
	?>
	<script type="text/javascript">
			var wpdocgen_toc = false;
			var wpdocgen_file = <?php echo $file; ?>;
	</script>
	<h2 id="wpdocgen-page-name"></h2>
	<p id="wpdocgen-page-description"></p>
	<?php
}

/**
 * Registers JavaScript file for use on WPDocGen page.
 *
 *  @return void
 */
function wpdocgen_register_script() {
	$url = plugins_url('wpdocgen.js', __FILE__);
	wp_register_script("wpdocgen", $url, array("jquery-ui-core"), '0.1', true);
}
add_action('init', 'wpdocgen_register_script');

/**
 * Prints the plugin javascript into the footer of an admin page.
 * 
 * @return void
 */
function wpdocgen_print_script() {
	global $wpdocgen_add_script;
	if (!$wpdocgen_add_script)
		return;
	wp_print_scripts("wpdocgen");
}
add_action('wp_footer', 'wpdocgen_print_script');

