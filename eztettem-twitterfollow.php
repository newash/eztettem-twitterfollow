<?php
/**
 * Plugin Name:  Eztettem Twitter Auto Follow
 * Plugin URI:   http://www.eztettem.hu
 * Description:  Automate common Twitter activities such as following & unfollowing twitter accounts.
 * Version:      1.0.0
 * Tested up to: 4.3
 * Author:       Enterprise Software Innovation Kft.
 * Author URI:   http://google.com/+EnterpriseSoftwareInnovationKftBudapest
 * Text Domain:  eztettem
 * License:      GPL2
 */
class Eztettem_Twitter_Follow {
	const CRON_EVENT = 'twitter_event';
	const ADMIN_ID = 'eztettem_twitter_options';
	const OPTION_PREFIX = 'eztettem_twitter_';
	const T_CHECK = 1;
	const T_INPUT = 2;

	const MAX_DELAY_TIME = 8;  // Max delay in seconds between api requests (following or unfollowing)
	const MAX_UNFOLLOW = 4000; // Max amount of users to unfollow in one run of this script
	const MAX_FOLLOW = 984;    // Max amount of users to follow in one run of this script

	private $twitter;
	private $options = array(
			array( 'id' => 'cron',            'type' => self::T_CHECK, 'name' => 'Cron',      'text' => 'Enable cron' ),
			array( 'id' => 'users',           'type' => self::T_INPUT, 'name' => 'User list', 'text' => 'List of users, separated by commas, who\'s followers to follow' ),
			array( 'id' => 'consumer_key',    'type' => self::T_INPUT, 'name' => 'Twitter Consumer Key' ),
			array( 'id' => 'consumer_secret', 'type' => self::T_INPUT, 'name' => 'Twitter Consumer Secret' ),
			array( 'id' => 'token',           'type' => self::T_INPUT, 'name' => 'Twitter OAuth Token' ),
			array( 'id' => 'token_secret',    'type' => self::T_INPUT, 'name' => 'Twitter OAuth Token Secret' )
	);

	public function __construct() {
		add_action( 'admin_init',                                    array( &$this, 'admin_options' )        );
		add_action( 'update_option_' . self::OPTION_PREFIX . 'cron', array( &$this, 'update_cron'   ), 10, 2 );
		add_action( self::CRON_EVENT,                                array( &$this, 'do_cronjob'    )        );
	}

	/**
	 * Put settings fields on 'Settings' > 'Reader'
	 */
	public function admin_options() {
		add_settings_section( self::ADMIN_ID, __( 'Twitter Auto Follow', 'eztettem' ), null, 'reading' );
		foreach($this->options as $option) {
			$id = self::OPTION_PREFIX . $option['id'];
			register_setting( 'reading', $id );
			add_settings_field( $id, __( $option['name'], 'eztettem' ), array( &$this, 'admin_callback' ), 'reading', self::ADMIN_ID, $option );
		}
	}

	/**
	 * Setting fields callback function
	 */
	public function admin_callback( $args ) {
		extract( $args );
		$id = self::OPTION_PREFIX . $id;
		if( $type === self::T_CHECK )
			printf('<label for="%1$s"><input name="%1$s" type="checkbox" id="%1$s" value="%2$s">%3$s</label>', $id, get_option( $id ), $text );
		elseif( $type === self::T_INPUT ) {
			printf('<input name="%1$s" type="text" id="%1$s" value="%2$s" class="regular-text">', $id, get_option( $id ) );
			if( $text )
				printf( '<p class="description">%s</p>', $text );
		}
	}

	/**
	 * TODO
	 */
	public function update_cron( $old_value, $value ) {
		if( $old_value === $value ) return;
		if( $value )
			wp_schedule_event( time(), 'hourly', self::CRON_EVENT );
		else
			wp_clear_scheduled_hook( self::CRON_EVENT );
	}

