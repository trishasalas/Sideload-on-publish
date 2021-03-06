<?php

/* Plugin Name: Sideload Images On publish
 * Description: Sideloads external images from whitelist on publish
 * Author: Mattheu
 * Author URI: http://matth.eu
 * Contributors: 
 * Version: 0.1
 */

if ( defined('WP_CLI') && WP_CLI )
	require __DIR__ . '/sideload-images-on-publish-cli.php';

$sideload_images = new HM_Sideload_Images();

class HM_Sideload_Images {

	public $domain_whitelist = array(
		'https://dl.dropboxusercontent.com', // DropBox
		'http://cl.ly/image/463Y120M3O1R',   // CloudApp
		'http://www.evernote.com',           // Evernote / Skitch
		'https://www.evernote.com',          // Evernote / Skitch
		'https://skydrive.live.com',         // Skydrive
		'http://sdrv.ms',                    // Skydrive
		'http://i.imgur.com',                // Imgur
		'https://raw.github.com/'
	);
	
	function __construct() {

		add_action( 'save_post', array( $this, 'check_post_content' ), 100 );

		add_action( 'wp_insert_comment', array( $this, 'check_comment_content' ), 100 );
		add_action( 'edit_comment', array( $this, 'check_comment_content' ), 100 );

	}

	/**
	 * Method for getting domain whitelist.
	 *
	 * Always use this rather than accessing the property directly.
	 * 
	 * @return array $domain_whitelist.
	 */
	public function get_whitelist() {
		return apply_filters( 'hm_sideload_images', $this->domain_whitelist );
	}

