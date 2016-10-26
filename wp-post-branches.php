<?php
/*
Plugin Name: WP Post Branches
Author: Horike Takahiro
Plugin URI: http://www.kakunin-pl.us
Description: Create branches of posts.
Version: 2.3.3
Author URI: http://www.kakunin-pl.us
Domain Path: /languages
Text Domain: wp_post_branches
*/

if ( ! defined( 'WPBS_DOMAIN' ) )
	define( 'WPBS_DOMAIN', 'wp_post_branches' );
	
if ( ! defined( 'WPBS_PLUGIN_URL' ) )
	define( 'WPBS_PLUGIN_URL', plugins_url() . '/' . dirname( plugin_basename( __FILE__ ) ));

if ( ! defined( 'WPBS_PLUGIN_DIR' ) )
	define( 'WPBS_PLUGIN_DIR', WP_PLUGIN_DIR . '/' . dirname( plugin_basename( __FILE__ ) ));

load_plugin_textdomain( WPBS_DOMAIN, WPBS_DOMAIN.'/languages', dirname( plugin_basename( __FILE__ ) ).'/languages' );

add_action( 'post_submitbox_start', 'wpbs_post_submitbox_start' );
function wpbs_post_submitbox_start() {
	global $post;

	if ( in_array( $post->post_status, array('publish', 'future', 'private') ) && 0 != $post->ID ) {
		echo '<div id="branch-action" style="margin-bottom:5px;">';
		echo '<input type="submit" class="button-primary" name="wp_post_branches" value="' . __( 'Create Branch', WPBS_DOMAIN ) . '" />';
		echo '</div>';
	}
}


add_filter( 'pre_post_update', 'wpbs_pre_post_update' );
function wpbs_pre_post_update( $id ) {

	if ( isset( $_POST['wp_post_branches'] ) ) {
		// post
		$pub = get_post( $id, ARRAY_A );
		unset( $pub['ID'] );
		$pub['post_status'] = 'draft';
		$pub['post_name']   = $pub['post_name'] . '-branch';

		$pub = apply_filters( 'wpbs_pre_publish_to_draft_post', $pub );
		$draft_id = wp_insert_post( $pub );

		// postmeta
		$keys = get_post_custom_keys( $id );
		$custom_field = array();
		foreach ( (array) $keys as $key ) {
			if ( preg_match( '/^_feedback_/', $key ) )
				continue;

			if ( preg_match( '/_wp_old_slug/', $key ) )
				continue;

			$key = apply_filters( 'wpbs_publish_to_draft_postmeta_filter', $key );

			$values = get_post_custom_values($key, $id );
			foreach ( $values as $value ) {
				add_post_meta( $draft_id, $key, $value );
			}
		}

		//attachment
		$args = array( 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => null, 'post_parent' => $id ); 
		$attachments = get_posts( $args );
		if ($attachments) {
			foreach ( $attachments as $attachment ) {
				$new = array(
					'post_author' => $attachment->post_author,
					'post_date' => $attachment->post_date,
					'post_date_gmt' => $attachment->post_date_gmt,
					'post_content' => $attachment->post_content,
					'post_title' => $attachment->post_title,
					'post_excerpt' => $attachment->post_excerpt,
					'post_status' => $attachment->post_status,
					'comment_status' => $attachment->comment_status,
					'ping_status' => $attachment->ping_status,
					'post_password' => $attachment->post_password,
					'post_name' => $attachment->post_name,
					'to_ping' => $attachment->to_ping,
					'pinged' => $attachment->pinged,
					'post_modified' => $attachment->post_modified,
					'post_modified_gmt' => $attachment->post_modified_gmt,
					'post_content_filtered' => $attachment->post_content_filtered,
					'post_parent' => $draft_id,
					'guid' => $attachment->guid,
					'menu_order' => $attachment->menu_order,
					'post_type' => $attachment->post_type,
					'post_mime_type' => $attachment->post_mime_type,
					'comment_count' => $attachment->comment_count
				);
				$new = apply_filters( 'wpbs_pre_publish_to_draft_attachment', $new );
				$attachment_newid = wp_insert_post( $new );
				$keys = get_post_custom_keys( $attachment->ID );

				$custom_field = array();
				foreach ( (array) $keys as $key ) {
					$value = get_post_meta( $attachment->ID, $key, true );

				add_post_meta( $attachment_newid, $key, $value );
				}
			}
		}

		//tax
		$taxonomies = get_object_taxonomies( $pub['post_type'] );
		foreach ($taxonomies as $taxonomy) {
			$post_terms = wp_get_object_terms($id, $taxonomy, array( 'orderby' => 'term_order' ));
			$post_terms = apply_filters( 'wpbs_pre_publish_to_draft_taxonomies', $post_terms );
			$terms = array();
			for ($i=0; $i<count($post_terms); $i++) {
				$terms[] = $post_terms[$i]->slug;
			}
			wp_set_object_terms($draft_id, $terms, $taxonomy);
		}

		add_post_meta($draft_id, '_wpbs_pre_post_id', $id);
		
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			wp_safe_redirect( admin_url( 'post.php?post=' . $draft_id . '&action=edit' ) );
			exit;
		}
	}
}


