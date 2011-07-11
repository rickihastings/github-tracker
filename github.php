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

$bot = new bot;

class bot
{

	public $xbot;
	static public $config;
	static public $quiet = false;
	static public $db;
	static public $queries = 0;
	static public $buffer = array();

	static public $sock;
	static public $client = array();
	// give $xbot framework a var; so we can use it all around the class.

	/*
	* __construct
	*
	* @params
	* void
	*/
	public function __construct()
	{
		$this->xbot = new xbot;
		// new xbot

		self::$config = array(
			'networks' 		=> array(
				'localhost'	=> array(
					'port' 			=> '6667',

					'nick' 			=> 'Github',
					'ident' 		=> 'github',
					'real' 			=> 'Github Tracker',
					'chans'			=> array(), // DO NOT USE.
				),
				// network one
			),
			
			'repos' 	=> array(
				//'ircnode/acorairc'	=> 'localhost/#acora',
			),
			// github repos now automatically populated from mysql
			
			'recursive_api_calls'	=> array(
				'issues' 	=> 'http://github.com/api/v2/json/issues/list/{user}/{repo}/open',
				'pulls'		=> 'http://github.com/api/v2/json/pulls/{user}/{repo}/open',
			),
			// recursive calls checked every x seconds
			
			'unparsed_responses'	=> array(
				'commits'	=> '{repo}: 6{user} 2{branch} * r{revision} / ({files}): {title} ({url})',
				'issues'	=> '{repo}: 6{user} opened an issue at ({date}): {title} - {message} ({url})',
				'pulls'		=> '{repo}: 6{user} has requested to merge 5{head_label} into 7{base_label}: {title} - {message} ({url})',
				'comments'	=> '{repo}: 6{user} has commented on ({title}): {message} ({url})',
				// unrequested responses, ie event based
				'commit'	=> '{repo}: commit added by 6{user} on ({date}): {title} ({url})',
				'issue'		=> '{repo}: #{id} opened by 6{user} on ({date}) ({comments} {plural}) ({colour}{state}): {title} - {message} ({url})',
				'pull'		=> '{repo}: pull request by 6{user} on ({date}) to merge 5{head_label} into 7{base_label} ({comments} {plural}) ({colour}{state}): {title} - {message} ({url})',
				// requested responses, ie commands
			),
			// unparsed messages
			
			'error_messages'		=> array(
				'help_messages'		=> array(
					'The following commands can be used.',
					':track user/repo network/#channel',
					':untrack user/repo',
					':commits user/repo id',
					':issues user/repo #id',
					':pulls user/repo #id',
				),
				'untrack_syntax'	=> 'Syntax is :untrack user/repo',
				// :untrack error messages
				'track_syntax'		=> 'Syntax is :track user/repo network/#channel',
				'track_nonet'		=> 'I\'m currently not connected to that network, you can connect me to it using my config array.',
				// :track error messages
				'commits_syntax'		=> 'Syntax is :commits user/repo id',
				'commits_noid'		=> 'Invalid commit id, make sure it is a full commit id in sha hash form.',
				// :commit error messages
				'issues_syntax'		=> 'Syntax is :issues user/repo #id',
				'issues_noid'		=> 'Invalid issue id, make sure it is a valid id, prefixed with a hash (#).',
				// :issue error messages
				'pulls_syntax'		=> 'Syntax is :pulls user/repo #id',
				'pulls_noid'			=> 'Invalid pull request id, make sure it is a valid id, prefixed with a hash (#).',
				// :pull error messages
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
			),
			// admin hosts
		);
		
		self::$db = mysql_connect( self::$config['mysql']['host'], self::$config['mysql']['user'], self::$config['mysql']['pass'] );
		mysql_select_db( self::$config['mysql']['db'], self::$db );
		// connect to mysql
		
		$this->xbot->connect( self::$config );
		// connect the bot
		
		$this->xbot->timer->add( array( 'bot', 'listen_data', array( $this->xbot ) ), 5, 0 );
		$this->xbot->timer->add( array( 'bot', 'get_new_data', array( $this->xbot ) ), 30, 0 );
		// set up some timers, we only actually go hunting for new data every 30 seconds, then if new data is found its stored
		// the stored data is checked by listen_data every 5 seconds. We only check every 30 seconds because for huge repos like
		// facebook's hiphop-php, which I developed this on it can be quite intensive, and plus the more often we check the quicker
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
			}
			// grab gitchans
		}
		
		if ( $ircdata->type == 'privmsg' && $ircdata->message[0] == ':' )
		{
			$message = substr( $ircdata->message, 1 );
			$messages = explode( ' ', $message );
			// parse up message
			
			if ( strcasecmp( $messages[0], 'help' ) == 0 )
			{
				foreach ( self::$config['error_messages']['help_messages'] as $i => $line )
					$xbot->notice( $ircdata->from, $ircdata->nick, $line );
			}
			// help message
			
			if ( strcasecmp( $messages[0], 'track' ) == 0 )
			{
				if ( !in_array( $ircdata->host, self::$config['admin_hosts'] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['access_denied'] );
					return false;
				}
				// they don't have access to use this command.
			
				if ( count( $messages ) < 3 || substr_count( $messages[1], '/' ) == 0 || substr_count( $messages[2], '/' ) == 0 )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['track_syntax'] );
					return false;
				}
				// invalid syntax
				
				$repo = $messages[1];
				$info = $messages[2];
				$info_a = explode( '/', $info );
				// TODO: currently we don't check if $repo is a valid git repo, which may cause hassle
				//       we'll check it soon, cba atm. 
				
				if ( isset( self::$config['repos'][$repo] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['already_got_repo'] );
					return false;
				}
				// we're already tracking that repo, silly git (no pun intended!)
				
				if ( !isset( self::$config['networks'][$info_a[0]] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['track_nonet'] );
					return false;
				}
				// are we connected to network?
				
				mysql_query( "INSERT INTO `".self::$config['mysql']['table_c']."` (`repo`, `chan`, `empty`) VALUES('".$repo."', '".$info."', '1')" );
				
				if ( !isset( self::$config['networks'][$info_a[0]][$info_a[1]] ) )
					$xbot->join( $info_a[0], $info_a[1] );
				// everything seems to be good! let's start tracking buddy.
				
				self::$config['repos'][$repo] = array( $info_a[0], $info_a[1] );
			}
			// track command
			
			if ( strcasecmp( $messages[0], 'untrack' ) == 0 )
			{
				if ( !in_array( $ircdata->host, self::$config['admin_hosts'] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['access_denied'] );
					return false;
				}
				// they don't have access to use this command.
				
				if ( count( $messages ) < 2 || substr_count( $messages[1], '/' ) == 0 )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['untrack_syntax'] );
					return false;
				}
				// invalid syntax
				
				$repo = $messages[1];
				
				if ( !isset( self::$config['repos'][$repo] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages']['invalid_repo'] );
					return false;
				}
				// we're not tracking this repo
				
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
					$xbot->part( $info[0], $info[1] );
				// it isn't used elsewhere let's go.
				
				unset( self::$config['repos'][$repo] );
			}
			// untrack command
			
			if ( strcasecmp( $messages[0], 'commits' ) == 0 )
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
				
				$payload = self::call_api( 'http://github.com/api/v2/json/commits/show/'.$repo.'/'.$id );
				
				if ( isset( $payload['error'] ) )
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
					date( 'd/m/Y i:H', strtotime( $payload['committed_date'] ) ),
					( strlen( $payload['message'] ) > 150 ) ? substr( $payload['message'], 0, 150 ).'..' : $payload['message'],
					'https://github.com/'.$payload['url']
				);
				// compile a list of shite.
				
				$msg = str_replace( $search, $replace, $msg );
				// compile a message
				
				$xbot->msg( $ircdata->from, $ircdata->target, $msg );
				// ok we've found a commit everything is good let's parse some stuff out of our json and fire it back <3
			}
			// someone has asked for a commit..
			
			if ( strcasecmp( $messages[0], 'issues' ) == 0 || strcasecmp( $messages[0], 'pulls' ) == 0 )
			{
				$req = $messages[0];
				if ( count( $messages ) < 3 || substr_count( $messages[1], '/' ) == 0 || $messages[2][0] != '#' )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages'][$req.'_syntax'] );
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
				
				$ext = ( $req == 'issues' ) ? '/show' : '';
				$payload = self::call_api( 'http://github.com/api/v2/json/'.$req.$ext.'/'.$repo.'/'.$id );
				
				if ( isset( $payload['error'] ) )
				{
					$xbot->notice( $ircdata->from, $ircdata->nick, self::$config['error_messages'][$req.'_noid'] );
					return false;
				}
				// invalid issue id
				
				$payload = ( $req == 'issues' ) ? $payload['issue'] : $payload['pull'];
				$payload['body'] = preg_replace( '/\s\s+/', ' ', $payload['body'] );
				$msg = self::$config['unparsed_responses'][substr( $req, 0, -1 )];
				
				$search = array(
					'{repo}', '{user}', '{id}', '{head_label}', '{base_label}', '{date}', '{colour}', '{state}', '{plural}', '{comments}', '{title}', '{message}', '{url}'
				);
				
				$replace = array(
					$repo_a[1],
					( $req == 'issues' ) ? $payload['user'] : $payload['user']['login'],
					$id,
					$payload['head']['label'],
					$payload['base']['label'],
					date( 'd/m/Y i:H', strtotime( $payload['created_at'] ) ),
					( $payload['state'] == 'open' ) ? '3' : '4',
					$payload['state'],
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
				$dpayload = stripslashes( $data['payload'] );
				$payload = json_decode( $dpayload, true );
				
				if ( $data['type'] == 'commit' )
					self::parse_commits( $xbot, $repo, $payload );
				if ( $data['type'] == 'issues' )
					self::parse_issues( $xbot, $payload );
				if ( $data['type'] == 'pulls' )
					self::parse_pulls( $xbot, $payload );
				if ( $data['type'] == 'comments' )
					self::parse_comments( $xbot, $payload );
				// parse commits
				
				mysql_query( "UPDATE `".self::$config['mysql']['table_p']."` SET `read` = '1' WHERE `id` = '".$data['id']."'" );
				// mark it as read
			}
		}
		// if we have new data, work with it
	}
	
	/*
	* get_new_data
	*
	* @params
	* void
	*/
	static public function get_new_data( $xbot )
	{
		$git_chans_q = mysql_query( "SELECT `id`, `repo`, `chan`, `empty` FROM `".self::$config['mysql']['table_c']."`" );
		while ( $git_chans = mysql_fetch_array( $git_chans_q ) )
		{
			$repo_a = explode( '/', $git_chans['repo'] );
		
			foreach ( self::$config['recursive_api_calls'] as $id => $call )
			{
				$call = str_replace( '{user}', $repo_a[0], $call );
				$call = str_replace( '{repo}', $repo_a[1], $call );
				$data = self::call_api( $call );
				
				if ( empty( $data[$id] ) )
					continue;
				// response is empty, move on.
				
				if ( $id == ( 'issues' || 'pulls' ) )
				{
					$changed = $comments = 0;
					// find out how many are actually new, if none are we don't bother inserting into
					// our post recieve data table or we fast end up with a HUGE table.
					
					foreach ( $data[$id] as $i => $rep )
					{
						$timestamp = strtotime( $rep['created_at'] );
						$git_reps_q = mysql_query( "SELECT `id`, `repo`, `info_id`, `comments` FROM `".self::$config['mysql']['table_i']."` WHERE `info_id` = '".$rep['number']."' AND `type` = '".$id."' AND `repo` = '".$git_chans['repo']."' AND `timestamp` = '".$timestamp."'" );
						
						if ( mysql_num_rows( $git_reps_q ) > 0 )
						{
							$git_reps = mysql_fetch_array( $git_reps_q );
							
							if ( $git_reps['comments'] < $rep['comments'] )
							{
								mysql_query( "UPDATE `".self::$config['mysql']['table_i']."` SET `comments` = '".$rep['comments']."' WHERE `id` = '".$git_reps['id']."'" );
								// update our new comment limit
								
								$ccall = 'http://github.com/api/v2/json/issues/comments/'.$git_chans['repo'].'/'.$git_reps['info_id'];
								$cdata = self::call_api( $ccall );
								$ncdata['comments'] = array_slice( $cdata['comments'], $git_reps['comments'] );
								$ncdata['repo'] = $git_chans['repo'];
								$ncdata['repo_id'] = $git_reps['info_id'];
								$ncdata['issue_title'] = $rep['title'];
								
								$comments++;
								// we need to find out which comments are new.
							}
							// update number of comments and look for the new ones :D
							
							unset( $data[$id][$i] );
							continue;
						}
						
						$number = ( $id == 'comments' ) ? $rep['id'] : $rep['number'];
						$data[$id][$i]['repo'] = $git_chans['repo'];
						$changed++;
						mysql_query( "INSERT INTO `".self::$config['mysql']['table_i']."` (`type`, `repo`, `info_id`, `timestamp`, `comments`) VALUES('".$id."', '".$git_chans['repo']."', '".$number."', '".$timestamp."', '".$rep['comments']."')" );
						// check if the issue is new or not, if it is insert it into our issue table so
						// we don't recognise it again, and also pass info into our post_recieve table
					}
					
					if ( $git_chans['empty'] == 0 && ( $changed > 0 || $comments > 0 ) )
					{
						$encoded_data = ( $comments > 0 ) ? $ncdata : $data;
						$rid = ( $comments > 0 ) ? 'comments' : $id;
						$encoded_data = addslashes( json_encode( $encoded_data ) );
						mysql_query( "INSERT INTO `".self::$config['mysql']['table_p']."` (`timestamp`, `payload`, `read`, `type`) VALUES('".time()."', '".$encoded_data."', '0', '".$rid."')" );
					}
					// we have changed records!
				}
				// loop through issues and pulls and find out which ones are new.
			}
			// loop through the recursive api calls and call them
			
			if ( $git_chans['empty'] == 1 )
				mysql_query( "UPDATE `".self::$config['mysql']['table_c']."` SET `empty` = '0' WHERE `id` = '".$git_chans['id']."'" );
			// set the channel as not-empty.. make sense?
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
		
		foreach ( $payload['commits'] as $id => $commit )
		{
			$msg = self::$config['unparsed_responses']['commits'];
			$search = array(
				'{repo}', '{user}', '{branch}', '{revision}', '{files}', '{title}', '{url}'
			);
			
			$replace = array(
				$payload['repository']['name'],
				$commit['author']['name'],
				$payload['repository']['integrate_branch'],
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
	* parse_issues
	*
	* @params
	* $xbot < object, $payload < valid github json
	*/
	static public function parse_issues( $xbot, $payload )
	{
		$issues = count( $payload['issues'] );
		foreach ( $payload['issues'] as $id => $issue )
		{
			$repo = $issue['repo'];
			$repo_a = explode( '/', $repo );
			
			$msg = self::$config['unparsed_responses']['issues'];
			$search = array(
				'{repo}', '{user}', '{date}', '{title}', '{message}', '{url}'
			);
			
			$replace = array(
				$repo_a[1],
				$issue['user'],
				date( 'd/m/Y i:H', strtotime( $issue['created_at'] ) ),
				( strlen( $issue['title'] ) > 50 ) ? substr( $issue['title'], 0, 50 ).'..' : $issue['title'],
				( strlen( $issue['body'] ) > 150 ) ? substr( $issue['body'], 0, 150 ).'..' : $issue['body'],
				$issue['html_url']
			);
			
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
	* parse_pulls
	*
	* @params
	* $xbot < object, $payload < valid github json
	*/
	static public function parse_pulls( $xbot, $payload )
	{
		$pulls = count( $payload['pulls'] );
		foreach ( $payload['pulls'] as $id => $pull )
		{
			$repo = $pull['repo'];
			$repo_a = explode( '/', $repo );
			
			$msg = self::$config['unparsed_responses']['pulls'];
			$search = array(
				'{repo}', '{user}', '{head_label}', '{base_label}', '{title}', '{message}', '{url}'
			);
			
			$replace = array(
				$repo_a[1],
				$pull['user']['login'],
				$pull['head']['label'],
				$pull['base']['label'],
				( strlen( $pull['title'] ) > 50 ) ? substr( $pull['title'], 0, 50 ).'..' : $pull['title'],
				( strlen( $pull['body'] ) > 150 ) ? substr( $pull['body'], 0, 150 ).'..' : $pull['body'],
				$pull['html_url']
			);

			$msg = str_replace( $search, $replace, $msg );
			// compile a message
			
			$net = self::$config['repos'][$repo];
			// commit channel
			
			$xbot->msg( $net[0], $net[1], $msg );
			
			if ( $pulls > 4 )
				usleep( 500000 );
		}
		// foreach pulls.
		
		// output info like so:
		// acorairc: n0valyfe has requested to merge n0valyfe:bugfix into ircnode:acorairc: Issue title - Issue body this is a lump of text etc (https://github.com/technoweenie/faraday/pull/15)
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
		foreach ( $payload['comments'] as $id => $comment )
		{
			$repo = $payload['repo'];
			$repo_a = explode( '/', $repo );
			
			$msg = self::$config['unparsed_responses']['comments'];
			$search = array(
				'{repo}', '{user}', '{title}', '{message}', '{url}'
			);
			
			$replace = array(
				$repo_a[1],
				$comment['user'],
				( strlen( $payload['issue_title'] ) > 50 ) ? substr( $payload['issue_title'], 0, 50 ).'..' : $payload['issue_title'],
				( strlen( $comment['body'] ) > 150 ) ? substr( $comment['body'], 0, 150 ).'..' : $comment['body'],
				'https://github.com/'.$repo.'/issues/'.$payload['repo_id'].'#issuecomment-'.$comment['id']
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
	* call_api
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
			 CURLOPT_HEADER 		=> false,
			 CURLOPT_RETURNTRANSFER => true,
		);

		curl_setopt_array( $curl_handle, $options );
		$rdata = curl_exec( $curl_handle );
		curl_close( $curl_handle );
		
		return json_decode( preg_replace( '/\s\s+/', ' ', $rdata ), true );
	}
}

// EOF;