	/**
	 * Main logic
	 *
	 * Picks a user from the user list set in Admin and follow its followers using these rules:
	 * - don't follow a user if he's already following me
	 * - in one run do maximum MAX_FOLLOW or MAX_UNFOLLOW transactions
	 * - between transactions wait a random number of seconds up to MAX_DELAY_TIME
	 * - unfollow some non-followers if ...
	 *
	 * The Twitter OAuth library is only loaded here, but it's totally fine.
	 * @see http://php.net/manual/en/function.include.php
	 */
	public function do_cronjob() {
		require_once('lib/TwitterOAuth.php');

		// Create Twitter OAuth object
		$options = array();
		array_walk( $this->options, function( $v, $k, &$o ) { return $o[$v['id']] = get_option( self::OPTION_PREFIX . $v['id'] ); }, &$options );
		extract( $options );
		$this->twitter = new Abraham\TwitterOAuth\TwitterOAuth($consumer_key, $consumer_secret, $token, $token_secret);

		// Start logging with initial follow data
		$credentials = $this->twitter->get( 'account/verify_credentials' );
		$following_count = $credentials->friends_count;
		$followers_count = $credentials->followers_count;
		$this->log( 'cron starts - following %d & followers %d', $following_count, $followers_count );

		// Get users I'm following with oldest first
		$followings = $twitterAuth->get( 'friends/ids' );
		$followings = $followings->ids;
		$followings = array_reverse( $followings );

		// Get users following me
		$followers = $twitterAuth->get( 'followers/ids' );
		$followers = $followers->ids;

		// Get users following a randomly picked target user
		$target_users = array_map( 'trim', explode(',', $eztettem_twitter_users ) );
		$target_user = $target_users[mt_rand( 0, count( $targetusers ) - 1 )];
		$target_followers = $twitterAuth->get( 'followers/ids', array( 'screen_name' => $target_user ) );
		$target_followers = $target_followers->ids;

		// Can't follow any more if followed 2000 and under 2000 followers
		if( $following_count - $followers_count < 600 || $followers_count < 2000 && $following_count > 1950 )
			$this->unfollow_users( $followings, followers );
		else
			$this->follow_users( $target_followers, $followings );
	}

	/**
	 * Follow users that are I'm not following yet from the given list
	 */
	function follow_users( $target_followers, $followings ) {
		$more_to_follow = self::MAX_FOLLOW;
		foreach( $target_followers as $target_follower ) {
			if( $more_to_follow <= 0 ) break;                         // It was enough to follow
			if( in_array( $target_follower, $followings ) ) continue; // I'm already following this user

			$this->twitter->post( 'friendships/create', array( 'user_id' => $target_follower ) );
			$more_to_follow--;

			$delay_time = rand( 3, self::MAX_DELAY_TIME );
			$this->log( '+++ followed a user - sleeping for %d seconds...', $delay_time );
			sleep( $delay_time );
		}
	}

	/**
	 * Unfollow users not following me
	 */
	function unfollow_users($followings, $followers) {
		$more_to_unfollow = self::MAX_UNFOLLOW;
		foreach( $followings as $following ) {
			if( $more_to_unfollow <= 0 ) break;                // It was enough to unfollow
			if( in_array( $following, $followers ) ) continue; // Keep the user if he's following me

			$this->twitter->post( 'friendships/destroy', array( 'user_id' => $following ) );
			$more_to_unfollow--;

			$delay_time = rand( 3, self::MAX_DELAY_TIME );
			$this->log( '--- unfollowed a user - sleeping for %d seconds...', $delay_time );
			sleep( $delay_time );
		}
	}

	/**
	 * Do formatted logging in WordPress debug output
	 * @see http://codex.wordpress.org/Debugging_in_WordPress
	 */
	function log() {
		$args = func_get_args();
		error_log( vsprintf( 'AutoTwitter: ' . array_shift( $args ), $args ) );
	}
}

add_action( 'after_setup_theme', function() { new Eztettem_Twitter_Follow(); } );