	/**
	 * Check post content, If new content, update
	 * 
	 * @param int $post_id
	 * @return null
	 */
	public function check_post_content ( $post_id ) {
		
		global $wpdb;
		
		$post = get_post( $post_id );		

		if ( ! $post )
			return;

		$new_content = $post->post_content;

		$new_content = $this->check_content_for_img_markdown( $new_content, $post_id );
		$new_content = $this->check_content_for_img_html( $new_content, $post_id );

		if ( $new_content !== $post->post_content ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $new_content ), array( 'ID' => $post->ID ) );
			clean_post_cache( $post->ID );
		}

	}

	/**
	 * Check comment content.
	 * 
	 * @param int $post_id
	 * @return null
	 */
	public function check_comment_content ( $comment_id ) {
		
		global $wpdb;
		
		$comment = get_comment( $comment_id );

		if ( ! $comment )
			return;

		$new_content = $comment->comment_content;
		$new_content = $this->check_content_for_img_markdown( $new_content, $comment->comment_post_ID );
		$new_content = $this->check_content_for_img_html( $new_content, $comment->comment_post_ID );
	
		if ( $new_content !== $comment->comment_content ) {
			$wpdb->update( $wpdb->comments, array( 'comment_content' => $new_content ), array( 'comment_ID' => $comment->comment_ID ) );
			clean_comment_cache( $comment->comment_ID );
		}

	}

	/**
	 * Check content and sideload external img elements with srcs from whitelisted domains.
	 *
	 * @param  string $content Old Content
	 * @return string $content New Content
	 */
	public function check_content_for_img_markdown ( $content, $post_id = null ) {	

		preg_match_all( '/!\[.*?\]\((\S*?)\)/', $content, $matches );

		if ( empty( $matches[1] ) )
			return $content;

		for ( $i = 0; $i < count( $matches[0] ); $i++ ) {

			$src = $matches[1][$i];
			$new_attachment = $this->sideload_image( $src, $post_id );

			if ( 0 === strpos( $src, home_url() ) || ! $this->check_domain_whitelist( $src ) )
				continue;

			if ( ! $new_src = wp_get_attachment_image_src( $new_attachment, 'full' ) )
				continue;
			
			$markdown = str_replace( $src, $new_src[0], $matches[0][$i] );
			$content = str_replace( $matches[0][$i], $markdown, $content );
			
		}

		return $content;

	}

	/**
	 * Check content and sideload external img elements with srcs from whitelisted domains.
	 *
	 * @param  string $content Old Content
	 * @return string $content New Content
	 */
	public function check_content_for_img_html ( $content, $post_id = null ) {

		$dom = new DOMDocument();
		// loadXml needs properly formatted documents, so it's better to use loadHtml, but it needs a hack to properly handle UTF-8 encoding
		@$dom->loadHTML( sprintf( 
			'<html><head><meta http-equiv="Content-Type" content="text/html; charset="UTF-8" /></head><body>%s</body></html>',
			$content
		) );

		$update = false;

		foreach ( $dom->getElementsByTagName( 'img' ) as $image ) {

			$src = $image->getAttribute( 'src' );
			
			if ( 0 === strpos( $src, home_url() ) || ! $this->check_domain_whitelist( $src ) )
				continue;

			$new_attachment = $this->sideload_image( $src, $post_id );

			$width  = $image->getAttribute( 'width' );
			$height = $image->getAttribute( 'height' );
			
			if ( $width && $height )
				$size = array( intval( $width ), intval( $height ) );
			else
				$size = 'full';

			// If WPThumb is active, crop the image to the correct dimensions.
			if ( class_exists( 'WP_Thumb' ) && is_array( $size ) )
				$size['crop'] = true;

			$new_src = wp_get_attachment_image_src( $new_attachment, $size );

			if ( isset( $new_src[0] ) ) {

				$image->setAttribute ( 'src' , $new_src[0] );
				$image->setAttribute ( 'width' , $new_src[1] );
				$image->setAttribute ( 'height' , $new_src[2] );
				$update = true;
			
			}

		}

		if ( ! $update )
			return $content;

		$new_content = '';

		// This seems a mega hacky way of oututting the body innerHTML
    	$children = $dom->getElementsByTagName('body')->item(0)->childNodes; 

    	foreach ( $children as $node )
    		$new_content .= $dom->saveXML($node) . "\n";

    	$new_content = trim( $new_content );
    	
		return $new_content;

	}

	/**
	 * Check image srs against domain whitelist
	 * 
	 * @param  string $src
	 * @return bool
	 */
	public function check_domain_whitelist( $src ) {

		$whitelist = $this->get_whitelist();

		foreach ( (array) $whitelist as  $domain )
			if ( 0 === strpos( $src, $domain ) )
				return true;

		return false;

	}

	/**
	 * Sideload Image.
	 * Return attachment ID.
	 * 
	 * @param  string $src exernal imagei source
	 * @param  int $post_id post ID. Sideloaded image is attached to this post.
	 * @param  string $desc Description of the sideloaded file.
	 * @return int Attachment ID
	 */
	public function sideload_image ( $src, $post_id = null, $desc = null ) {

		require_once(ABSPATH . "wp-admin" . '/includes/image.php');
    	require_once(ABSPATH . "wp-admin" . '/includes/file.php');
	    require_once(ABSPATH . "wp-admin" . '/includes/media.php');

		if ( ! empty( $src ) ) {
			
			// Fix issues with double encoding
			$src = urldecode( $src );

			// Set variables for storage
			// fix src filename for query strings
			preg_match('/[^\?]+\.(jpg|JPG|jpe|JPE|jpeg|JPEG|gif|GIF|png|PNG)/', $src, $matches);
			
			if ( empty( $matches ) )
				return false;

			// Download file to temp location
			$tmp = download_url( $src );

			$file_array = array();
			$file_array['name'] = basename($matches[0]);
			$file_array['tmp_name'] = $tmp;
			
			// If error storing temporarily, unlink
			if ( is_wp_error( $tmp ) ) {
				@unlink($file_array['tmp_name']);
				$file_array['tmp_name'] = '';
				return false;
			}

			// do the validation and storage stuff
			$id = media_handle_sideload( $file_array, $post_id, $desc );

			// If error storing permanently, unlink
			if ( is_wp_error($id) ) {
				@unlink($file_array['tmp_name']);
				return false;
			}

			return $id;

		}

		return false;
	
	}

}
