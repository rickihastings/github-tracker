<?php

//
// Github tracker bot.
// This can be used to track github changes for any repos
// Multiple repos on multiple channels on multiple networks are supported :P
//
// We use v2 api currently, partly because v3 obviously isn't finished and
// that calls are limited per minute rather than per day, meaning we can use more calls.
//

set_time_limit( 0 );
error_reporting( E_ALL ^ E_NOTICE );
// set time limit to 0

include( 'src/xbot.php' );
include( 'src/events.php' );
include( 'src/timer.php' );
// include xbot framework

$bot = new bot( $argc, $argv );

class bot
{

	public $xbot;
	static public $config;
	static public $quiet = false;
	static public $db;
	static public $queries = 0;
	static public $buffer = array();
	static public $debug = false;
	static public $api_calls = 0;
	// give $xbot framework a var; so we can use it all around the class.

	/*
	* __construct
	*
	* @params
	* void
	*/
	public function __construct( $argc, $argv )
	{
		$this->xbot = new xbot;
		// new xbot

		self::$config = array(
			'options'	=> array(
				'track_commits'		=> false, // requires commit-tracker and post recieve hooks
				'track_new_issues'	=> true,
				'track_comments'	=> true,
				'track_closes'		=> true,
				'track_reopens'		=> true,
				'track_merges'		=> true,
				'new_data_interval'	=> 60 // in seconds, beware setting this below about 10 will probably exceed your daily limit of 5000
										  // and it certainly will if you have multiple repos being tracked, see github to get an unlimited
										  // amount of calls.
			),
			// here we can specify what to track
		
			'networks' 		=> array(
				'irc.ircnode.org'	=> array(
					'port' 			=> '6667',

					'nick' 			=> 'Github',
					'ident' 		=> 'github',
					'real' 			=> 'Github Tracker',
					'chans'			=> array(), // DO NOT USE.
				),
				// network two
			),
			
			'unparsed_responses'	=> array(
				'commits'	=> '{repo}: 6{user} 2{branch} * r{revision} / ({files}): {title} ({url})',
				'issues'	=> '{repo}: 6{user} {colour}{plural} issue #{id} at ({date}): {title} - {message} ({url})',
				'pulls'		=> '{repo}: 6{user} wants someone to merge (5{head_label}) into (7{base_label}): {title} - {message} ({url})',
				'comments'	=> '{repo}: 6{user} has commented on ({title}) (#{id}): {message} ({url})',
				'merges'	=> '{repo}: 6{user} has 3merged {commit} {plural} into (5{head_label}) into (7{base_label}) at ({date}): {title} - {message} ({url})',
				// unrequested responses, ie event based
				'commit'	=> '{repo}: commit added by 6{user} on ({date}): {title} ({url})',
				'issue'		=> '{repo}: #{id} opened by 6{user} on ({date}) ({comments} {plural}) ({colour}{state}): {title} - {message} ({url})',
				'pull'		=> '{repo}: pull request by 6{user} on ({date}) to merge 5{head_label} into 7{base_label} ({comments} {plural}) ({colour}{state}): {title} - {message} ({url})',
				// requested responses, ie commands
			),
			// unparsed messages
			
			'error_messages'		=> array(
				'help_messages'		=> array(
					'The following commands can be used in channel or via pm.',
					'  :commit user/repo id',
					'  :issue user/repo #id',
				),
				'admin_help_msgs'	=> array(
					'The following commands are admin only and can only be used via pm.',
					'  :track user/repo network/#channel',
					'  :untrack user/repo',
					'  :join network/#channel',
					'  :part network/#channel',
				),
				// help messages
				'join_syntax'		=> 'Syntax is :join network/#channel',
				'part_syntax'		=> 'Syntax is :part network/#channel',
				'join_already_in'	=> 'I\'m already in that channel!',
				'part_not_in'		=> 'I\'m not in that channel!',
				'part_already_track'=> 'I\'m currently tracking in this channel, if you wish to stop, see :untrack',
				// :join/part error messages
				'untrack_syntax'	=> 'Syntax is :untrack user/repo',
				'untrack_repo'		=> 'Sucessfully stopped tracking {repo}',
				// :untrack error messages
				'track_syntax'		=> 'Syntax is :track user/repo network/#channel',
				'track_nonet'		=> 'I\'m currently not connected to that network, you can connect me to it using my config array.',
				'track_new_repo_1'	=> 'Now tracking {repo} in {chan}. Collecting repo information...',
				'track_new_repo_2'	=> '... Done, repo now being tracked.',
				// :track error messages
				'commits_syntax'	=> 'Syntax is :commit user/repo id',
				'commits_noid'		=> 'Invalid commit id, make sure it is a full commit id in sha hash form.',
				// :commit error messages
				'issues_syntax'		=> 'Syntax is :issue user/repo #id',
				'issues_noid'		=> 'Invalid issue id, make sure it is a valid id, prefixed with a hash (#).',
				// :issue error messages
				'invalid_repo'		=> 'Invalid repo, are you sure I\'m tracking it?',
				'already_got_repo'	=> 'I\'m already tracking that repo.',
				'access_denied'		=> 'Sorry, you don\'t have access to use that command.',
				// misc errors
			),
			// error messages

			'ctcp'		=> array(
				'version'	=> 'Use me! And abuse me :3 https://github.com/n0valyfe/github-tracker',
			),
			// ctcp replies
			
			'mysql' 			=> array(
				'host' 		=> 'localhost',
				'user'		=> 'root',
				'pass'		=> '',
				'db'		=> 'samantha',
				'table_c'	=> 'git_chans',
				'table_i' 	=> 'git_info',
				'table_p' 	=> 'git_post_recieves',
			),
			// mysql
			
			'admin_hosts'		=> array(
				'10.0.2.2',
				'wl562-633.members.linode.com',
			),
			// admin hosts (don't add a *@, it doesn't support wildcards (might in future))
		);
		
		if ( $argc > 1 && $argv[1] == 'debug' )
			self::$debug = true;
		// argc
		
		self::debug( 'connecting to mysql ('.self::$config['mysql']['host'].':'.self::$config['mysql']['db'].')' );
		self::$db = mysql_connect( self::$config['mysql']['host'], self::$config['mysql']['user'], self::$config['mysql']['pass'] );
		mysql_select_db( self::$config['mysql']['db'], self::$db );
		// connect to mysql
		
		foreach ( self::$config['networks'] as $network => $net_data )
			self::debug( 'connecting to ('.$network.':'.$net_data['port'].') as '.$net_data['nick'] );
		$this->xbot->connect( self::$config );
		self::debug( 'connected to networks' );
		// connect the bot
		
		$this->xbot->timer->add( array( 'bot', 'listen_data', array( $this->xbot ) ), 5, 0 );
		$this->xbot->timer->add( array( 'bot', 'get_new_data', array( $this->xbot ) ), self::$config['options']['new_data_interval'], 0 );
		// set up some timers, we only actually go hunting for new data every 30 seconds, then if new data is found its stored
		// the stored data is checked by listen_data every 5 seconds. We only check every 30 seconds because for huge repos like
		// rails/rails, which I developed this on it can be quite intensive, and plus the more often we check the quicker
		// we can run out of api calls (unless you get on the whitelist)
		$this->xbot->main( 'bot', 'main' );
		// boot the main loop w/ a callback
	}

