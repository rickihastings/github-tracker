<?php

/*
* xBot IRC Framework
* xbot.php: xBot core class.
*
* Permission to use, copy, modify, and/or distribute this software for any
* purpose with or without fee is hereby granted, provided that the above
* copyright notice and this permission notice appear in all copies.
*/

class timer extends xbot
{
	
	static public $uptime;
	static public $last_time = 0;
	static public $timers = array();
	// some vars.
	
	/*
	* __construct
	*
	* @params
	* void
	*/
	public function __construct() {}
	
	/*
	* init
	*
	* @params
	* void
	*/
	static public function init()
	{
		self::$last_time = time();
		self::$uptime = 0;
		// this basically just sets our timer variables up.
	}
	
	/*
	* add
	*
	* @params
	* $execution - array( 'class', 'method', '(optional parameter)' )
	* $timer - how often it should be executed (seconds)
	* $times - how many times it should be executed.
	*/
	static public function add( $execution, $timer = 1, $times = 1 )
	{
		// NOTE: params in $execution, should be an array
		
		if ( !is_array( $execution ) || !count( $execution ) >= 2 )
		{
			return false;
		}
		// is the format correct
		
		self::$timers[] = array(
			'class' => $execution[0],
			'method' => $execution[1],
			'params' => ( isset( $execution[2] ) ) ? $execution[2] : '',
			'timer' => $timer,
			'times' => $times,
			'count_timer' => 0,
			'count_times' => 0,
		);
		// add our timer to the timer array.
		// we could instantly call it, but the reason we're using
		// timers in some places is to get to the next loop, so we just
		// call it when we need to.
		
	}
	
	/*
	* remove
	*
	* @params
	* $execution - array( 'class', 'method', '(optional parameter)' ) < should be EXACTLY
	*              the same as the data used in timer::add()
	*/
	static public function remove( $execution )
	{
		// NOTE: params in $execution, should be an array
		
		if ( !is_array( $execution ) || !count( $execution ) >= 2 )
		{
			return false;
		}
		// is the format correct
		
		foreach ( self::$timers as $tid => $data )
		{
			if ( $data['class'] == $execution[0] && $data['method'] == $execution[1] && $data['params'] == $execution[2] )
			{
				unset( self::$timers[$tid] );
				break;
			}
		}
		// find a match.
	}
	
	/*
	* loop
	*
	* @params
	* void
	*/
	static public function loop()
	{
		if ( substr( time(), -1, 1 ) != substr( self::$last_time, -1, 1 ) )
		{
			self::$last_time = time();
			self::$uptime++;
			// another second added.
			
			foreach ( self::$timers as $tid => $data )
			{
				$class = $data['class'];
				$method = $data['method'];
				$params = $data['params'];
				// set up some variables.
				
				if ( self::$timers[$tid]['count_timer'] == $data['timer'] )
				{
					if ( is_callable( array( $class, $method ), true ) && method_exists( $class, $method ) )
					{
						if ( $params == '' )
							call_user_func( array( $class, $method ) );
						else
							call_user_func_array( array( $class, $method ), $params );
					}
					// execute the callback, with parameters if defined.
					
					self::$timers[$tid]['count_timer'] = 0;
					self::$timers[$tid]['count_times']++;
					// reset the counter back to 0, so it's executed again
					// and ++ our count_times
					
					if ( ( self::$timers[$tid]['count_times'] == $data['times'] ) && $data['times'] != 0 )
					{
						unset( self::$timers[$tid] );
						continue;
					}
					// check if it's the last time we're to execute it
					// if so, cut the timer off.
					// if times is 0, which means indefinatly, just keep running it.
				}
				
				self::$timers[$tid]['count_timer']++;
				// ++
			}
			// loop though the set timers.
			// ++'ng each one of them, then checking against their
			// execution time.	
		}
	}	
}

?>
