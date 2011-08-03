<?php

/*
* xBot IRC Framework
* xbot.php: xBot core class.
*
* Permission to use, copy, modify, and/or distribute this software for any
* purpose with or without fee is hereby granted, provided that the above
* copyright notice and this permission notice appear in all copies.
*/

class xbot
{
	public $sockets = array();
	public $mynicks = array();
	public $chans = array();
	// declare some variables.

	/*
	* __construct
	*
	* @params
	* void
	*/
	public function __construct()
	{
		$this->events = new events();
		$this->timer = new timer();
		// start our subclasses
	}

	/*
	* connect
	*
	* @params
	* $info - array containing port, nick, ident. etc
	*/
	public function connect( $info )
	{
		$this->info = $info;

		foreach ( $this->info['networks'] as $host => $hinfo )
		{
			$hinfo = (object) $hinfo;

			$socket = fsockopen( $host, $hinfo->port );

			if ( !$socket )
			{
				// cannot connect
				// do something here, like debug logging, etc.
				continue;
			}
			else
			{
				$this->sockets[$host] = $socket;
				$this->mynicks[$hinfo->nick] = $host;

				stream_set_blocking( $socket, 0 );
				// set this to blocking

				$this->send( $host, 'USER '.$hinfo->ident.' a b :'.$hinfo->real );
				$this->nick( $host, $hinfo->nick );
				// send our info
			}
		}
		// loop though our hosts, connecting to em.

		if ( count( $this->sockets ) == 0 )
			exit( 'Error: No connections were able to establish.' );
		// we've not established any connections, quit.

		sleep( 5 );
		// might need to sleep

		$this->timer->init();
		// timer init

		$this->main_loop = true;
		// open main loop
	}

	/*
	* disconnect
	*
	* @params
	* void
	*/
	public function disconnect()
	{
		foreach ( $this->sockets as $host => $socket )
			$this->quit( $host );
		// loop though our bots quitting them.
	}

	/*
	* init
	*
	* @params
	* void
	*/
	public function join_chans( $host )
	{
		usleep( 200000 );
		// lazy bastard just constantly wanted to sleep

		foreach ( $this->info['networks'][$host]['chans'] as $chan => $key )
		{
			if ( $key == '' )
				$this->join( $host, $chan );
			else
				$this->join( $host, $chan, $key );

			usleep( 200000 );
			// and again.. sheezh
		}
		// loop though our channels & join them.
	}

	/*
	* main
	*
	* @params
	* $class - class
	* $function - function
	*/
	public function main( $class, $function )
	{
		$this->class = $class;
		$this->function = $function;
		$loop_count = 0;
		$joined = false;
		// remember all this.

		while ( $this->main_loop )
		{
			$loop_count++;

			foreach ( $this->sockets as $host => $socket )
			{
				$this->timer->loop();

				if ( $raw = stream_get_line( $socket, 4093, "\r\n" ) )
				{
					$raw = trim( $host.' '.$raw );
					$this->ircdata = explode( ' ', $raw );
					// create our $ircdata arr

					if ( $this->events->on_error( $this->ircdata ) )
					{
						$this->disconnect();
						// kill all our bots
						$this->main_loop = false;
						// stop the main loop
						$this->connect( $this->info );
						// connect
						$this->main( $this->class, $this->function );
						// reopen the main loop
					}
					// error? reboot

					if ( $this->events->on_ping( $this->ircdata ) )
						$this->pong( $host, str_replace( ':', '', $this->ircdata[2] ) );
					// ping pong, ting tong

					if ( $this->events->on_pmsg( $this->ircdata ) )
					{
						$from = explode( '!', $this->ircdata[1] );
						$from = substr( $from[0], 1 );
						// who is CTCP'ing us?

						if ( strtolower( substr( $this->ircdata[4], 1 ) ) == strtolower( 'TIME' ) )
						{
							$this->ctcp( $host, $from, 'TIME', date( 'D M j G:i:s Y', time() ) );
							continue;
						}

						if ( strtolower( substr( $this->ircdata[4], 1 ) ) == strtolower( 'PING' ) )
						{
							$this->ctcp( $host, $from, 'PING', '0secs' );
							continue;
						}
						// time and ping, are automaticaly replied to

						foreach ( $this->info['ctcp'] as $type => $value )
						{
							if ( strtolower( substr( $this->ircdata[4], 1 ) ) == strtolower( ''.$type.'' ) )
							{
								$this->ctcp( $host, $from, $type, $value );
								continue;
							}
							// a match is found, send it back
						}
						// loop though our ctcp values
						// note these can be also custom values.
					}
					// ctcp reply

					$ircdata_obj = $this->format( $this->ircdata );
					// format our data

					if ( $joined === false && ( $this->events->on_connect( $this->ircdata ) ) )
					{
						$this->join_chans( $host );
						$joined = true;
					}
				}
				// reading the data

				if ( $class == '' )
					call_user_func_array( $function, array( $this, $ircdata_obj ) );
				else
					call_user_func_array( array( $class, $function ), array( $this, $ircdata_obj ) );
				// execute the callback

				unset( $ircdata_obj );
				unset( $this->ircdata );
			}
			// foreach through our sockets

			usleep( 30000 );
			// usleep to keep cpu handling well.
		}
	}