	/*
	* main
	*
	* @params
	* $xbot - object passed from xbot::main()
	* $ircdata - object passed from xbot::main()
	*/
	public function main( $xbot, $ircdata )
	{
		if ( $xbot->events->on_connect( $xbot->ircdata ) )
		{
			$git_chans_q = mysql_query( "SELECT `id`, `repo`, `chan` FROM `".self::$config['mysql']['table_c']."`" );
			while ( $git_chans = mysql_fetch_array( $git_chans_q ) )
			{
				$chan = explode( '/', $git_chans['chan'] );
				self::$config['networks'][$chan[0]]['chans'][$chan[1]] = '';
				self::$config['repos'][$git_chans['repo']] = array( $chan[0], $chan[1] );
				$xbot->join( $chan[0], $chan[1] );
				// join channel and set some variables
				
				self::debug( 'tracking '.$git_chans['repo'].' in '.$git_chans['chan'] );
				// debug the fact we're tracking it
				
				mysql_query( "UPDATE `".self::$config['mysql']['table_c']."` SET `empty` = '1' WHERE `id` = '".$git_chans['id']."'" );
				self::get_new_data( $xbot );
				// re-scan repos so we don't get huge spams
			}
			// grab gitchans
		}
		
		if ( $ircdata->type == 'privmsg' && $ircdata->target == self::$config['networks'][$ircdata->from]['nick'] && $ircdata->message[0] == ':' )
		{
			$message = substr( $ircdata->message, 1 );
			$messages = explode( ' ', $message );
			// parse up message
		
			if ( strcasecmp( $messages[0], 'track' ) == 0 || strcasecmp( $messages[0], 'untrack' ) == 0 )
			{
				$req = strtolower( $messages[0] );
				if ( !in_array( $ircdata->host, self::$config['admin_hosts'] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['access_denied'] );
					return false;
				}
				// they don't have access to use this command.
			
				if ( ( $req == 'track' && ( count( $messages ) < 3 || substr_count( $messages[1], '/' ) == 0 || substr_count( $messages[2], '/' ) == 0 ) ) || 
					 ( $req == 'untrack' && ( count( $messages ) < 2 || substr_count( $messages[1], '/' ) == 0 ) ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages'][$req.'_syntax'] );
					return false;
				}
				// invalid syntax
				
				$repo = $messages[1];
				$info = $messages[2];
				$info_a = explode( '/', $info );
				// TODO: currently we don't check if $repo is a valid git repo, which may cause hassle
				//       we'll check it soon, cba atm. 
				
				if ( $req == 'track' && isset( self::$config['repos'][$repo] ) ||
					 ( $req == 'untrack' && !isset( self::$config['repos'][$repo] ) ) )
				{
					$msg = ( $req == 'track' ) ? self::$config['error_messages']['already_got_repo'] : self::$config['error_messages']['invalid_repo'];
					$xbot->notice( $ircdata->from, $ircdata->nick, $msg );
					return false;
				}
				// we're already tracking that repo, silly git (no pun intended!)
				
				if ( $req == 'track' && !isset( self::$config['networks'][$info_a[0]] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['track_nonet'] );
					return false;
				}
				// are we connected to network?
				
				if ( $req == 'track' )
				{
					mysql_query( "INSERT INTO `".self::$config['mysql']['table_c']."` (`repo`, `chan`, `empty`) VALUES('".$repo."', '".$info."', '1')" );
					
					$search = array( '{repo}', '{chan}' );
					$replace = array( $repo, $info );
					$xbot->notice( $ircdata->from, $ircdata->nick, str_replace( $search, $replace, self::$config['error_messages']['track_new_repo_1'] ) );
					if ( !isset( self::$config['networks'][$info_a[0]][$info_a[1]] ) )
					{
						$xbot->join( $info_a[0], $info_a[1] );
						self::$config['networks'][$info_a[0]]['chans'][$info_a[1]] = '';
					}
					// everything seems to be good! let's join the channel
					
					self::$config['repos'][$repo] = array( $info_a[0], $info_a[1] );
					self::get_new_data( $xbot );
					// rescan to get the rest
					
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['track_new_repo_2'] );
					// we're finished, giggidy
					
					self::debug( $ircdata->nick.' used :track (repo: '.$repo.'; chan: '.$info_a[0].'/'.$info_a[1].')' );
				}
				// track command
				else
				{
					$info = self::$config['repos'][$repo];
					mysql_query( "DELETE FROM `".self::$config['mysql']['table_c']."` WHERE `repo` = '".$repo."'" );
					mysql_query( "DELETE FROM `".self::$config['mysql']['table_i']."` WHERE `repo` = '".$repo."'" );
					
					$used_elsewhere = false;
					foreach ( self::$config['repos'] as $repo_id => $chan_id )
					{
						if ( $repo_id == $repo )
							continue;
						if ( $chan_id[0] == $info[0] && $chan_id[1] == $info[1] )
							$used_elsewhere = true;
					}
					// everything seems to be good! let's start tracking buddy.
					
					if ( !$used_elsewhere )
					{
						$xbot->part( $info[0], $info[1] );
						unset( self::$config['networks'][$info[0]]['chans'][$info[1]] );
					}
					// it isn't used elsewhere let's go.
					
					$xbot->notice( $ircdata->from, $ircdata->nick, str_replace( '{repo}', $repo, self::$config['error_messages']['untrack_repo'] ) );
					unset( self::$config['repos'][$repo] );
					
					self::debug( $ircdata->nick.' used :untrack (repo: '.$repo.'; chan: '.$info[0].'/'.$info[1].')' );
				}
			}
			// (un)track command
			
			if ( strcasecmp( $messages[0], 'join' ) == 0 || strcasecmp( $messages[0], 'part' ) == 0 )
			{
				$req = strtolower( $messages[0] );
				if ( !in_array( $ircdata->host, self::$config['admin_hosts'] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['access_denied'] );
					return false;
				}
				// they don't have access to use this command.
				
				if ( count( $messages ) < 2 || substr_count( $messages[1], '/' ) == 0 )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages'][$req.'_syntax'] );
					return false;
				}
				// invalid syntax
				
				$info = $messages[1];
				$info_a = explode( '/', $info );
				
				if ( !isset( self::$config['networks'][$info_a[0]] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['track_nonet'] );
					return false;
				}
				// are we connected to network?
				
				if ( $req == 'join' )
				{
					if ( !isset( self::$config['networks'][$info_a[0]]['chans'][$info_a[1]] ) )
					{
						$xbot->join( $info_a[0], $info_a[1] );
						self::$config['networks'][$info_a[0]]['chans'][$info_a[1]] = '';
					}
					// we're good, let's idle!
					else
					{
						$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['join_already_in'] );
						return false;
					}
					// we're already in network/#channel
					
					self::debug( $ircdata->nick.' used :join (chan: '.$info.')' );
				}
				// :join
				else
				{
					if ( !isset( self::$config['networks'][$info_a[0]]['chans'][$info_a[1]] ) )
					{
						$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['part_not_in'] );
						return false;
					}
					// we're not even in the channel AT ALL.
				
					$tracking_in_chan = false;
					foreach ( self::$config['repos'] as $repo_id => $chan_id )
					{
						if ( $chan_id[0] == $info_a[0] && $chan_id[1] == $info_a[1] )
							$tracking_in_chan = true;
					}
					// find out if we're tracking here or not.
					
					if ( $tracking_in_chan )
					{
						$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['part_already_track'] );
						return false;
					}
					else
					{
						$xbot->part( $info_a[0], $info_a[1] );
						unset( self::$config['networks'][$info_a[0]]['chans'][$info_a[1]] );
					}
					// leave the chan, or tell them we can't
					
