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

	const MAX_DELAY_TIME = 8;     // Max delay in seconds between API requests (following or unfollowing)
	const MAX_FOLLOW = 41;        // = 1000 / 24 as cannot follow more than 1000 in a day
	const MAX_UNFOLLOW = 50;      // There is no specific rule for unfollow limit, but you should be careful
	const CONSTR_TRESHOLD = 2000; // A user can follow this many without other constraints
	const CONSTR_RATIO = 1.1;     // Over the treshold this has to be the following / follower ratio

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
			printf('<label for="%1$s"><input name="%1$s" type="checkbox" id="%1$s" value="1" %2$s>%3$s</label>', $id, checked('1', get_option( $id ), false), $text );
		elseif( $type === self::T_INPUT ) {
			printf('<input name="%1$s" type="text" id="%1$s" value="%2$s" class="regular-text">', $id, get_option( $id ) );
			if( !empty( $text ) )
				printf( '<p class="description">%s</p>', $text );
		}
	}

	/**
	 * Turn cron scheduling on / off based on the setting
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
	 * - unfollow some users if follower number reaches Twitter limits
	 *
	 * Twitter restrictions explained:
	 * - Twitter limits https://support.twitter.com/articles/15364
	 * - Following rules and best practices https://support.twitter.com/articles/68916
	 * - Do You Know the Twitter Limits http://iag.me/socialmedia/guides/do-you-know-the-twitter-limits/
	 *
	 * The Twitter OAuth library is only loaded here, but it's totally fine.
	 * @see http://php.net/manual/en/function.include.php
	 */
	public function do_cronjob() {
		require_once('lib/TwitterOAuth.php');

		// Create Twitter OAuth object
		$options = array();
		array_walk( $this->options, function( $o ) use ( &$options ) {
			return $options[$o['id']] = get_option( Eztettem_Twitter_Follow::OPTION_PREFIX . $o['id'] );
		} );
		extract( $options );
		$this->twitter = new TwitterOAuth($consumer_key, $consumer_secret, $token, $token_secret);

		// Start and log initial follow data
		$credentials = $this->twitter->get( 'account/verify_credentials' );
		if($credentials === null) {
			$this->log( 'ERROR connecting to Twitter!' );
			return;
		}
		$following_count = $credentials->friends_count;
		$followers_count = $credentials->followers_count;
		$this->log( 'START cron - following %d - followers %d', $following_count, $followers_count );

		// Get users I'm following with oldest first
		$followings = $this->twitter->get( 'friends/ids' );
		$followings = $followings->ids;
		$followings = array_reverse( $followings );

		// Get users following me
		$followers = $this->twitter->get( 'followers/ids' );
		$followers = $followers->ids;

		// Get users following a randomly picked target user
		$target_users = array_map( 'trim', explode(',', $users ) );
		$target_user = $target_users[mt_rand( 0, count( $target_users ) - 1 )];
		$target_followers = $this->twitter->get( 'followers/ids', array( 'screen_name' => $target_user ) );
		$target_followers = $target_followers->ids;
		$this->log('picked user to follow followers: %s', $target_user);

		// Do the real stuff
		$max_allowed = max( self::CONSTR_TRESHOLD, $followers_count * self::CONSTR_RATIO );
		if( $following_count + self::MAX_FOLLOW < $max_allowed )
			$this->follow_users( $target_followers, $followings );
		else
			$this->unfollow_users( $followings, followers );

		$this->log( 'END cron' );
	}

	/**
	 * Follow users that are I'm not following yet from the given list
	 */
	private function follow_users( $target_followers, $followings ) {
		$target_followers = array_diff( $target_followers, $followings );
		shuffle( $target_followers );
		foreach( array_slice( $target_followers, 0, self::MAX_FOLLOW - 1 ) as $target_follower ) {
			$this->twitter->post( 'friendships/create', array( 'user_id' => $target_follower ) );

			$delay_time = rand( 3, self::MAX_DELAY_TIME );
			$this->log( '+++ followed user %s - sleeping for %d seconds...', $target_follower, $delay_time );
			sleep( $delay_time );
		}
	}

	/**
	 * Unfollow users not following me
	 */
	private function unfollow_users( $followings, $followers ) {
		$followings = array_diff( $followings, $followers );
		foreach( array_slice( $followings, 0, self::MAX_UNFOLLOW - 1 ) as $following ) {
			$this->twitter->post( 'friendships/destroy', array( 'user_id' => $following ) );

			$delay_time = rand( 3, self::MAX_DELAY_TIME );
			$this->log( '--- unfollowed user %s - sleeping for %d seconds...', $target_follower, $delay_time );
			sleep( $delay_time );
		}
	}

	/**
	 * Do formatted logging in WordPress debug output
	 * @see http://codex.wordpress.org/Debugging_in_WordPress
	 */
	private function log() {
		$args = func_get_args();
		error_log( vsprintf( 'AutoTwitter: ' . array_shift( $args ), $args ) );
	}
}

add_action( 'after_setup_theme', function() { new Eztettem_Twitter_Follow(); } );