	/*
	* format
	*
	* @params
	* $ircdata - ..
	*/
	public function format( $ircdata )
	{
		$ircdata_obj = (object) array();

		$from = explode( '!', $ircdata[1] );
		$ident = explode( '@', $from[1] );

		$ircdata_obj->raw = implode( ' ', $ircdata );
		$ircdata_obj->from = $ircdata[0];
		$ircdata_obj->nick = str_replace( ':', '', $from[0] );
		$ircdata_obj->ident = $ident[0];
		$ircdata_obj->host = $ident[1];
		// these are standard in ircdata objects

		if ( $this->events->on_join( $ircdata ) || $this->events->on_part( $ircdata ) )
		{
			$ircdata_obj->type = strtolower( $ircdata[2] );
			$ircdata_obj->channel = ( $ircdata[3][0] == ':' ) ? substr( $ircdata[3], 1 ) : $ircdata[3];
			if ( $this->events->on_part( $ircdata ) ) $ircdata_obj->message = substr( $this->get_data_after( $ircdata, 4 ), 1 );
			// format it into a usable object.

			return $ircdata_obj;
			// return this object.
		}
		// on join/part?
		elseif ( $this->events->on_quit( $ircdata ) )
		{
			$ircdata_obj->type = strtolower( $ircdata[2] );
			$ircdata_obj->message = substr( $this->get_data_after( $ircdata, 3 ), 1 );
			// format it into a usable object.

			return $ircdata_obj;
			// return this object.
		}
		// on quit
		elseif ( $this->events->on_kick( $ircdata ) )
		{
			$ircdata_obj->type = strtolower( $ircdata[2] );
			$ircdata_obj->who = $ircdata[4];
			$ircdata_obj->channel = ( $ircdata[3][0] == ':' ) ? substr( $ircdata[3], 1 ) : $ircdata[3];
			$ircdata_obj->message = substr( $this->get_data_after( $ircdata, 5 ), 1 );
			// format it into a usable object.

			return $ircdata_obj;
			// return this object.
		}
		// on kick
		elseif ( $this->events->on_mode( $ircdata ) )
		{
			$ircdata_obj->type = strtolower( $ircdata[2] );
			$ircdata_obj->channel = ( $ircdata[3][0] == ':' ) ? substr( $ircdata[3], 1 ) : $ircdata[3];
			$ircdata_obj->mode = $this->get_data_after( $ircdata, 4 );
			// format it into a usable object.

			return $ircdata_obj;
			// return this object.
		}
		// on mode
		elseif ( $this->events->on_cmsg( $ircdata ) || $this->events->on_pmsg( $ircdata ) || $this->events->on_cnotice( $ircdata ) || $this->events->on_pnotice( $ircdata ) )
		{
			$ircdata_obj->type = strtolower( $ircdata[2] );
			$ircdata_obj->target = $ircdata[3];
			$ircdata_obj->message = substr( $this->get_data_after( $ircdata, 4 ), 1 );
			// format it into a usable object.

			return $ircdata_obj;
			// return this object.
		}
		// on msg/notice
		elseif ( $this->events->on_nick( $ircdata ) )
		{
			$ircdata_obj->type = strtolower( $ircdata[2] );
			$ircdata_obj->host = $ident[1];
			$ircdata_obj->new = $ircdata[3];
			// format it into a usable object.

			return $ircdata_obj;
			// return this object.
		}
		// on nick
		elseif ( $this->events->on_topic( $ircdata ) )
		{
			$ircdata_obj->type = strtolower( $ircdata[2] );
			$ircdata_obj->channel = ( $ircdata[3][0] == ':' ) ? substr( $ircdata[3], 1 ) : $ircdata[3];
			$ircdata_obj->topic = substr( $this->get_data_after( $ircdata, 4 ), 1 );
			// format it into a usable object.

			return $ircdata_obj;
			// return this object.
		}
		// on topic
		else
		{
			unset( $ircdata_obj->nick, $ircdata_obj->ident, $ircdata_obj->host );
			// unset the stuff we don't need for numerics.

			$ircdata_obj->type = 'numeric';
			$ircdata_obj->numeric = $ircdata[2];
			$ircdata_obj->message = $this->get_data_after( $ircdata, 4 );
			$ircdata_obj->server = str_replace( ':', '', $ircdata[1] );
			// this is most likely a numeric
			// so we parse it a little differently.

			return $ircdata_obj;
		}
		// else.
	}