add_action( 'admin_notices', 'wpbs_admin_notice' );
function wpbs_admin_notice() {
	if ( isset($_REQUEST['post']) ) {
		$id = $_REQUEST['post'];
		if ( $old_id = get_post_meta( $id, '_wpbs_pre_post_id', true ) ) {
			echo '<div id="wpbs_notice" class="updated fade"><p>' . sprintf( __( "This post is a copy of the post id <a href='%s' target='__blank' >%s</a> Overwrite the original post by pressing the publish button.", WPBS_DOMAIN ),  get_permalink($old_id), $old_id ) . '</p></div>';
		}
	}
}

add_action( 'init', 'add_wpbs_save_post_hooks', 9999 );
function add_wpbs_save_post_hooks() {
    $additional_post_types = get_post_types( array( '_builtin' => false, 'show_ui' => true ) );
    foreach ( $additional_post_types as $post_type ) {
        add_action( 'publish_' . $post_type, 'wpbs_save_post', 9999, 2 );
    }
}

add_action( 'publish_page', 'wpbs_save_post', 9999, 2 );
add_action( 'publish_post', 'wpbs_save_post', 9999, 2 );
function wpbs_save_post( $id, $post ) {

	if ( $org_id = get_post_meta( $id, '_wpbs_pre_post_id', true ) ) {
		// post
		$new = array(
			'ID' => $org_id,
			'post_author' => $post->post_author,
			'post_date' => $post->post_date,
			'post_date_gmt' => $post->post_date_gmt,
			'post_content' => $post->post_content,
			'post_title' => $post->post_title,
			'post_excerpt' => $post->post_excerpt,
			'post_status' => 'publish',
			'comment_status' => $post->comment_status,
			'ping_status' => $post->ping_status,
			'post_password' => $post->post_password,
//			'post_name' => $post->post_name,
			'to_ping' => $post->to_ping,
			'pinged' => $post->pinged,
			'post_modified' => $post->post_modified,
			'post_modified_gmt' => $post->post_modified_gmt,
			'post_content_filtered' => $post->post_content_filtered,
			'post_parent' => $post->post_parent,
			'guid' => $post->guid,
			'menu_order' => $post->menu_order,
			'post_type' => $post->post_type,
			'post_mime_type' => $post->post_mime_type
		);
		wp_update_post( apply_filters( 'wpbs_draft_to_publish_update_post', $new ) );


		//postmeta
		$keys = get_post_custom_keys( $id );

		$custom_field = array();
		foreach ( (array) $keys as $key ) {
			if ( preg_match( '/^_feedback_/', $key ) )
				continue;

			if ( preg_match( '/_wpbs_pre_post_id/', $key ) )
				continue;

			if ( preg_match( '/_wp_old_slug/', $key ) )
				continue;
				
			$key = apply_filters( 'wpbs_draft_to_publish_postmeta_filter', $key );

			delete_post_meta( $org_id, $key );
			$values = get_post_custom_values($key, $id );
			foreach ( $values as $value ) {
				add_post_meta( $org_id, $key, $value );
			}
		}


		//attachment
//		$args = array( 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => null, 'post_parent' => $org_id );
//		$attachments = get_posts( $args );
//		if ($attachments) {
//			foreach ( $attachments as $attachment ) {
//				wp_delete_post( $attachment->ID );
//			}
//		}

		$args = array( 'post_type' => 'attachment', 'numberposts' => -1, 'post_status' => null, 'post_parent' => $id ); 
		$attachments = get_posts( $args );
		if ($attachments) {
			foreach ( $attachments as $attachment ) {
				$new = array(
					'post_author' => $attachment->post_author,
					'post_date' => $attachment->post_date,
					'post_date_gmt' => $attachment->post_date_gmt,
					'post_content' => $attachment->post_content,
					'post_title' => $attachment->post_title,
					'post_excerpt' => $attachment->post_excerpt,
					'post_status' => $attachment->post_status,
					'comment_status' => $attachment->comment_status,
					'ping_status' => $attachment->ping_status,
					'post_password' => $attachment->post_password,
					'post_name' => $attachment->post_name,
					'to_ping' => $attachment->to_ping,
					'pinged' => $attachment->pinged,
					'post_modified' => $attachment->post_modified,
					'post_modified_gmt' => $attachment->post_modified_gmt,
					'post_content_filtered' => $attachment->post_content_filtered,
					'post_parent' => $draft_id,
					'guid' => $attachment->guid,
					'menu_order' => $attachment->menu_order,
					'post_type' => $attachment->post_type,
					'post_mime_type' => $attachment->post_mime_type,
					'comment_count' => $attachment->comment_count
				);
				$new = apply_filters( 'wpbs_pre_draft_to_publish_attachment', $new );
				$attachment_newid = wp_insert_post( $new );
				$keys = get_post_custom_keys( $attachment->ID );

				$custom_field = array();
				foreach ( (array) $keys as $key ) {
					$value = get_post_meta( $attachment->ID, $key, true );

					delete_post_meta( $org_id, $key );
					add_post_meta( $org_id, $key, $value );
				}
			}
		}


		//tax
		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ($taxonomies as $taxonomy) {
			$post_terms = wp_get_object_terms($id, $taxonomy, array( 'orderby' => 'term_order' ));
			$post_terms = apply_filters( 'wpbs_pre_draft_to_publish_taxonomies', $post_terms );
			$terms = array();
			for ($i=0; $i<count($post_terms); $i++) {
				$terms[] = $post_terms[$i]->slug;
			}
			wp_set_object_terms($org_id, $terms, $taxonomy);
		}

	wp_delete_post( $id );
	wp_safe_redirect( admin_url( '/post.php?post=' . $org_id . '&action=edit&message=1' ) );
	exit;
	}
}

//add_action( 'admin_init', 'wpbs_admin_notice_saved_init' );

function wpbs_admin_notice_saved_init() {
	if ( isset($_REQUEST['message']) && $_REQUEST['message'] == 'wpbs_msg' )
		add_action( 'admin_notices', 'wpbs_admin_notice_saved' );
}

function wpbs_admin_notice_saved() {
	echo '<div id="wpbs_notice" class="updated fade"><p></p></div>';

}

add_filter( 'display_post_states', 'wpbs_display_branch_stat' );
function wpbs_display_branch_stat( $stat ) {
	global $post;
	if ( $org_id = get_post_meta( $post->ID, '_wpbs_pre_post_id', true ) ) {
		$stat[] = sprintf( __( 'Branch of %d', WPBS_DOMAIN ), $org_id );
	}
	return $stat;
}