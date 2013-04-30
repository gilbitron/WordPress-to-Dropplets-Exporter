<?php
/*
Plugin Name: WordPress to Dropplets Exporter
Description: Exports WordPress posts to Markdown files for http://dropplets.com
Version: 1.0
Author: Gilbert Pellegrom
Author URI: http://gilbert.pellegrom.me
License: GPLv3 or Later

Based on https://github.com/benbalter/wordpress-to-jekyll-exporter
*/

class WP_To_Dropplets_Export {

	private $zip_folder = 'wp-to-dropplets-export/'; //folder zip file extracts to
	private $twitter_handle;

	/**
	 * Hook into WP Core
	 */
	function __construct() {
		$this->twitter_handle = '';
		add_action( 'admin_menu', array( &$this, 'register_menu' ) );
		add_action( 'current_screen', array( &$this, 'callback' ) );
	}

	/**
	 * Listens for page callback, intercepts and runs export
	 */
	function callback() {
		if ( !isset( $_GET['page'] ) || $_GET['page'] != 'wp-to-dropplets-export' )
			 return;
	
		if ( !current_user_can( 'manage_options' ) )
			 return;
			 
		if ( empty($_POST) )
			return;
	
		if(isset($_POST['wtde_twitter_handle'])) $this->twitter_handle = strip_tags($_POST['wtde_twitter_handle']);
		$this->export();
		exit();
	}


	/**
	 * Add menu option to tools list
	 */
	function register_menu() {
		add_management_page( __( 'Export to Dropplets', 'wp-to-dropplets-export' ), __( 'Export to Dropplets', 'wp-to-dropplets-export' ), 'manage_options', 'wp-to-dropplets-export', array( &$this, 'page' ) );
	}
	
	function page()
	{
		if(!empty($_POST)){
			$this->twitter_handle = strip_tags($_POST['twitter_handle']);
			$this->export();
		}
		
		echo '<div class="wrap"><div id="icon-tools" class="icon32"></div>
		<h2>WordPress to Dropplets Exporter</h2></div>
		<br /><form method="post" action=""><p><label>Twitter Handle:</label> <input type="text" name="wtde_twitter_handle" /></p>
		<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Download Export File"></p></form>';
	}


	/**
	 * Get an array of all post and page IDs
	 * Note: We don't use core's get_posts as it doesn't scale as well on large sites
	 */
	function get_posts() {
		global $wpdb;
		return $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = 'post'" );
	}


	/**
	 * Convert a posts meta data (both post_meta and the fields in wp_posts) to key value pairs for export
	 */
	function convert_meta( $post ) {
		$categories = get_the_category( $post->ID );
		$output = array(
			 'title'		=> get_the_title( $post->ID ),
			 'author'		=> get_userdata( $post->post_author )->display_name,
			 'twitter'  	=> $this->twitter_handle,
			 'date'			=> get_the_time( 'Y/m/d', $post ),
			 'category' 	=> isset($categories[0]) ? $categories[0]->cat_name : '',
			 'status'		=> 'published'
		);
	
		return $output;
	}

	/**
	 * Convert the main post content to Markdown.
	 */
	function convert_content( $post ) {
		$content = apply_filters( 'the_content', $post->post_content );

		require_once plugin_dir_path(__FILE__) .'markdownify_extra.php';
		$md = new Markdownify_Extra;
		return $md->parseString($content);
	}

	/**
	 * Loop through and convert all posts to MD files with YAML headers
	 */
	function convert_posts() {
		global $post;
	
		foreach ( $this->get_posts() as $postID ) {
			 $post = get_post( $postID );
			 setup_postdata( $post );
	
			 $meta = $this->convert_meta( $post );
	
			 $output = '';
			 foreach($meta as $key=>$val){
			 	 if($key == 'title') $output .= '# '. $val ."\n";
				 else $output .= '- '. $val ."\n";
			 }
	
			 $output .= "\n";
			 $output .= $this->convert_content( $post );
			 $this->write( $output, $post );
		}
	}

	function filesystem_method_filter() {
		return 'direct';
	}

	/**
	 * Main function, bootstraps, converts, and cleans up
	 */
	function export() {
		global $wp_filesystem;
	
		define( 'DOING_DROPPLETS_EXPORT', true );
	
		add_filter( 'filesystem_method', array( &$this, 'filesystem_method_filter' ) );
	
		WP_Filesystem();
	
		$temp_dir = get_temp_dir();
		$this->dir = $temp_dir . 'wp-dropplets-' . md5( time() ) . '/';
		$this->zip = $temp_dir . 'wp-dropplets.zip';
		$wp_filesystem->mkdir( $this->dir );
		$wp_filesystem->mkdir( $this->dir . 'posts/' );
	
		$this->convert_posts();
		$this->zip();
		$this->send();
		$this->cleanup();
	}


	/**
	 * Write file to temp dir
	 */
	function write( $output, $post ) {
		global $wp_filesystem;
	
		$filename = 'posts/'. $post->post_name .'.md';
		$wp_filesystem->put_contents( $this->dir . $filename, $output );
	}


	/**
	 * Zip temp dir
	 */
	function zip() {
		//create zip
		$zip = new ZipArchive();
		$zip->open( $this->zip, ZIPARCHIVE::CREATE );
		$this->_zip( $this->dir, $zip );
		$zip->close();
	}


	/**
	 * Helper function to add a file to the zip
	 */
	function _zip( $dir, &$zip ) {
		//loop through all files in directory
		foreach ( glob( trailingslashit( $dir ) . '*' ) as $path ) {
			 if ( is_dir( $path ) ) {
			 	$this->_zip( $path, $zip );
			 	continue;
			 }
	
			 //make path within zip relative to zip base, not server root
			 $local_path = '/' . str_replace( $this->dir, $this->zip_folder, $path );
	
			 //add file
			 $zip->addFile( realpath( $path ), $local_path );
		}
	}


	/**
	 * Send headers and zip file to user
	 */
	function send() {
		//send headers
		@header( 'Content-Type: application/zip' );
		@header( "Content-Disposition: attachment; filename=wp-dropplets.zip" );
		@header( 'Content-Length: ' . filesize( $this->zip ) );
	
		//read file
		readfile( $this->zip );
	}


	/**
	 * Clear temp files
	 */
	function cleanup( ) {
		global $wp_filesystem;
	
		$wp_filesystem->delete( $this->dir, true );
		$wp_filesystem->delete( $this->zip );
	}

}
$wtde = new WP_To_Dropplets_Export();