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


// Globals
global $wpdocgen_db_version;
global $wpdocgen_files;
global $wpdocgen_sections;
global $wpdocgen_section_meta;
global $wpdb;
global $wpdocgen_theme;

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
	add_option("wpdocgen_theme", "na");
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
		$section_title = trim(substr($section_title, strlen($section_number)));
		if (explode(".", $section_number)[1] != "0")
			$section_parent = explode(".", $section_number)[0].".0";
		$wpdb->insert($wpdocgen_sections, array("name"=> $section_title, "description" => $section_description, "file_id"=>$file_id));
		$section_id = $wpdb->insert_id;
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
	$docblock["description"] = wpdocgen_analyze_description($header[0]);
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
		$name = $basename;
		$docblock = array();
		$z = 1;
		$docblock["description"] = wpdocgen_analyze_description($comment);
		preg_match_all("/ @[a-z]* .*/", $comment, $meta);
		foreach ($meta[0] as $data) {
			$docblock[$z] = wpdocgen_analyze_docblock($data);
			$z++;
		}
		// Save it to database
		$wpdb->insert($wpdocgen_sections, array("name"=> $name, "description" =>$docblock["description"]["short"], "file_id"=>$file_id));
		$section_id = $wpdb->insert_id;
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
 * Analyzes a JavaScript file and stores information in database.
 *
 * @param  string $file The file to be analyzes (full path).
 * @return void
 */
