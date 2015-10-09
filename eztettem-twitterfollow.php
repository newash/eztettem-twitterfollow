<?php
/**
 * Plugin Name:  Eztettem Twitter Auto Follow
 * Plugin URI:   http://www.eztettem.hu
 * Description:  Automate common Twitter activities such as following & unfollowing twitter accounts.
 * Version:      1.2.2
 * Tested up to: 4.3.1
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

	const MAX_DELAY_TIME = 8;      // Max delay in seconds between API requests (following or unfollowing)
	const MAX_DAILY_FOLLOW = 1000; // Cannot follow more than 1000 in a day
	const MAX_RATIO = 2.0;         // Custom rule: maximum following / follower ratio so the numbers don't look bad for other Twitter users
	const MIN_OVERHEAD = 800;      // Custom rule: additionally to MAX_RATIO allow minimum this more followings than followers
	const CONSTR_TRESHOLD = 2000;  // A user can follow this many without other constraints
	const CONSTR_RATIO = 1.1;      // Over the treshold this has to be the following / follower ratio

	private $twitter;
	private $options = array(
			array( 'id' => 'cron',            'type' => self::T_CHECK, 'name' => 'Cron',      'text' => 'Enable cron' ),
			array( 'id' => 'users',           'type' => self::T_INPUT, 'name' => 'User list', 'text' => 'Comma separated list of users, who\'s followers to follow.' ),
			array( 'id' => 'hashtags',        'type' => self::T_INPUT, 'name' => 'Hashtag list', 'text' => 'Comma separated list of hashtags, who\'s tweeters to follow.' ),
			array( 'id' => 'inactive',        'type' => self::T_INPUT, 'name' => 'Inactivity filter', 'text' => 'Not to follow users being inactive for this many <strong>days</strong>.' ),
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
	 * Targets can be either users to follow their followers or hashtags to follow those tweeting them.
	 * After randomly picking a target the procedure is using these rules:
	 * - Don't follow a user if he's already following me.
	 * - In one run do maximum MAX_FOLLOW or MAX_UNFOLLOW transactions.
	 * - Between transactions wait a random number of seconds up to MAX_DELAY_TIME.
	 * - Inactive users likely won't follow back, so a parameter is used to filter out users being
	 *   inactive for that many days.
	 * - Unfollow some users if follower number reaches Twitter limits.
	 * - Also unfollow users if our custom criteria is met.
	 * - Twitter seems to punish following and unfollowing users in the same round, so a logic is applied
	 *   to unfollow 2X users in one and follow X in the next two. Because of this the "follow rounds" are
	 *   just 2/3 of total, so the maximum follow actions can be multiplied by 3/2 in a round.
	 *
	 * Twitter restrictions explained:
	 * - Twitter limits https://support.twitter.com/articles/15364
	 * - Following rules and best practices https://support.twitter.com/articles/68916
	 * - Do You Know the Twitter Limits http://iag.me/socialmedia/guides/do-you-know-the-twitter-limits/
	 *
	 * Custom criteria explained:
	 * - If you start with just a few followers the number of "followings" can quickly go up to 2000
	 *   and a "follower" number around 100 will just look bad for other Twitter users. To avoid that
	 *   the ratio can only be MAX_RATIO, except for really low "follower" numbers. For those a
	 *   +MIN_OVERHEAD is allowed to be able to grow in a sanely manner.
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

		// Determine maximum allowed following count and the hourly number
		$custom_max_allowed = max( $followers_count + self::MIN_OVERHEAD, $followers_count * self::MAX_RATIO );
		$twitter_max_allowed = max( $followers_count * self::CONSTR_RATIO, self::CONSTR_TRESHOLD );
		$combined_max_allowed = min( $custom_max_allowed, $twitter_max_allowed );
		$follow_hourly = intval( min( ( $combined_max_allowed - $followers_count ) * 1.5, self::MAX_DAILY_FOLLOW ) / 24 );

		// Make some room for new followings if needed but don't unfollow + follow in one round
		if( $following_count + $follow_hourly > $combined_max_allowed )
			$this->unfollow_users( $follow_hourly * 2, $followings, $followers );
		else {
			// Get following or tweeting users randomly
			$user_list = $users ? array_map( 'trim', explode(',', $users ) ) : array();
			$hashtag_list = $hashtags ? array_map( 'trim', explode(',', $hashtags ) ) : array();
			$target_index = mt_rand( 0, count( $user_list ) + count( $hashtag_list ) - 1 );
			if( $target_index < count( $user_list ) ) {
				$target_users = $this->twitter->get( 'followers/ids', array( 'screen_name' => $user_list[$target_index] ) );
				$target_users = $target_users->ids;
				$this->log( 'picked user to follow followers: %s', $user_list[$target_index] );
			} else {
				$target_index -= count( $user_list );
				$target_users = $this->twitter->get( 'search/tweets', array( 'q' => '#' . $hashtag_list[$target_index], 'count' => 100 ) );
				$target_users = array_unique( array_map( function( $s ) { return $s->user->id; }, $target_users->statuses ) );
				$this->log( 'picked hashtag to follow tweeters: #%s', $hashtag_list[$target_index] );
			}
			$this->follow_users( $follow_hourly, $target_users, $followings, intval( $inactive ) );
		}

		$this->log( 'END cron' );
	}

	/**
	 * Follow users that are I'm not following yet from the given list,
	 * with filtering out those inactive for the specified number of days
	 */
	private function follow_users( $follow_num, $target_users, $followings, $inactive_days ) {
		$target_users = array_diff( $target_users, $followings );
		shuffle( $target_users );
		if( $inactive_days ) {
			$target_details = $this->twitter->post( 'users/lookup', array( 'user_id' => implode( ',', array_slice( $target_users, 0, 100 ) ) ) );
			$target_users = array_map( function( $u ) {
				return $u->id;
			}, array_filter( $target_details, function( $t ) use ( $inactive_days ) {
				return isset( $t->status ) && strtotime( $t->status->created_at ) > strtotime( "-$inactive_days days" );
			} ) );
		}

		foreach( array_slice( $target_users, 0, $follow_num ) as $target_user ) {
			$this->twitter->post( 'friendships/create', array( 'user_id' => $target_user ) );

			$delay_time = rand( 3, self::MAX_DELAY_TIME );
			$this->log( '+++ followed user %s - sleeping for %d seconds...', $target_user, $delay_time );
			sleep( $delay_time );
		}
	}

	/**
	 * Unfollow users not following me
	 */
	private function unfollow_users( $unfollow_num, $followings, $followers ) {
		$followings = array_diff( $followings, $followers );
		foreach( array_slice( $followings, 0, $unfollow_num ) as $following ) {
			$this->twitter->post( 'friendships/destroy', array( 'user_id' => $following ) );

			$delay_time = rand( 3, self::MAX_DELAY_TIME );
			$this->log( '--- unfollowed user %s - sleeping for %d seconds...', $following, $delay_time );
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
