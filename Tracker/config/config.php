<?php


/**
 * Configuration options for you to play around with;
 *
 * @note It would be a good idea to hide this in apache
 */
class Config{
    // MySQL Connection
    const DB_SERVER_HOST = "localhost";
    const DB_SERVER_USERNAME = "seeder";
    const DB_SERVER_PASSWORD = "";
    const DB_SERVER_NAME = "torrent_tracker";
    
    // Tracker behavior
    const INTERVAL = 30;
    const MIN_INTERVAL = 5;    
    const DEFAULT_NUMBER_OF_PEERS = 25; // numwhat - specs say it's 50
    
    //...
    const Max_PEER_IDLE_TIME = 120; // If a peer doesn't announce, how long to keep him 
    const TRACKER_ID = "define own traking id";
}