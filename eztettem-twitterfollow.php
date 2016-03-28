<?php
/**
 * Plugin Name:  Eztettem Twitter Auto Pilot
 * Plugin URI:   http://www.eztettem.hu
 * Description:  Automate common Twitter activities such as following twitter accounts & favouriting tweets.
 * Version:      1.3.3
 * Tested up to: 4.4.2
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
	const MAX_HOURLY_FOLLOW = 12;  // Should not be aggressive, so 12 users/hour maximum
	const MAX_HOURLY_FAV = 10;     // Also should not be aggressive.
	const MAX_RATIO = 2.0;         // Custom rule: maximum following / follower ratio so the numbers don't look bad for other Twitter users
	const MIN_OVERHEAD = 800;      // Custom rule: additionally to MAX_RATIO allow minimum this more followings than followers
	const CONSTR_TRESHOLD = 5000;  // A user can follow this many without other constraints
	const CONSTR_RATIO = 1.1;      // Over the treshold this has to be the following / follower ratio

	private $twitter;
	private $options = array(
			array( 'id' => 'cron',            'type' => self::T_CHECK, 'name' => 'Cron',      'text' => 'Enable cron' ),
			array( 'id' => 'users',           'type' => self::T_INPUT, 'name' => 'User list', 'text' => 'Comma separated list of users, who\'s followers to follow.' ),
			array( 'id' => 'hashtags',        'type' => self::T_INPUT, 'name' => 'Hashtag list', 'text' => 'Comma separated list of hashtags, who\'s tweeters to follow.' ),
			array( 'id' => 'geocode',         'type' => self::T_INPUT, 'name' => 'Geo location', 'text' => 'Filters the hashtag tweets. Use format <code>latitude,longitude,radius</code>.' ),
			array( 'id' => 'inactive',        'type' => self::T_INPUT, 'name' => 'Inactivity filter', 'text' => 'Not to follow users being inactive for this many <strong>days</strong>.' ),
			array( 'id' => 'consumer_key',    'type' => self::T_INPUT, 'name' => 'Twitter Consumer Key' ),
			array( 'id' => 'consumer_secret', 'type' => self::T_INPUT, 'name' => 'Twitter Consumer Secret' ),
			array( 'id' => 'token',           'type' => self::T_INPUT, 'name' => 'Twitter OAuth Token' ),
			array( 'id' => 'token_secret',    'type' => self::T_INPUT, 'name' => 'Twitter OAuth Token Secret' )
	);
	private $internal_options = array(
			array( 'id' => 'last_mention' ),
			array( 'id' => 'last_hashtag' )
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
		add_settings_section( self::ADMIN_ID, __( 'Twitter Auto Pilot', 'eztettem' ), null, 'reading' );
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
	 * - Don't follow a user if he's already followed.
	 * - In one run do maximum MAX_HOURLY_FOLLOW.
	 * - Between transactions wait a random number of seconds up to MAX_DELAY_TIME.
	 * - Inactive users likely won't follow back, so a parameter is used to filter out users being
	 *   inactive for that many days.
	 * - Unfollow some users if follower number reaches Twitter limits.
	 * - Also unfollow users if our custom criteria is met.
	 * - When unfollowing pick those having the biggest follow count, they are more likely to be bots anyway.
	 *
	 * Beside following users it favourites some tweets as well:
	 * - Most importantly it favourites any new one mentioning the current user.
	 * - If still under MAX_HOURLY_FAV limit, also favourites tweets having the given hashtags.
	 * - Like for the follows it also tries to stay below limits -> so unfavourites if necessary.
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
		array_walk( array_merge( $this->options, $this->internal_options ), function( $o ) use ( &$options ) {
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

		// Get users already following with oldest first
		$followings = $this->twitter->get( 'friends/ids' );
		$followings = $followings->ids;
		$followings = array_reverse( $followings );

		// Get users following the current user
		$followers = $this->twitter->get( 'followers/ids' );
		$followers = $followers->ids;

		// Get all favourites that can be manipulated (might not be all)
		$favourites = $this->get_my_favourite_ids();
		$favourites_count = count( $favourites );
		$this->log( 'START cron - following %d - followers %d - favourites %d', $following_count, $followers_count, $favourites_count );

		// Determine maximum allowed following count and the hourly number
		$custom_max_allowed = max( $followers_count + self::MIN_OVERHEAD, $followers_count * self::MAX_RATIO );
		$twitter_max_allowed = max( $followers_count * self::CONSTR_RATIO, self::CONSTR_TRESHOLD );
		$combined_max_allowed = min( $custom_max_allowed, $twitter_max_allowed );
		$follow_hourly = min( intval( ( $combined_max_allowed - $followers_count ) * 2 / 24 ), self::MAX_HOURLY_FOLLOW );
		$fav_hourly = min( intval( ( $combined_max_allowed - $favourites_count ) * 2 / 24 ), self::MAX_HOURLY_FAV );

		// Prepare WordPress options
		$user_list = $users ? array_map( 'trim', explode(',', $users ) ) : array();
		$last_mention = $last_mention ? $last_mention : 1;
		$last_hashtag = $last_hashtag ? $last_hashtag : 1;

		// Decide what can be done
		$can_follow = $following_count + $follow_hourly < $combined_max_allowed;
		$can_fav = $favourites_count + $fav_hourly < $combined_max_allowed;
		$follow_hashtags = mt_rand( 0, 1 );

		// Main logic
		if( $can_fav ) {
			list( $favourites, $last_mention ) = $this->get_mentions( $last_mention );
			$fav_method = array( 'favorites/create', '++# favourited' );
		} else
			$fav_method = array( 'favorites/destroy', '--# unfavourited' );
		if( $can_follow ) {
			$target_users = empty( $user_list ) ? array() : $this->get_target_followers( $user_list, $followings, $inactive );
			$follow_method = array( 'friendships/create', '+++ followed' );
		} else {
			$target_users = $this->get_non_followers($followings, $followers);
			$follow_method = array( 'friendships/destroy', '--- unfollowed' );
		}
		if( ( $can_fav || $can_follow && $follow_hashtags ) && !empty( $hashtags ) ) {
			list( $tweets, $users, $last_hashtag ) = $this->process_hashtags( $hashtags, $geocode, $last_hashtag, $followings );
			$favourites = array_merge( $favourites, $tweets );
			$target_users = array_merge( $users, $target_users );
		}

		$this->process_objects( $target_users, $follow_hourly, 'user_id', $follow_method[0], $follow_method[1] );
		$this->process_objects( $favourites, $fav_hourly, 'id', $fav_method[0], $fav_method[1] );

		// Save internal options for the next run
		foreach( $this->internal_options as $saveable )
			update_option( Eztettem_Twitter_Follow::OPTION_PREFIX . $saveable['id'], ${$saveable['id']} );

		$this->log( 'END cron' );
	}

	/**
	 * Get all tweet IDs favourited in chronological order.
	 * Twitter API returns these starting with the most recent ones and only 200 at a time,
	 * so for the correct order we need to load them all in a loop and reverse the list.
	 */
	private function get_my_favourite_ids() {
		$api_limit = 15;
		$fav_ids = array();
		$fav_param = array( 'count' => 200 );
		do {
			if( $fav_ids )
				$fav_param['max_id'] = array_pop( $fav_ids );
			$favs = $this->twitter->get( 'favorites/list', $fav_param );
			$fav_ids = array_merge( $fav_ids, array_map( function( $f ) { return $f->id_str; }, $favs ) );
			$api_limit--;
		} while( $api_limit > 0 && count( $favs ) === 200 );
		return array_reverse( $fav_ids );
	}

	/**
	 * Get IDs of tweets mentioning the current user.
	 * Only returns the new tweets since the last run.
	 * (For that, we persist the last ID in WordPress options.)
	 * @return array with:
	 *   [0] : list of tweet IDs
	 *   [1] : last processed tweet ID to start from next time
	 */
	private function get_mentions( $last_tweet_id ) {
		$mentions = $this->twitter->get( 'statuses/mentions_timeline', array( 'since_id' => $last_tweet_id, 'count' => 50 ) );
		$mentions = array_map( function( $t ) { return $t->id_str; }, $mentions );
		return array( $mentions, ( $mentions ? $mentions[0] : $last_tweet_id ) );
	}

	/**
	 * Get the IDs and the user IDs of tweets containing the given hashtags.
	 * This method serves two purposes:
	 * 1. the returned tweet IDs can be used to favourite them
	 * 2. the user IDs can be used to follow those users
	 *
	 * If it's given, the tweets are filtered with geolocation.
	 * NOTE: geocode "53.681093,-4.174805,476km" covers Ireland and UK (mostly)
	 *
	 * For users, we don't need to check for inactivity because
	 * with the right keywords chosen, the list will be always fresh.
	 * @return array with:
	 *   [0] : list of tweet IDs
	 *   [1] : list of user IDs
	 *   [2] : last processed tweet ID to start from next time
	 */
	private function process_hashtags( $hashtags, $geocode, $last_tweet_id, $followings ) {
		$search_param = array(
				'q' => '#' . preg_replace( '|\s*([^\s,]+)\s*,\s*|', '$1 OR #', $hashtags ),
				'since_id' => $last_tweet_id,
				'count' => 100
		);
		if( $geocode )
			$search_param['geocode'] = $geocode;
		$this->log( 'getting tweets with: %s', $search_param['q'] );
		$target_tweets = $this->twitter->get( 'search/tweets', $search_param );

		$tweet_ids = array_map( function( $t ) { return $t->id_str; }, $target_tweets->statuses );
		$target_users = array_unique( array_map( function( $s ) { return $s->user->id; }, $target_tweets->statuses ) );
		$target_users = array_diff( $target_users, $followings );
		shuffle( $target_users );
		return array( $tweet_ids, $target_users, ( $tweet_ids ? $tweet_ids[0] : $last_tweet_id ) );
	}

	/**
	 * Get user IDs following one from the given list of users.
	 * Filters out those the current user is aready following,
	 * and those inactive for the specified number of days.
	 */
	private function get_target_followers( $user_list, $followings, $inactive_days ) {
		$picked_user = $user_list[mt_rand( 0, count( $user_list ) - 1 )];
		$this->log( 'picked user to follow followers: %s', $picked_user );

		$target_users = $this->twitter->get( 'followers/ids', array( 'screen_name' => $picked_user ) );
		$target_users = $target_users->ids;
		$target_users = array_diff( $target_users, $followings );
		shuffle( $target_users );
		$inactive_days = intval( $inactive_days );
		if( !$inactive_days )
			return $target_users;

		$target_details = $this->twitter->post( 'users/lookup', array( 'user_id' => implode( ',', array_slice( $target_users, 0, 100 ) ) ) );
		return array_map( function( $u ) {
			return $u->id;
		}, array_filter( $target_details, function( $d ) use ( $inactive_days ) {
			return isset( $d->status ) && strtotime( $d->status->created_at ) > strtotime( "-$inactive_days days" );
		} ) );
	}

	/**
	 * Get user IDs not following the current user,
	 * by picking those having the most follows (probably bots) from the oldest 100.
	 * It's effective only if $unfollow_num is much less than 100.
	 */
	private function get_non_followers( $followings, $followers ) {
		$followings = array_diff( $followings, $followers );
		$following_details = $this->twitter->post( 'users/lookup', array( 'user_id' => implode( ',', array_slice( $followings, 0, 100 ) ) ) );
		usort( $following_details, function( $a, $b ) {
			return $b->friends_count - $a->friends_count;
		} );
		return array_map( function( $u ) { return $u->id; }, $following_details );
	}

	/**
	 * Process objects from the given list
	 * @return the number of object successfully processed
	 */
	private function process_objects( $ids, $process_num, $id_name, $api_method, $log_name ) {
		$success_num = 0;
		foreach( $ids as $id ) {
			if( $success_num == $process_num ) break;

			$resp = $this->twitter->post( $api_method, array( $id_name => $id ) );
			if( property_exists( $resp, 'errors' ) ) {
				$this->log( '%s %s ERROR: %s', $log_name, $id, $resp->errors[0]->message );
				continue;
			}
			$success_num++;

			$delay_time = rand( 3, self::MAX_DELAY_TIME );
			$this->log( '%s %s - sleeping for %d seconds...', $log_name, $id, $delay_time );
			sleep( $delay_time );
		}
		$this->log( '%s ==> %d out of %d', $log_name, $success_num, $process_num );
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
