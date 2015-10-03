# eztettem-twitterfollow
WordPress plugin for automated common Twitter activities

Based on [Joey's Twitter-Auto-Pilot](https://github.com/JoeyTawadrous/Twitter-Auto-Pilot) project.

For what this plugin can be good for check the README of that project or his [super-detailed blogpost](http://www.joeyt.net/blog/twitter-tutorial-how-to-gain-over-3000-new-followers-a-month/).

The difference here that it's a fully functioning WordPress plugin where you can set all the parameters in the admin area under "Settings >> Reading".

![admin-screenshot](https://cloud.githubusercontent.com/assets/4682432/10262463/aeba5f94-69c0-11e5-9265-21122f9c5dcb.png)

**IMPORTANT**: Since the process runs in a Cron job I stronly advise to turn off the default WP Cron and setup a normal Cron instead as described here: [Properly Setting Up WordPress Cron Jobs](https://tommcfarlin.com/wordpress-cron-jobs/).
 And it's not because it wouldn't have precise scheduling, but it's a very lengthy process that can take up to 6 minutes to run, and it would cause timeouts for normal website visitors.

*The library for Twitter communication is from [Abraham Williams](https://github.com/abraham/twitteroauth), though I'm not exacly sure which version is this beside that it's small and works.*



