<?php


$socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );

socket_connect( $socket, '127.0.0.1', 7423 );
//socket_write( $socket, pack( 'C', 50 ), 1 );  


$path = "/run/media/god/Taws/prepared_data/torrents/dumps.torrent";
$message = pack( 'CN', 50, strlen( $path ) ) . $path;
socket_write( $socket, $message, strlen( $message ) );

socket_close( $socket );

// request a piece
//$message = pack( 'NCNNN', 13, 6, $piece_index, 0, $this->torrent_data[ 'info' ][ 'piece length' ]  );
//socket_write( $socket, $message, strlen( $message ) );