	/*
	* format_list
	*
	* @params
	* $ircdata - ..
	*/
	public function format_list( $ircdata )
	{
		if ( $ircdata[2] == '322' )
		{
			$message = $this->get_data_after( $ircdata, 4 );
			$list_reply = explode( ' ', $message );
			// setup our vars

			if ( $list_reply[0] == '*' ) return false;
			// ignore * don't have a clue what it is..

			$listreply = array(
				'channel' => $list_reply[0],
				'users' => $list_reply[1],
				'modes' => substr( $list_reply[2], 2, -1 ),
				'topic' => $this->get_data_after( $list_reply, 3 ),
			);
			// setup the list reply

			return $listreply;
			// return it
		}
	}

	/*
	* format_names
	*
	* @params
	* $ircdata - ..
	*/
	public function format_names( $ircdata )
	{
		if ( $ircdata[2] == '353' )
		{
			$message = $this->get_data_after( $ircdata, 4 );
			$names_reply = explode( ' ', $message );
			$channel_nicks = substr( $this->get_data_after( $names_reply, 2 ), 1 );
			$channel_nicks = explode( ' ', $channel_nicks );
			// setup some arrays/vars

			$namesreply = array(
				'channel' => $names_reply[1],
				'users' => $channel_nicks,
			);

			return $namesreply;
		}
		// names numeric
	}

	/*
	* ctcp
	*
	* @params
	* $host - ..
	* $target - nick to ctcp reply
	* $message - type, eg. VERSION
	* $value - eg. SomeBot 0.1, etc.
	*/
	public function ctcp( $host, $target, $type, $value )
	{
		$this->notice( $host, $target, ''.$type.' '.$value.'' );
		// sent a ctcp reply to the server
	}

	/*
	* action
	*
	* @params
	* $host - ..
	* $target - nick/channel to action
	* $message - action to use
	*/
	public function action( $host, $target, $message )
	{
		$this->send( $host, 'PRIVMSG '.$target.' :ACTION '.$message.'' );
		// send ACTION to the server
	}

	/*
	* msg
	*
	* @params
	* $host - ..
	* $target - nick/channel to msg
	* $message - message to use
	*/
	public function msg( $host, $target, $message )
	{
		$this->send( $host, 'PRIVMSG '.$target.' :'.$message );
		// send PRIVMSG to the server
	}

	/*
	* notice
	*
	* @params
	* $host - ..
	* $target - nick/channel to notice
	* $message - message to use
	*/
	public function notice( $host, $target, $message )
	{
		$this->send( $host, 'NOTICE '.$target.' :'.$message );
		// send NOTICE to the server
	}

	/*
	* topic
	*
	* @params
	* $host - ..
	* $chan - channel to set topic on
	* $topic - topic to set
	*/
	public function topic( $host, $chan, $topic = '' )
	{
		if ( $topic != '' )
		{
			$this->send( $host, 'TOPIC '.$chan.' '.$topic );
		}
		else
		{
			$this->send( $host, 'TOPIC '.$chan );

			usleep( 10000 );
			// usleep a tick, so we can wait for the reply

			$socket = $this->sockets[$host];
			while ( $raw = stream_get_line( $socket, 4093, "\r\n" ) )
			{
				$raw = trim( $host.' '.$raw );
				$ircdata = explode( ' ', $raw );
				// create our $ircdata arr

				$topic = $this->format( $ircdata );
				$topic = explode( ' ', $topic->message );
				// format our data

				return substr( $this->get_data_after( $topic, 1 ), 1 );
				// return topic reply
			}
			// re-format the latest stuff from the socket
		}
		// send TOPIC to the server
	}

	/*
	* mode
	*
	* @params
	* $host - ..
	* $chan - channel to set modes on
	* $mode - modes to set
	*/
	public function mode( $host, $chan, $mode )
	{
		$this->send( $host, 'MODE '.$chan.' '.$mode );
		// send MODE to the server
	}

