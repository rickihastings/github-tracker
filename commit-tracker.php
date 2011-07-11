<?php

// GITHUB PAYLOAD RECIEVER
// for commits.

$config = array(
	'mysql'		=> array(
		'host'	=> 'localhost',
		'user'	=> 'root',
		'pass'	=> '',
		'db'	=> 'samantha',
		'table'	=> 'git_post_recieves',
	),
);
// config array, just for mysql connects really.

$db = mysql_connect( $config['mysql']['host'], $config['mysql']['user'], $config['mysql']['pass'] );
mysql_select_db( $config['mysql']['db'], $db ) or die();
// connect to mysql

$host = gethostbyaddr( $_SERVER['REMOTE_ADDR'] );
$host = explode( '.', $host );
$host = $host[count( $host ) - 2] . '.' . $host[count( $host ) - 1];

if ( $host !== 'github.com' )
	die();
// only deploy a payload if it's from github.com

$payload = addslashes( $_POST['payload'] );
mysql_query( "INSERT INTO `".$config['mysql']['table']."` (`timestamp`, `payload`, `read`, `type`) VALUES('".time()."', '".$payload."', '0', 'commit')" );
// insert a payload into our database

// EOF;
