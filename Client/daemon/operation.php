<?php


class Operation{
    // Daemon
    const SHUTDOWN = 0; // clean shut down
    const RESTART = 255; // create a new daemon and closes the current one
    
    // Torrent options, these require a secondary value 
    const CREATE_TORRENT = 255; // create a brand new torrent
    const ADD_TORRENT = 50; // add an already existing torrent file. {code}{length}{path}
    const REMOVE_TORRENT = 255;
    const START_TORRENT = 255;
    const STOP_TORRENT = 255;
    const START_ALL_TORRENTS = 255;
    const STOP_ALL_TORRENTS = 255;
    const DISPLAY_TORRENT_PROGRESS = 255;
    

}