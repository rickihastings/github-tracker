<?php

/*
* xBot IRC Framework
* events.php: Event handling class.
*
* Permission to use, copy, modify, and/or distribute this software for any
* purpose with or without fee is hereby granted, provided that the above
* copyright notice and this permission notice appear in all copies.
*/

class events extends xbot
{
	
	/*
	* __construct
	*
	* @params
	* void
	*/
	public function __construct() {	}
	
	/*
	* on_connect
	*
	* @params
	* $ircdata - ..
	*/
	public function on_connect( $ircdata )
	{
		if ( $ircdata[2] == '004' )
			return true;
		// return true if we've been sent modes n shit
		
		return false;
		// return false
	}
	
	/*
	* on_error
	*
	* @params
	* $ircdata - ..
	*/
	public function on_error( $ircdata )
	{
		if ( $ircdata[1] == 'ERROR' )
			return true;
		// return true if we've been sent error
		
		return false;
		// return false
	}
	
	/*
	* on_ping
	*
	* @params
	* $ircdata - ..
	*/
	public function on_ping( $ircdata )
	{
		if ( $ircdata[1] == 'PING' )
			return true;
		// return true if we've been pinged
		
		return false;
		// return false
	}
	
	/*
	* on_join
	*
	* @params
	* $ircdata - ..
	*/
	public function on_join( $ircdata )
	{
		if ( $ircdata[2] == 'JOIN' )
			return true;
		// return true if we've seen a join
		
		return false;
		// return false
	}
	
	/*
	* on_part
	*
	* @params
	* $ircdata - ..
	*/
	public function on_part( $ircdata )
	{
		if ( $ircdata[2] == 'PART' )
			return true;
		// return true if we've seen a part
		
		return false;
		// return false
	}
	
	/*
	* on_quit
	*
	* @params
	* $ircdata - ..
	*/
	public function on_quit( $ircdata )
	{
		if ( $ircdata[2] == 'QUIT' )
			return true;
		// return true if we've seen a quit
		
		return false;
		// return false
	}
	
	/*
	* on_kick
	*
	* @params
	* $ircdata - ..
	*/
	public function on_kick( $ircdata )
	{
		if ( $ircdata[2] == 'KICK' )
			return true;
		// return true if we've seen a kick
		
		return false;
		// return false
	}
	
	/*
	* on_mode
	*
	* @params
	* $ircdata - ..
	*/
	public function on_mode( $ircdata )
	{
		if ( $ircdata[2] == 'MODE' )
			return true;
		// return true if we've seen a mode
		
		return false;
		// return false
	}
	
	/*
	* on_cmsg
	*
	* @params
	* $ircdata - ..
	*/
	public function on_cmsg( $ircdata )
	{
		if ( $ircdata[2] == 'PRIVMSG' && $ircdata[3][0] == '#' )
			return true;
		// return true if we've seen a channel msg
		
		return false;
		// return false
	}
	
	/*
	* on_pmsg
	*
	* @params
	* $ircdata - ..
	*/
	public function on_pmsg( $ircdata )
	{
		if ( $ircdata[2] == 'PRIVMSG' && $ircdata[3][0] != '#' )
			return true;
		// return true if we've seen a private msg
		
		return false;
		// return false
	}
	
	/*
	* on_cnotice
	*
	* @params
	* $ircdata - ..
	*/
	public function on_cnotice( $ircdata )
	{
		if ( $ircdata[2] == 'NOTICE' && $ircdata[3][0] == '#' )
			return true;
		// return true if we've seen a channel notice
		
		return false;
		// return false
	}
	
	/*
	* on_pnotice
	*
	* @params
	* $ircdata - ..
	*/
	public function on_pnotice( $ircdata )
	{
		if ( $ircdata[2] == 'NOTICE' && $ircdata[3][0] != '#' )
			return true;
		// return true if we've seen a private notice
		
		return false;
		// return false
	}
	
	/*
	* on_nick
	*
	* @params
	* $ircdata - ..
	*/
	public function on_nick( $ircdata )
	{
		if ( $ircdata[2] == 'NICK' )
			return true;
		// return true if we've seen a private notice
		
		return false;
		// return false
	}
	
	/*
	* on_topic
	*
	* @params
	* $ircdata - ..
	*/
	public function on_topic( $ircdata )
	{
		if ( $ircdata[2] == 'TOPIC' )
			return true;
		// return true if we've seen a private notice
		
		return false;
		// return false
	}
}

// EOF;