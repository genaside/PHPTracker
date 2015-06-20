<?php


class Torrent{

    /**
     * Get and parse a .torrent file
     *
     * @param $torrent_path The path to the .torrent file. 
     * Path doesen't have to be on the local machine
     * @return a dictionary form of the file
     */
    public static function getTorrentInfoFromFile( $torrent_path ){
        $raw_data = file_get_contents( $torrent_path );           
        $info = Bencode::decode( $raw_data );
        return $info;
    }

}