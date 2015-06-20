<?php


// a i might do auto loader later
require_once( __DIR__ . "/torrent/torrent.php" );
require_once( __DIR__ . "/bencode/bencode.php" );
require_once( __DIR__ . "/daemon/daemon.php" );
require_once( __DIR__ . "/daemon/operation.php" );
require_once( __DIR__ . "/config/config.php" );
require_once( __DIR__ . "/storage/storage.php" );

// start Daemon and detach,
//passthru( "php ./daemon/daemon.php" );

$d = new Daemon;
$d->start();