function wpdocgen_analyze_js($file) {
	global $wpdb;
	global $wpdocgen_files;
	global $wpdocgen_sections;
	global $wpdocgen_section_meta;

	// Gather all php comments and functions
	$contents = file_get_contents($file);
	preg_match("/\/\*\*.*?\*\//s", $contents, $header);
	preg_match_all("/\/\*\*(?:(?!\/\*\*).)*?\*\/(\r)?(\n)?(\s)?function\s[^\s\(]+\([^{]*\)?/s", $contents, $comments);

	// Analyze file data
	$docblock = array();
	$i = 1;
	$docblock["description"] = wpdocgen_analyze_description($header[0]);
	preg_match_all("/ @[a-z]* .*/", $header[0], $meta);
	foreach ($meta[0] as $data) {
		$docblock[$i] = wpdocgen_analyze_file_header($data);
		$i++;
	}

	// Insert file into database
	$wpdb->insert($wpdocgen_files, array("name" => basename($file), "description" => $docblock["description"]["short"], "type" => "js"));
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
		$name = $basename;
		$docblock = array();
		$z = 1;
		$docblock["description"] = wpdocgen_analyze_description($comment);
		preg_match_all("/ @[a-z]* .*/", $comment, $meta);
		foreach ($meta[0] as $data) {
			$docblock[$z] = wpdocgen_analyze_docblock($data);
			$z++;
		}
		// Save it to database
		$wpdb->insert($wpdocgen_sections, array("name"=> $name, "description" =>$docblock["description"]["short"], "file_id"=>$file_id));
		$section_id = $wpdb->insert_id;
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
function wpdocgen_analyze_description($comment) {
	$description = preg_replace("/\*/", "", substr($comment, 3, strpos($comment, "@")-3));
	$description = trim(str_replace("\n", "", $description));
	$description = trim(str_replace("\r", "", $description));
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
 * Sends a HTML response by default. Can also send a JSON response if GET 'format' is set to 'json'.
 * Can be accessed either through main url or through an ajax call.
 * 
 * @return void
 */
function wpdocgen_get_toc(){
	global $wpdb;
	global $wpdocgen_files;
	global $wpdocgen_sections;
	global $wpdocgen_section_meta;
	global $wpdocgen_theme;

	$toc = array();
	$files = $wpdb->get_results("SELECT * FROM $wpdocgen_files");
	$sections = $wpdb->get_results("SELECT * FROM $wpdocgen_sections");
	$subsections = $wpdb->get_results("SELECT * FROM $wpdocgen_section_meta INNER JOIN $wpdocgen_sections ON $wpdocgen_section_meta.for_id = $wpdocgen_sections.id WHERE meta_key = 'section_parent'");

	$toc["title"] = $wpdocgen_theme->get("Name");
	$toc["version"] = $wpdocgen_theme->get("Version");
	$toc["author"] = $wpdocgen_theme->get("Author");
	$toc["authoruri"] = $wpdocgen_theme->get('AuthorURI');
	$toc["description"] = $wpdocgen_theme->get("Description");
	$toc["template"] = "toc";

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
	if ($_GET['format'] == "json") {
		header('Content-type: application/json');
		echo json_encode($toc);
		die();
	}
	else {
		$url = $_SERVER['REQUEST_URI'];
		$response = '<h2 id="wpdocgen-page-name">Table of Contents</h2><ul id="wpdocgen-toc">';
		$css = '<li id="wpdocgen-toc-css"><h3>CSS</h3><ul>';
		$php = '<li id="wpdocgen-toc-php"><h3>PHP</h3><ul>';
		$js = '<li id="wpdocgen-toc-js"><h3>JS</h3><ul>';
		foreach ($toc as $file_id => $file) {
			if (isset($file["name"])) {
				$file_li = '<li><a href="'.$url.'&file='.$file_id.'" ><h3>'.$file['name'].'</h3></a><ul>';
				foreach ($file as $section_id => $section) {
					if (isset($section['name'])) {
						$file_li .= '<li><a href="'.$url.'&section='.$section_id.'" ><h4>'.$section['name'].'</h4></a></li>';
					}
				}
				$file_li .= '</ul></li>';
				switch ($file['type']) {
					case "css":
						$css .= $file_li; 
						break;
					case "php":
						$php .= $file_li;
						break;
					case "js":
						$js .= $file_li;
						break;
				}
			}
		}
		$end = '</ul></li>';
		$css .= $end;
		$php .= $end;
		$js .= $end;
		$response .= $css . $php . $js . '</ul>';
		?>
			<script type="text/javascript">
				var wpdocgen_pagename = "Table of Contents";
			</script>
			<div id="wpdocgen-header">
				<h1><a href="<?php echo $url; ?>"><?php echo $toc["title"]; ?></a></h1>
				<p class="wpdocgen-meta">Version: <?php echo $toc["version"]; ?></p>
				<p class="wpdocgen-meta">Author: <a href="<?php echo $toc['authoruri']; ?>" target="_blank"><?php echo $toc["author"]; ?></a></p>
				<p class="wpdocgen-meta"><?php echo $toc["description"]; ?></p>
			</div>
			<div id="wpdocgen-content">
		<?php
		echo $response;
	}
}

/**
 * Retrieves file information from the database.
 *
 * Sends a HTML response by default. Can also send a JSON response if GET 'format' is set to 'json'.
 * Can be accessed either through main url or through an ajax call. Uses GET parameters 'format' and 'file' (the file's id).
 * 
 * @return void
 */
function wpdocgen_get_file() {
	global $wpdb;
	global $wpdocgen_files;
	global $wpdocgen_sections;
	global $wpdocgen_section_meta;
	global $wpdocgen_theme;

	// Grab file and sections
	$file_id = intval($_GET['file']);
	$file = $wpdb->get_row("SELECT * FROM $wpdocgen_files WHERE id = $file_id");
	$sections = $wpdb->get_results("SELECT * FROM $wpdocgen_sections WHERE file_id = $file_id");
	$file_meta = $wpdb->get_results("SELECT * FROM $wpdocgen_section_meta WHERE type='file' AND for_id=".$file->id);
	$url = $_SERVER['REQUEST_URI'];
	$url = preg_replace("/&file=[0-9]*/", "", $url);
	$url = preg_replace("/&section=[0-9]*/", "", $url);

	// Create array to hold response
	$file_array = array();
	$file_array["name"] = $file->name;
	$file_array["description"] = $file->description;
	$file_array["type"] = $file->type;
	$file_array["template"] = "file";
	$file_array["theme"] = $wpdocgen_theme->get("Name");
	$file_array["themelink"] = $url;


	// Put file meta in the array
	foreach ($file_meta as $fm) {
		$file_array[$fm->meta_key] = $fm->value;	
	}

	foreach($sections as $section) {
		$file_array[$section->id]["name"] = $section->name;
		$file_array[$section->id]["description"] = $section->description;
	}
	if ($_GET['format'] == 'json') {
		header('Content-type: application/json');
		echo json_encode($file_array);
		die();
	}
	else {
		$result = 	"";
		$table .= "<table><tr><th>Section</th><th>Description</th></tr>";
		$sections = "";
		$count = 0;
		foreach ($file_array as $section_id => $section) {
			if (isset($section["name"])) {
				$table_row = "<tr>";
				$name ="<td><a href='".$url."&section=$section_id' >".$section["name"]."</a></td>";;
				$table_row .= $name . "<td>" . $section["description"] . "</td></tr>";
				$table .= $table_row;
				$count++;
			}
		}
		$table .= "</table>";
		if ($count > 0) {
			$result .= $table;
		}
		?>
			<div id="wpdocgen-header">
				<p class="wpdocgen-breadcrumbs"><a href="<?php echo $file_array['themelink']; ?>" ><?php echo $file_array['theme']; ?></a> &#62 <?php echo $file_array['name']; ?></p>
				<h1><?php echo $file_array["name"]; ?></h1>
				<p class="wpdocgen-meta"><?php echo $file_array["description"].'</p><p>'.$file_array["long_description"]; ?></p>
			</div>
			<div id="wpdocgen-content">
		<?php
		echo $result;
	}
	
}

/**
 * Retrieves section information from the database.
 *
 * Sends a HTML response by default. Can also send a JSON response if GET 'format' is set to 'json'.
 * Can be accessed either through main url or through an ajax call. Uses GET parameters 'format' and 'section' (the section's id).
 *
 * @return void
 */
function wpdocgen_get_section() {
	global $wpdb;
	global $wpdocgen_files;
	global $wpdocgen_sections;
	global $wpdocgen_section_meta;
	global $wpdocgen_theme;

	$section_id = intval($_GET['section']);
	$section = $wpdb->get_row("SELECT * FROM $wpdocgen_sections WHERE id = $section_id");
	$meta = $wpdb->get_results("SELECT * FROM $wpdocgen_section_meta WHERE type='section' AND for_id = $section_id");
	$file = $wpdb->get_row("SELECT * FROM $wpdocgen_files WHERE id=".$section->file_id);
	$url = $_SERVER['REQUEST_URI'];
	$url = preg_replace("/&file=[0-9]*/", "", $url);
	$url = preg_replace("/&section=[0-9]*/", "", $url);

	$section_array = array();
	$section_array['name'] = $section->name;
	$section_array['type'] = $file->type;
	$section_array['file'] = $file->name;
	$section_array['file_id'] = $file->id;
	$section_array['description'] = $section->description;
	$section_array["template"] = "section";
	$section_array["theme"] = $wpdocgen_theme->get("Name");
	$section_array["themelink"] = $url;

	foreach ($meta as $m) {
		$section_array[$m->id]["key"] = $m->meta_key;
		$section_array[$m->id]["value"] = $m->value;
		$meta_meta = $wpdb->get_results("SELECT * FROM $wpdocgen_section_meta WHERE type='meta' AND for_id=".intval($m->id));
		foreach ($meta_meta as $mm) {
			$section_array[$m->id][$mm->meta_key] = $mm->value;			
		}
	}

	if ($_GET['format'] == "json") {
		header('Content-type: application/json');
		echo json_encode($section_array);
		die();
	}
	else {
		$result = "";
		$sourcecode = "<h2>Source Code</h2><p>Located in <a href='$url&file=".$section_array['file_id']."' >".$section_array['file'].".</a></p>";
		if ($section_array['type'] == 'php') {
			$profile = "<h2>Profile</h2>";
			$params = "<h2>Parameters</h2>";
			$paramcount = 0;
			$returns = "<h2>Return Value</h2>";
			$returnscount = 0;
			$uses = "<h2>Referenced Functions</h2>";
			$usescount = 0;
			$changelog = "<h2>Change Log</h2>";
			foreach ($section_array as $meta_id => $meta) {
				if (isset($meta['key'])) {
					if ($meta['key'] == 'param') {
						$params .= "<h4>".$meta["value"]."</h4>";
						$params .= "<p>(".$meta["type"].") ";
						if (isset($meta["description"])) {
							$params .= $meta["description"];
							$paramscount++;
						}
						$params .="</p>";
					}
					elseif ($meta['key'] == 'return') {
						$returns .= "<p>(".$meta["value"].") ";
						if (isset($meta["description"])) {
							$returns .= $meta["description"];
						}
						$returns .="</p>";
						$returnscount++;
					}
					elseif ($meta['key'] == "uses") {
						$uses .= "<h4>".$meta["value"]."</h4>";
						if (isset($meta["description"])) {
							$uses .= "<p>".$meta["description"]."</p>";
							$usescount++;
						}
					}
					elseif ($meta['key'] == "since") {
						$changelog .= "Since ".$meta["value"];
					}
					elseif ($meta['key'] == "profile") {
						$profile .= "<pre>function ".$meta["value"]. "</pre>";
					}
				}
			}
			$result .= $profile;
			if ($paramscount > 0) {
				$result .= $params;
			}
			$result .=  $returns;
			if ($returnscount == 0) {
				$result .= "<p>(void)</p>";
			}
			if ($usescount >0) {
				$result .= $uses;
			}
			$result .= $changelog . $sourcecode;
		}
		else {
			$result .= $sourcecode;
		} ?>
			<div id="wpdocgen-header">
				<p class="wpdocgen-breadcrumbs"><a href="<?php echo $section_array['themelink']; ?>" ><?php echo $section_array['theme']; ?></a> &#62 <a href="<?php echo $section_array['themelink'].'&file='.$section_array['file_id']; ?>" ><?php echo $section_array['file']; ?></a> &#62 <?php echo $section_array['name']; ?></p>
				<h1><?php echo $section_array["name"]; ?></h1>
				<p class="wpdocgen-meta"><?php echo $section_array["description"].'</p><p>'.$section_array["long_description"]; ?></p>
			</div>
			<div id="wpdocgen-content">
		<?php
		echo $result;
	}

}

/**
 * Recursive function for retrieving all files in all subdirectories.
 * 
 * @param string $dir    Top level directory.
 * @param string $prefix Directory prefix (Defaults to empty string).
 */
function ListIn($dir, $prefix = '') {
  $dir = rtrim($dir, '\\/');
  $result = array();

    foreach (scandir($dir) as $f) {
      if ($f !== '.' and $f !== '..') {
        if (is_dir("$dir/$f")) {
          $result = array_merge($result, ListIn("$dir/$f", "$prefix$f/"));
        } else {
          $result[] = $prefix.$f;
        }
      }
    }

  return $result;
}

/**
 * Analyzes entire theme and saves information in database. Generated on first load of WPDocGen page (where the shortcode is used).
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
	$files = ListIn($directory);
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
		if (strpos($file, ".js")) {
			$js[] = $file;
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

	// Analyze all PHP files
	foreach ($js as $file) {
		wpdocgen_analyze_js($directory.$file);
	}

}

/**
 * Echos the table of contents page for WP Doc Gen. 
 *
 * Create this page by using the shortcode [wpdocgen].
 * 
 * @return void
 */
function wpdocgen() {
	global $wpdocgen_theme;
	$wpdocgen_theme = wp_get_theme();
	if (get_option("wpdocgen_theme") != $wpdocgen_theme->get("Name")) {
		wpdocgen_analyze_theme();
		update_option("wpdocgen_theme", $wpdocgen_theme->get("Name"));
	}

	global $wpdocgen_add_script;
	$wpdocgen_add_script = true;
	$url = $_SERVER['REQUEST_URI'];
	$url = preg_replace("/&file=[0-9]*/", "", $url);
	$url = preg_replace("/&section=[0-9]*/", "", $url);
	
	
	if ($_GET['format'] != 'json') { ?>
		<script type="text/javascript">
			var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
			var wpdocgen_base_url = '<?php echo $url; ?>';
		</script>
	<?php }
	if (!isset($_GET['file']) && !isset($_GET['section'])) { 
		wpdocgen_get_toc();
	} elseif (isset($_GET['file'])) { 
		wpdocgen_get_file();
	} else {
		wpdocgen_get_section();
	}
	?>
	</div>
	<?php
}
add_shortcode( 'wpdocgen', 'wpdocgen' );
add_action("wp_ajax_wpdocgen", "wpdocgen");
add_action("wp_ajax_nopriv_wpdocgen", "wpdocgen");

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