	/*
	* kick
	*
	* @params
	* $host - ..
	* $chan - channel to kick user from
	* $user - user to kick
	*/
	public function kick( $host, $chan, $user )
	{
		$this->send( $host, 'KICK '.$chan.' '.$user );
		// send KICK to the server
	}

	/*
	* invite
	*
	* @params
	* $host - ..
	* $chan - channel to invite user to
	* $user - user to invite
	*/
	public function invite( $host, $chan, $user )
	{
		$this->send( $host, 'INVITE '.$chan.' '.$user );
		// send INVITE to the server
	}

	/*
	* join
	*
	* @params
	* $host - ..
	* $chan - channel to join
	* $key - key to use
	*/
	public function join( $host, $chan, $key = '' )
	{
		if ( $key != '' )
			$this->send( $host, 'JOIN '.$chan.' '.$key );
		else
			$this->send( $host, 'JOIN '.$chan );
		// send JOIN to the server
	}

	/*
	* part
	*
	* @params
	* $host - ..
	* $chan - channel to part
	* $message - message to use
	*/
	public function part( $host, $chan, $message = '' )
	{
		if ( $message != '' )
			$this->send( $host, 'PART '.$chan.' '.$message );
		else
			$this->send( $host, 'PART '.$chan );
		// send PART to the server
	}

	/*
	* nick
	*
	* @params
	* $host - ..
	* $nick - nick to use.
	*/
	public function nick( $host, $nick )
	{
		$this->send( $host, 'NICK '.$nick );
		// send NICK to the server
	}

	/*
	* quit
	*
	* @params
	* $host - ..
	*/
	public function quit( $host )
	{
		$this->send( $host, 'QUIT' );
		// send QUIT to the server
	}

	/*
	* names
	*
	* @params
	* $host - ..
	* $chan - channel to /names
	*/
	public function names( $host, $chan )
	{
		$this->send( $host, 'NAMES '.$chan );
		// send NAMES to the server

		usleep( 10000 );
		// usleep a tick, so we can wait for the reply

		$socket = $this->sockets[$host];
		while ( $raw = stream_get_line( $socket, 4093, "\n" ) )
		{
			$raw = trim( $host.' '.$raw );
			$ircdata = explode( ' ', $raw );
			// create our $ircdata arr

			$reply = $this->format_names( $ircdata );
			// format our data

			if ( $reply != '' ) $namesreply[] = $reply;
		}
		// re-format the latest stuff from the socket

		return $namesreply;
		// return names reply
	}

	/*
	* list
	*
	* @params
	* $host - ..
	*/
	public function list_chans( $host )
	{
		$this->send( $host, 'LIST' );
		// send LIST to the server

		usleep( 200000 );
		// usleep a tick, so we can wait for the reply
		// seems we have to usleep ages here, because its just not working
		// fucking GAY!

		$socket = $this->sockets[$host];
		while ( $raw = stream_get_line( $socket, 4093, "\r\n" ) )
		{
			$raw = trim( $host.' '.$raw );
			$ircdata = explode( ' ', $raw );
			// create our $ircdata arr

			$reply = $this->format_list( $ircdata );
			// format our data

			if ( $reply != '' ) $listreply[] = $reply;
		}
		// re-format the latest stuff from the socket

		return $listreply;
		// return list reply
	}

	/*
	* pong
	*
	* @params
	* $host - ..
	* $server - server to pong
	*/
	public function pong( $host, $server )
	{
		$this->send( $host, 'PONG '.$server );
		// send PING to the server
	}

	/*
	* pass
	*
	* @params
	* $host - ..
	* $pass - pass to send to the server
	*/
	public function pass( $host, $pass )
	{
		$this->send( $host, 'PASS '.$pass );
		// send PASS to the server
	}

	/*
	* send
	*
	* @params
	* $message - what to send to the socket.
	*/
	public function send( $host, $message )
	{
		$socket = $this->sockets[$host];

		fputs( $socket, $message."\n", strlen( $message."\n" ) );
		// fputs.
	}

	/*
	* get_data_after
	*
	* @params
	* $ircdata, $number
	*/
	public function get_data_after( $ircdata, $number )
	{
		$new_ircdata = $ircdata;

		for ( $i = 0; $i < $number; $i++ )
			unset( $new_ircdata[$i] );
		// the for loop lets us determine where to go, how many to get etc.. so hard to explain
		// but so easy to understand when your working with it :P
		// we reset the variable and unset everything that isnt needed
		// just to make sure we dont fuck something up with $ircdata (fragile x])
		$new = implode( ' ', $new_ircdata );

		return trim( $new );
	}
}

// EOF;