					self::debug( $ircdata->nick.' used :part (chan: '.$info.')' );
				}
				// :part
			}
			// join && part commands
		}
		// admin only commands
		
		if ( $ircdata->type == 'privmsg' && $ircdata->message[0] == ':' )
		{
			$message = substr( $ircdata->message, 1 );
			$messages = explode( ' ', $message );
			// parse up message
			
			if ( strcasecmp( $messages[0], 'help' ) == 0 )
			{
				foreach ( self::$config['error_messages']['help_messages'] as $i => $line )
					$xbot->notice( $ircdata->from, $ircdata->nick, $line );
				if ( in_array( $ircdata->host, self::$config['admin_hosts'] ) )
				{
					foreach ( self::$config['error_messages']['admin_help_msgs'] as $i => $line )
						$xbot->notice( $ircdata->from, $ircdata->nick, $line );
				}
			}
			// help message
			
			if ( strcasecmp( $messages[0], 'commit' ) == 0 )
			{
				if ( count( $messages ) < 3 || substr_count( $messages[1], '/' ) == 0 )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['commits_syntax'] );
					return false;
				}
				// invalid syntax, this time we notify them unlike samantha :3
				
				$repo = $messages[1];
				$id = $messages[2];
				$repo_a = explode( '/', $repo );
				// get our vars, and remove the # from id
				
				if ( !isset( self::$config['repos'][$repo] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['invalid_repo'] );
					return false;
				}
				// invalid repo
				
				$call = 'https://api.github.com/repos/'.$repo.'/commits/'.$id;
				$payload = self::call_api( $call );
				
				if ( strtolower( $payload['message'] ) == 'not found' )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['commits_noid'] );
					return false;
				}
				// invalid commit id - GAME OVER.
				
				$payload = $payload['commit'];
				$payload['message'] = preg_replace( '/\s\s+/', ' ', $payload['message'] );
				$msg = self::$config['unparsed_responses']['commit'];
				
				$search = array(
					'{repo}', '{user}', '{date}', '{title}', '{url}'
				);
				
				$replace = array(
					$repo_a[1],
					$payload['committer']['name'],
					date( 'd/m/Y H:i', strtotime( $payload['committer']['date'] ) ),
					( strlen( $payload['message'] ) > 150 ) ? substr( $payload['message'], 0, 150 ).'..' : $payload['message'],
					'https://github.com/'.$repo.'/commit/'.$id
				);
				// compile a list of shite.
				
				$msg = str_replace( $search, $replace, $msg );
				// compile a message
				
				$xbot->msg( $ircdata->from, $ircdata->target, $msg );
				// ok we've found a commit everything is good let's parse some stuff out of our json and fire it back <3
				
				self::debug( $ircdata->nick.' used :commit (repo: '.$repo.'; id: '.$id.')' );
			}
			// someone has asked for a commit..
			
			if ( strcasecmp( $messages[0], 'issue' ) == 0 )
			{
				$req = strtolower( $messages[0] );
				if ( count( $messages ) < 3 || substr_count( $messages[1], '/' ) == 0 || $messages[2][0] != '#' )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['issues_syntax'] );
					return false;
				}
				// invalid syntax
				
				$repo = $messages[1];
				$id = substr( $messages[2], 1 );
				$repo_a = explode( '/', $repo );
				// get our vars, and remove the # from id
				
				if ( !isset( self::$config['repos'][$repo] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['invalid_repo'] );
					return false;
				}
				// invalid repo
				
				$call = 'https://api.github.com/repos/'.$repo.'/issues/'.$id;
				$payload = self::call_api( $call );
				
				if ( strtolower( $payload['message'] ) == 'not found' )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['issues_noid'] );
					return false;
				}
				// invalid issue id
				
				$type = 'issue';
				if ( $payload['pull_request']['html_url'] != null )
				{
					$pcall = 'https://api.github.com/repos/'.$repo.'/pulls/'.$id;
					$pdata = self::call_api( $pcall );
					// if we've gotten to here it means we can't find the issue, so let's find it.
					
					$payload['head']['label'] = $pdata['head']['label'];
					$payload['base']['label'] = $pdata['base']['label'];
					$payload['merged'] = $pdata['merged'];
				}
				// check if it's a pull request
				
				$payload['body'] = preg_replace( '/\s\s+/', ' ', $payload['body'] );
				$msg = ( isset( $payload['merged'] ) ) ? self::$config['unparsed_responses']['pull'] : self::$config['unparsed_responses']['issue'];
				
				$search = array(
					'{repo}', '{user}', '{id}', '{head_label}', '{base_label}', '{date}', '{colour}', '{state}', '{plural}', '{comments}', '{title}', '{message}', '{url}'
				);
				
				$replace = array(
					$repo_a[1],
					$payload['user']['login'],
					$id,
					$payload['head']['label'],
					$payload['base']['label'],
					date( 'd/m/Y H:i', strtotime( $payload['created_at'] ) ),
					( $payload['merged'] || $payload['state'] == 'open' ) ? '3' : '4',
					( $payload['merged'] ) ? 'merged' : $payload['state'],
					( $payload['comments'] == 1 ) ? 'comment' : 'comments',
					$payload['comments'],
					( strlen( $payload['title'] ) > 50 ) ? substr( $payload['title'], 0, 50 ).'..' : $payload['title'],
					( strlen( $payload['body'] ) > 150 ) ? substr( $payload['body'], 0, 150 ).'..' : $payload['body'],
					( $req == 'issues' ) ? 'https://github.com/'.$repo.'/issues/'.$id : $payload['html_url']
				);
				// compile a list of search and replaces!
				
				$msg = str_replace( $search, $replace, $msg );
				
				$xbot->msg( $ircdata->from, $ircdata->target, $msg );
				// ok we've found a commit everything is good let's parse some stuff out of our json and fire it back <3
				
				self::debug( $ircdata->nick.' used :issue (repo: '.$repo.'; id: '.$id.')' );
			}
			// someone has asked for an issue OR pull..
		}
		// look for prefix'd messages
	}

	/*
	* listen_data
	*
	* @params
	* void
	*/
	static public function listen_data( $xbot )
	{
		$get_new_data_q = mysql_query( "SELECT `id`, `timestamp`, `payload`, `read`, `type` FROM `".self::$config['mysql']['table_p']."` WHERE `read` = '0'" );
		$num_new_data = mysql_num_rows( $get_new_data_q );
		// find new data
	
		if ( $num_new_data > 0 )
		{
			while ( $data = mysql_fetch_array( $get_new_data_q ) )
			{
				$payload = json_decode( $data['payload'], true );
				
				if ( $data['type'] == 'commit' && self::$config['options']['track_commits'] )
					self::parse_commits( $xbot, $repo, $payload );
				if ( $data['type'] == 'events' )
					self::parse_events( $xbot, $payload );
				if ( $data['type'] == 'issues' )
					self::parse_issues( $xbot, $payload );
				if ( $data['type'] == 'comments' )
					self::parse_comments( $xbot, $payload );
				// parse commits
				
				mysql_query( "DELETE FROM `".self::$config['mysql']['table_p']."` WHERE `id` = '".$data['id']."'" );
				// mark it as read
			}
		}
		// if we have new data, work with it
	}
	
	/*
	* get_new_data
	*
	* @params
	* $xbot < xbot object
	*/
	static public function get_new_data( $xbot )
	{
		$git_chans_q = mysql_query( "SELECT `id`, `repo`, `chan`, `empty` FROM `".self::$config['mysql']['table_c']."`" );
		while ( $git_chans = mysql_fetch_array( $git_chans_q ) )
		{
			self::debug( 'scanning '.$git_chans['repo'] );
			
			if ( $git_chans['empty'] == 1 )
				mysql_query( "UPDATE `".self::$config['mysql']['table_c']."` SET `empty` = '0' WHERE `id` = '".$git_chans['id']."'" );
			// set the channel as not-empty.. make sense?
			
			$repo_a = explode( '/', $git_chans['repo'] );
			
			$call = 'http://github.com/api/v2/json/issues/list/'.$git_chans['repo'].'/open';
			$data = self::call_api( $call );
			$id = 'issues';
			
			if ( !is_array( $data[$id] ) )
				continue;
			// this will fix that ANNOYING AS FUCK bug where we sometimes get invalid data
			// in $data[$id] about 10 lines below this, we're just assuming that we're always
			// going to recieve data (even when we might be past our api usage limit).
			
			$changed = $comments = $events = 0;
			// find out how many are actually new, if none are we don't bother inserting into
			// our post recieve data table or we fast end up with a HUGE table.
			
			$git_reps_q = mysql_query( "SELECT `id`, `repo`, `info_id`, `comments`, `type`, `closed` FROM `".self::$config['mysql']['table_i']."` WHERE `repo` = '".$git_chans['repo']."'" );
			$num_rows = mysql_num_rows( $git_reps_q );
			
			$sorting_lambda = function( $a, $b ) { return strcmp( $a['number'], $b['number'] ); };
			usort( $data[$id], $sorting_lambda );
			// sort our data array, by number, lambda :3.
			
			while ( $rows = mysql_fetch_array( $git_reps_q ) )
			{
				$found = false;
				foreach ( $data[$id] as $ird => $rd )
				{		
					if ( $rows['info_id'] == $rd['number'] )
					{
						$found = true;
						break;
					}
				}
				// find a match!
				
				if ( $found && $rows['closed'] == 0 )
				{
					unset( $data[$id][$ird] );
				}
				// we've found it, which means we've got it, which means, we DON'T need it
				elseif ( !$found || ( $found && $rows['closed'] == 1 ) )
				{
					if ( !$found && $rows['closed'] == 1 )
						continue;
					// if we havent found it, which means it's actually closed, and we already have it marked as closed, lets bail
					
					$ecall = 'https://api.github.com/repos/'.$git_chans['repo'].'/issues/'.$rows['info_id'].'/events';
					$edata = self::call_api( $ecall );
					// look for events if we've got this far
					
					$edata = array_reverse( $edata );
					foreach ( $edata as $edata_id => $edata_array )
					{
						if ( !$found && ( $edata_array['event'] == 'closed' && $rows['closed'] == 0 ) && self::$config['options']['track_closes'] )
						{
							if ( $rows['closed'] == 1 )
								break;
							// we shouldn't have gotten here, but we sometimes do, not sure why, this is a fix anyway.
								
							mysql_query( "UPDATE `".self::$config['mysql']['table_i']."` SET `closed` = '1' WHERE `id` = '".$rows['id']."'" );
							// mark it as closed so we don't get here again
						
							$icall = 'https://api.github.com/repos/'.$git_chans['repo'].'/issues/'.$rows['info_id'];
							$idata = self::call_api( $icall );
							// if we've gotten to here it means we can't find the issue, so let's find it.
							
							$nedata = $edata[$edata_id];
							$nedata['repo'] = $git_chans['repo'];
							$nedata['number'] = $idata['number'];
							$nedata['title'] = $idata['title'];
							$nedata['body'] = $idata['body'];
							$nedata['updated_at'] = $idata['closed_at'];
							$nedata['html_url'] = $idata['html_url'];
							$new_edata['events'][] = $nedata;
							
							$events++;
							// just so we know something has changed
							break;
						}
						// this will explain why we can't find it.
						
						elseif ( ( $found && $rows['closed'] == 1 ) && 
								 ( $edata_array['event'] == 'reopened' && $rows['closed'] == 1 ) && self::$config['options']['track_reopens'] )
						{
							if ( $rows['closed'] == 0 )
								break;
							// again. shouldn't be here
						
							mysql_query( "UPDATE `".self::$config['mysql']['table_i']."` SET `closed` = '0' WHERE `id` = '".$rows['id']."'" );
							// mark it as closed so we don't get here again
							
							$nedata = $edata[$edata_id];
							$nedata['repo'] = $git_chans['repo'];
							$nedata['number'] = $rd['number'];
							$nedata['title'] = $rd['title'];
							$nedata['body'] = $rd['body'];
							$nedata['updated_at'] = $rd['updated_at'];
							$nedata['html_url'] = $rd['html_url'];
							$new_edata['events'][] = $nedata;
							
							$events++;
							// just so we know something has changed
							
							unset( $data[$id][$ird] );
							// remove it from $data, to prevent it blurting REOPENED issue, OPENED issue
							break;
						}
						// this will explain we can find a record thats been previously marked as closed, it's open again!
						
						elseif ( ( $edata_array['event'] == 'merged' && $rows['closed'] == 0 ) && self::$config['options']['track_merges'] )
						{
							$icall = 'https://api.github.com/repos/'.$git_chans['repo'].'/pulls/'.$rows['info_id'];
							$idata = self::call_api( $icall );
							// if we've gotten to here it means we can't find the issue, so let's find it.
						
							$nedata = $edata[$edata_id];
							$nedata['head_label'] = $idata['head']['label'];
							$nedata['base_label'] = $idata['base']['label'];
							$nedata['commits'] = $idata['commits'];
							$nedata['repo'] = $git_chans['repo'];
							$nedata['number'] = $idata['number'];
							$nedata['title'] = $idata['title'];
							$nedata['body'] = $idata['body'];
							$nedata['updated_at'] = $idata['merged_at'];
							$nedata['html_url'] = $idata['html_url'];
							$new_edata['events'][] = $medata;
							
							$events++;
							// just so we know something has changed
							break;
						}
						// here we just look for merges in general, ON open issues/pulls
					}
					// we're specifically only looking for a few events, "closed", "reopened" etc.
					
					// let's have a look for some events, we can only do this in the v3 library
					// so for now we shall
					// TODO: I plan on using V3 for everything else soon. once it all works.
					//      and if I can get unlimited api use.
				}
				// we haven't found it, which means it could be deleted?
				
				if ( ( $found && ( $rows['comments'] < $rd['comments'] ) ) && self::$config['options']['track_comments'] )
				{
					mysql_query( "UPDATE `".self::$config['mysql']['table_i']."` SET `comments` = '".$rd['comments']."' WHERE `id` = '".$rows['id']."'" );
					// update our new comment limit
					
					$ccall = 'https://api.github.com/repos/'.$git_chans['repo'].'/issues/'.$rows['info_id'].'/comments';
					$cedata = self::call_api( $ccall );
					
					$cdata['comments'] = array_slice( $cedata, $rows['comments'] );
					$cdata['repo'] = $git_chans['repo'];
					$cdata['repo_id'] = $rows['info_id'];
					$cdata['issue_title'] = $rd['title'];
					$cdata['type'] = ( isset( $rd['pull_request_url'] ) ) ? 'pull' : 'issues';
					
					$comments++;
					// we need to find out which comments are new.
				}
				// means we have new comments!
			}
			// look for comment changes & other crap, see large comment above.
			
			foreach ( $data[$id] as $i => $rep )
			{
				$dont_set = false;
				
				if ( $git_chans['empty'] == 0 && isset( $rep['pull_request_url'] ) )
				{
					$ncall = 'https://github.com/api/v2/json/pulls/'.$git_chans['repo'].'/'.$rep['number'];
					$ndata = self::call_api( $ncall );
					unset( $data[$id][$i] );
					$data[$id][$i] = $ndata['pull'];
				}
				// check if the issue has a pull_request_url, this will stop the following from happening
				// n0valyfe has opened issue #2048
				// n0valyfe has requested to merge ..
				// ^ these happen at the same time when someone opens a pull request
				
				if ( $git_chans['empty'] == 0 && strtotime( $rep['created_at'] ) != strtotime( $rep['updated_at'] ) )
				{
					$ecall = 'https://api.github.com/repos/'.$git_chans['repo'].'/issues/'.$rd['number'].'/events';
					$edata = self::call_api( $ecall );
					// if we've gotten to here it means we can't find the issue, so let's find it.
					
					$edata = array_reverse( $edata );
					foreach ( $edata as $edata_id => $edata_array )
					{
						if ( $edata_array['event'] == 'reopened' )
						{
							$nedata = $edata[$edata_id];
							$nedata['repo'] = $git_chans['repo'];
							$nedata['number'] = $rep['number'];
							$nedata['title'] = $rep['title'];
							$nedata['body'] = $rep['body'];
							$nedata['updated_at'] = $rep['updated_at'];
							$nedata['html_url'] = $rep['html_url'];
							$new_edata['events'][] = $nedata;
							// set a few thingybobs
						
							unset( $data[$id][$i] );
							$dont_set = true;
							$events++;
							break;
							// set some crap and break
						}
						// if we've got this far we've found what we're looking for, YEAH BUDDY.
					}
					// find out if it's been reopened before
				}
				// if the updated time is different to the created time, it *COULD* have been reopened
				// if we didn't have it before, so we would just see it as being opened, when really its
				// being reopened, its just that it was closed when we scanned for issues. :)
				
				$state = ( $rep['state'] == 'open' ) ? 0 : 1;
				$data[$id][$i]['repo'] = $git_chans['repo'];
				$data[$id][$i]['type'] = isset( $rep['pull_request_url'] ) ? 'pull' : 'issue';
				$rid = ( isset( $rep['pull_request_url'] ) ) ? 'pulls' : 'issues';
				if ( !$dont_set ) $changed++;
				
				mysql_query( "INSERT INTO `".self::$config['mysql']['table_i']."` (`type`, `repo`, `info_id`, `timestamp`, `comments`, `closed`) VALUES('".$rid."', '".$git_chans['repo']."', '".$rep['number']."', '".strtotime( $rep['created_at'] )."', '".$rep['comments']."', '".$state."')" );
				// check if the issue is new or not, if it is insert it into our issue table so
				// we don't recognise it again, and also pass info into our post_recieve table
			}
			
			if ( $git_chans['empty'] == 0 && ( $comments > 0 ) )
			{
				mysql_query( "INSERT INTO `".self::$config['mysql']['table_p']."` (`timestamp`, `payload`, `read`, `type`) VALUES('".time()."', '".addslashes( json_encode( $cdata ) )."', '0', 'comments')" );
			}
			// we have changed records!
			
			if ( $git_chans['empty'] == 0 && ( $events > 0 ) )
			{
				mysql_query( "INSERT INTO `".self::$config['mysql']['table_p']."` (`timestamp`, `payload`, `read`, `type`) VALUES('".time()."', '".addslashes( json_encode( $new_edata ) )."', '0', 'events')" );
			}
			// we have more changed records.
			
			if ( ( $git_chans['empty'] == 0 && ( $changed > 0 ) ) && self::$config['options']['track_new_issues'] )
			{
				mysql_query( "INSERT INTO `".self::$config['mysql']['table_p']."` (`timestamp`, `payload`, `read`, `type`) VALUES('".time()."', '".addslashes( json_encode( $data ) )."', '0', '".$id."')" );
			}
			// we have changed records!
		}
		// loop through repos
	}
	
	/*
	* parse_commits
	*
	* @params
	* $xbot < object, $repo < valid repo ie ircnode/acorairc, $payload < valid github json
	*/
	static public function parse_commits( $xbot, $repo, $payload )
	{
		$repo = $payload['repository']['owner']['name'] . '/' . $payload['repository']['name'];
		$commits = count( $payload['commits'] );
		if ( count( $commits ) == 0 )
			return;
		// let's go!
		
		foreach ( $payload['commits'] as $id => $commit )
		{
			$commit['message'] = preg_replace( '/\s\s+/', ' ', $commit['message'] );
			$msg = self::$config['unparsed_responses']['commits'];
			$search = array(
				'{repo}', '{user}', '{branch}', '{revision}', '{files}', '{title}', '{url}'
			);
			
			$replace = array(
				$payload['repository']['name'],
				$payload['author']['name'],
				( !isset( $payload['repository']['integrate_branch'] ) ) ? 'master' : $payload['repository']['integrate_branch'],
				substr( $commit['id'], 0, 7 ),
				( count( $commit['modified'] ) > 3 ) ? count( $commit['modified'] ) . ' files' : implode( ' ', $commit['modified'] ),
				( strlen( $commit['message'] ) > 150 ) ? substr( $commit['message'], 0, 150 ).'..' : $commit['message'],
				$commit['url']
			);
			
			$msg = str_replace( $search, $replace, $msg );
			// compile a message
			
			$net = self::$config['repos'][$repo];
			// commit channel
			
			$xbot->msg( $net[0], $net[1], $msg );
			
			if ( $commits > 4 )
				usleep( 500000 );
		}
		// foreach commits.
		
		// output info like CIA does:
		// acorairc: Ricki Hastings dev * r4f9c0c6 / core/core.php : Rewrote core::alog(), a bit neater now and hopefully a bit mor efficient, it was really bugging me how messy it was (http://bit.ly/pg69lW)
	}
	
	/*
	* parse_events
	*
	* @params
	* $xbot < object, $payload < valid github json
	*/
	static public function parse_events( $xbot, $payload )
	{
		$events = count( $payload['events'] );
		if ( $events == 0 )
			return;
		// let's go!
	
		foreach ( $payload['events'] as $id => $event )
		{
			$repo = $event['repo'];
			$repo_a = explode( '/', $repo );
			$event['body'] = preg_replace( '/\s\s+/', ' ', $event['body'] );
			
			if ( $event['event'] == ( 'reopened' || 'closed' ) )
			{
				$msg = self::$config['unparsed_responses']['issues'];
				$search = array(
					'{repo}', '{user}', '{colour}', '{plural}', '{id}', '{date}', '{title}', '{message}', '{url}'
				);
				
				$colour = ( $event['event'] == 'reopened' ) ? '3' : '4';
				
				$replace = array(
					$repo_a[1],
					$event['actor']['login'],
					$colour,
					$event['event'],
					$event['number'],
					date( 'd/m/Y H:i', strtotime( $event['updated_at'] ) ),
					( strlen( $event['title'] ) > 50 ) ? substr( $event['title'], 0, 50 ).'..' : $event['title'],
					( strlen( $event['body'] ) > 150 ) ? substr( $event['body'], 0, 150 ).'..' : $event['body'],
					$event['html_url']
				);
				// parse it up and send it out
			}
			// if the event is a reopen or close do this stuff
			else
			{
				$msg = self::$config['unparsed_responses']['merges'];
				$search = array(
					'{repo}', '{user}', '{commits}', '{plural}', '{head_label}', '{base_label}', '{date}', '{title}', '{message}', '{url}'
				);
				
				$replace = array(
					$repo_a[1],
					$event['actor']['login'],
					$event['commits'],
					( $event['commits'] == 1 ) ? 'commit' : 'commits',
					$event['head_label'],
					$event['base_label'],
					date( 'd/m/Y H:i', strtotime( $event['updated_at'] ) ),
					( strlen( $event['title'] ) > 50 ) ? substr( $event['title'], 0, 50 ).'..' : $event['title'],
					( strlen( $event['body'] ) > 150 ) ? substr( $event['body'], 0, 150 ).'..' : $event['body'],
					$event['html_url']
				);
				// parse it up and send it out
			}
			// it's a merge!
			
			$msg = str_replace( $search, $replace, $msg );
			// compile a message
			
			$net = self::$config['repos'][$repo];
			// commit channel
			
			$xbot->msg( $net[0], $net[1], $msg );
			
			if ( $events > 4 )
				usleep( 500000 );
		}
	}
	
	/*
	* parse_issues
	*
	* @params
	* $xbot < object, $payload < valid github json
	*/
	static public function parse_issues( $xbot, $payload )
	{
		$issues = count( $payload['issues'] );
		if ( $issues == 0 )
			return;
		// let's go!
	
		foreach ( $payload['issues'] as $id => $issue )
		{
			$repo = $issue['repo'];
			$repo_a = explode( '/', $repo );
			$issue['body'] = preg_replace( '/\s\s+/', ' ', $issue['body'] );
			
			if ( $issue['type'] == 'issue' )
			{
				$msg = self::$config['unparsed_responses']['issues'];
				$search = array(
					'{repo}', '{user}', '{colour}', '{plural}', '{id}', '{date}', '{title}', '{message}', '{url}'
				);
				
				$replace = array(
					$repo_a[1],
					$issue['user'],
					'3',
					'opened',
					$issue['number'],
					date( 'd/m/Y H:i', strtotime( $issue['created_at'] ) ),
					( strlen( $issue['title'] ) > 50 ) ? substr( $issue['title'], 0, 50 ).'..' : $issue['title'],
					( strlen( $issue['body'] ) > 150 ) ? substr( $issue['body'], 0, 150 ).'..' : $issue['body'],
					$issue['html_url']
				);
			}
			// it's an issue, parse it this way
			else
			{
				$msg = self::$config['unparsed_responses']['pulls'];
				$search = array(
					'{repo}', '{user}', '{head_label}', '{base_label}', '{title}', '{message}', '{url}'
				);
				
				$replace = array(
					$repo_a[1],
					$issue['user']['login'],
					$issue['head']['label'],
					$issue['base']['label'],
					( strlen( $issue['title'] ) > 50 ) ? substr( $issue['title'], 0, 50 ).'..' : $issue['title'],
					( strlen( $issue['body'] ) > 150 ) ? substr( $issue['body'], 0, 150 ).'..' : $issue['body'],
					$issue['html_url']
				);
			}
			// it's a pull request, parse it differently :3
			
			$msg = str_replace( $search, $replace, $msg );
			// compile a message
			
			$net = self::$config['repos'][$repo];
			// commit channel
			
			$xbot->msg( $net[0], $net[1], $msg );
			
			if ( $issues > 4 )
				usleep( 500000 );
		}
		// foreach issues.
		
		// output info like so:
		// acorairc: n0valyfe opened an issue at (2011/07/07 18:53:09 -0700): Issue title - Issue body this is a lump of text etc (https://github.com/ircnode/acorairc/issues/33)
	}
	
	/*
	* parse_comments
	*
	* @params
	* $xbot < object, $payload < valid github json
	*/
	static public function parse_comments( $xbot, $payload )
	{
		$comments = count( $payload['comments'] );
		if ( count( $comments ) == 0 )
			return;
		// let's go!
		
		foreach ( $payload['comments'] as $id => $comment )
		{
			$repo = $payload['repo'];
			$repo_a = explode( '/', $repo );
			
			$comment['body'] = preg_replace( '/\s\s+/', ' ', $comment['body'] );
			$msg = self::$config['unparsed_responses']['comments'];
			$search = array(
				'{repo}', '{user}', '{title}', '{id}', '{message}', '{url}'
			);
			
			$replace = array(
				$repo_a[1],
				$comment['user']['login'],
				( strlen( $payload['issue_title'] ) > 50 ) ? substr( $payload['issue_title'], 0, 50 ).'..' : $payload['issue_title'],
				$payload['repo_id'],
				( strlen( $comment['body'] ) > 150 ) ? substr( $comment['body'], 0, 150 ).'..' : $comment['body'],
				'https://github.com/'.$repo.'/'.$payload['type'].'/'.$payload['repo_id'].'#issuecomment-'.$comment['id']
			);
			
			$msg = str_replace( $search, $replace, $msg );
			// compile a message
			
			$net = self::$config['repos'][$repo];
			// commit channel
			
			$xbot->msg( $net[0], $net[1], $msg );
			
			if ( $comments > 4 )
				usleep( 500000 );
		}
		// foreach comments.
		
		// output info like so:
		// acorairc: n0valyfe has commented on (Issue title): Comment body blah blah (https://github.com/facebook/hiphop-php/issues/370#issuecomment-1546438)
	}
	
	/*
	* call_api (not used for recursive calls)
	*
	* @params
	* $url < url to call
	*/
	static public function call_api( $url )
	{
		$curl_handle = curl_init();
		// init a curl handler
		
		$options = array(
			CURLOPT_URL 			=> $url,
			CURLOPT_HEADER 			=> false,
			CURLOPT_HTTPGET			=> true,
			CURLOPT_RETURNTRANSFER 	=> true,
			CURLOPT_SSL_VERIFYPEER	=> false,
			CURLINFO_HEADER_OUT		=> true,
			CURLOPT_BUFFERSIZE		=> 64000
		);

		curl_setopt_array( $curl_handle, $options );
		$rdata = curl_exec( $curl_handle );
		$info = curl_getinfo( $curl_handle );
		// exec and get header info
		
		self::debug( 'calling: '.$url );
		
		curl_close( $curl_handle );
		++self::$api_calls;
		// set options, execute the call, close the handler, note how many calls we've made.
		
		return json_decode( preg_replace( '/\s\s+/', ' ', $rdata ), true );
	}
	
	/*
	* debug
	*
	* @params
	* $msg < debug message
	*/
	static public function debug( $msg )
	{
		if ( self::$debug )
			print '[' . date( 'd:m:Y H:i:s', time() ) . '] ' . $msg . "\r\n";
	}
}

// EOF;