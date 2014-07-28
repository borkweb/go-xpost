<?php

/**
 * Provides base functionality for cross posting,
 * could be leveraged via command line in addition to the typical implementation of GO_XPost
 */

class GO_XPost_Cron
{
	public $slug = 'go-xpost-cron';

	public function __construct()
	{
		add_action( 'edit_post', array( $this, 'edit_post' ) );
		add_action( 'wp_update_comment_count', array( $this, 'wp_update_comment_count' ) );
		add_action( 'wp_ajax_go_xpost_register_cron', array( $this, 'register_cron' ) );

		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
	} // END __construct

	/**
	 * On post save/edit remove the cron xPost term
	 */
	public function edit_post( $post_id )
	{
		// Don't bother with autosaves
		if ( defined( 'DOING_AUTOSAVE' ) )
		{
			return;
		}//end if

		$this->remove_cron_term( $post_id );
	} // END edit_post

	/**
	 * Remove all terms the xPost taxonomy for a given post
	 */
	public function remove_cron_term( $post_id )
	{
		wp_delete_object_term_relationships( $post_id, $this->slug );
	} // END remove_comment_cron

	/**
	 * Get posts and xPost them as configured
	 */
	public function process_cron()
	{
		$posts = $this->get_posts(
			go_xpost()->config->cron_post_types,
			go_xpost()->config->cron_term,
			go_xpost()->config->cron_limit
		);

		if ( ! $posts )
		{
			return;
		} // END if

		foreach ( $posts as $post )
		{
			if ( ! isset( $post->ID ) )
			{
				continue;
			} // END if

			go_xpost()->process_post( $post->ID );
			wp_set_post_terms( $post->ID, go_xpost()->config->cron_term, $this->slug, TRUE );

			sleep( 2 );
		} // END foreach
	} // END process_cron

	/**
	 * Add our custom cron interval to WordPress
	 */
	public function cron_schedules( $schedules )
	{
		$schedules[ $this->slug . '-interval' ] = array(
			'interval' => absint( go_xpost()->config()->cron_interval ) * 60,
			'display'  => 'Cron interval for Gigaom xPost (' . absint( go_xpost()->config()->cron_interval ) . 'min)',
		);

		return $schedules;
	} // END cron_schedules

	/**
	 * Get posts for use in process_cron or batch xPosting
	 */
	public function get_posts( $post_types, $term, $limit = 10 )
	{
		$args = array(
			'post_status' => array( 'any' ),
			'post_type' => (array) $post_types,
			'tax_query' => array(
				array(
					'taxonomy' => $this->slug,
					'field'    => 'slug',
					'terms'    => array( sanitize_key( $term ) ),
					'operator' => 'NOT IN',
				),
			),
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'posts_per_page' => $limit,
		);

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() )
		{
			return FALSE;
		} // END if

		return $query->posts;
	} // END get_posts

	/**
	 * Return a nonce link to use for registering/unregistering our cron hook with WordPress
	 */
	public function register_cron_link()
	{
		$args = array(
			'action' => 'go_xpost_register_cron',
			'nonce'  => wp_create_nonce( $this->slug . '-registier-cron' ),
		);

		if ( ! wp_next_scheduled( 'go_xpost_process_cron' ) )
		{
			$direction = 'Enable';
		} // END if
		else
		{
			$direction = 'Disable';
		} // END else

		$url = add_query_arg( $args, admin_url( 'admin-ajax.php' ) );

		return '<a href="' . esc_url( $url ) . '" title="' . esc_attr( $direction ) . ' Cron xPosting" class="button-primary">' . esc_html( $direction ) . ' Cron xPosting</a>';
	} // END register_cron_link

	/**
	 * AJAX endpoint which registers or unregisters our cron hook wtih WordPress
	 */
	public function register_cron()
	{
		if ( ! current_user_can( 'manage_options' ) )
		{
			wp_die( 'You don not have permission to be here!', 'Bad user! Bad!' );
		} // END if

		if ( ! wp_verify_nonce( $_GET['nonce'], $this->slug . '-registier-cron' ) )
		{
			wp_nonce_ays( 'register-cron' );
		} // END if

		// Schedule the cron job if it's not already scheduled
		if ( ! $timestamp = wp_next_scheduled( 'go_xpost_process_cron' ) )
		{
			wp_schedule_event( time(), $this->slug . '-interval', 'go_xpost_process_cron' );
			$success = 'registered';
		}
		else
		{
			wp_unschedule_event( $timestamp, 'go_xpost_process_cron' );
			$success = 'unregistered';
		} // END else

		wp_safe_redirect( add_query_arg( 'success', $success, admin_url( 'options-general.php?page=go-xpost-settings' ) ) );
		die;
	} // END register_cron
} // END GO_XPost_Cron
