<?php

    
/**
 * The main program that will be running in the background
 * TODO Remove all magic numbers
 * 
 * TODO move database work to another file
 * TODO add transactions
 * TODO I took a look at udp tracking, i will not craete a udp tracker but that doesn't 
 *      mean i cant announce to them
 */
class Daemon{

    const CLIENT_ID =  "-PT0040-"; // Azureus-style
    
    /**
     * A randomly created ID
     * @var string
     */
    private $peer_id;
    
    /**
     * A list of port resources being used
     * @todo add more things.
     * @var array { resource, port_number, occupied }
     */
    private $ports;
    
    /**
     * A opened SQLite connection.
     * @var resource
     */
    private $db_conn;    
    
    /**
     * A port that will send infomation on what action
     * the client needs to take. It is sort of like a replacement
     * for a gui interface.
     * @var resource
     */
    private $interface_conn;
    
    /**
     * A flag that tell if the program is running or not.
     * @var bool
     */
    private $is_running_flag = true;
    
    
    /**
     * Constructor
     */
    public function __construct(){   
        // initialize variables
        $this->ports = array();
    }
    
    /**
     * Clean up
     */
    public function __destruct(){    
        // Close ports            
        foreach( $this->ports AS $port ){
            socket_close( $port[ 'resource' ] );
        } 
        
        // Close database
        $this->db_conn->close();
        
        // Close the Interface port
        socket_close( $this->interface_conn );
    }
    
    /**
     * Start running the program
     */
    public function start(){
        // initialize varius things    
        $this->initializeID();
        $this->initializePorts();
        $this->initializeDatabase();
        $this->initializeInterface();
        
        // Run Main Loop
        $this->mainLoop();
    }
    
    /**
     * Installize this running program's ID(peer_id).
     * client id + 12 byte random string     
     */
    private function initializeID(){
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $len = 12;
        $charactersLength = strlen( $characters );
         
        $randomString = '';        
        for( $i = 0; $i < $len; ++$i ){
            $randomString .= $characters[ rand( 0, $charactersLength - 1 ) ];
        }
    
        $this->peer_id = self::CLIENT_ID . $randomString;        
    }
    
    
    /**
     * Start opening all ports in the range defined in config.php
     * commas are treated as lists, and dashes are treated as ranges.     
     * 
     * @throws ? If not ALL of the sockets specified are not created. TODO
     */
    private function initializePorts(){        
        $list = explode( ',', Config::CLIENT_PORT_RANGE );
        foreach( $list AS $value ){
            if( strpos( $value, '-' ) ){
            
                $range = explode( '-', $value );
                // var_dump( $range );
                for( $i = $range[ 0 ]; $i <= $range[ 1 ]; ++$i ){
                    $socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
                    socket_bind( $socket, '127.0.0.1', $i );
                    socket_listen( $socket );
                    //array_push( $this->ports, $socket );
                    array_push( $this->ports, array( 'resource' => $socket, 'port_number' => $i, 'occupied' => false ) );
                }                           
               
            }else{
                // var_dump( $value );  
                $socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
                socket_bind( $socket, '127.0.0.1', $value );
                socket_listen( $socket );
                //array_push( $this->ports, $socket );
                array_push( $this->ports, array( 'resource' => $socket, 'port_number' => $value, 'occupied' => false ) );
            }                       
        }        
    }
    
    /**
     * Make a connection Using SQLite.
     * Create a database if haven't already done so.
     * If everything goes well the resource connection is stored.
     * 
     * @throws     
     */
    private function initializeDatabase(){
        $this->db_conn = new SQLite3( __DIR__ . '/meta.db', SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE );
        $this->db_conn->busyTimeout( 1 );
        $this->db_conn->exec( 'PRAGMA foreign_keys = ON;' );        
        $this->db_conn->exec( "
        CREATE TABLE IF NOT EXISTS Torrents(
            -- Info about the torrent its self
            info_hash         TEXT  PRIMARY KEY  NOT NULL,             
            name              TEXT               NOT NULL,
            pieces_length     INT                NOT NULL,
            pieces            BLOB               NOT NULL            
        );
        CREATE TABLE IF NOT EXISTS Storage(
            info_hash         TEXT               NOT NULL,
            destination       TEXT               NOT NULL, -- Location for download file
            FOREIGN KEY( info_hash ) REFERENCES Torrents( info_hash ) ON DELETE CASCADE,
            UNIQUE( info_hash )
        );        
        CREATE TABLE IF NOT EXISTS Files(
            info_hash         TEXT               NOT NULL,
            filename          TEXT                       , -- null means that the filename is the name in torrent
            filesize          INT                NOT NULL,
            FOREIGN KEY( info_hash ) REFERENCES Torrents( info_hash ) ON DELETE CASCADE,
            UNIQUE( info_hash, filename )
        );
        CREATE TABLE IF NOT EXISTS AnnounceUrls(
            info_hash         TEXT               NOT NULL,
            url               TEXT               NOT NULL,
            rank              INT   DEFAULT 0    NOT NULL, -- The rank of how good the tracker is in returning results
            FOREIGN KEY( info_hash ) REFERENCES Torrents( info_hash ) ON DELETE CASCADE,
            UNIQUE( info_hash, url )
        );
        CREATE TABLE IF NOT EXISTS Statistics(
            info_hash         TEXT               NOT NULL,            
            status            INT   DEFAULT 1    NOT NULL, -- 0 stop, 1 running
            download_speed    INT   DEFAULT 0    NOT NULL,
            upload_speed      INT   DEFAULT 0    NOT NULL,
            bytes_left        INT                NOT NULL,
            bytes_uploaded    INT   DEFAULT 0    NOT NULL,
            bytes_downloaded  INT   DEFAULT 0    NOT NULL,            
            FOREIGN KEY( info_hash ) REFERENCES Torrents( info_hash ) ON DELETE CASCADE,
            UNIQUE( info_hash )
        );
        ");        
    }
    
    /**
     * The creates A sort of interface for this bittorent.
     * By using a socket the client can be controlled or even
     * a gui wrapper can by use this mechinism.
     * 
     * @throws     
     */
    private function initializeInterface(){
        $socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
        socket_bind( $socket, '127.0.0.1', Config::CLIENT_INTERFACE_PORT );
        socket_listen( $socket );
        //socket_set_nonblock( $socket );
        $this->interface_conn = $socket;        
    }
    
    /**
     * Search our ports for an available not in use port
     * 
     * @returns a free port number
     */
    private function getAvailablePortNumber(){
        $unused_port;
        foreach( $this->ports as $port ){        
            if( !$port[ 'occupied' ] ){
                $unused_port = $port[ 'port_number' ];                
                break;
            }
        }  
        return $unused_port;
    }
    
    /**
     * Function where all the magic happens
     * 
     * @throws     
     */
    private function mainLoop(){
        $temp_announce_list = array(); // so i dont have to call
        $temp_peer_list = array(); // 
        $temp_links = array(); // The running links
    
        while( $this->is_running_flag ){
            // Step x. Lets see if there any actions to take. 
            $this->processCommands();      
                       
            
            
            // Step x. Lets see what torrent Needs seeding/leeching.            
            // Get torrents to working on
            $torrents = $this->getAvailableTorrents();
            
            if( empty( $torrents ) ){    
                // There are no torrents to work on
                sleep( 2 ); // dont need to overwork the CPU
                continue;
            }
            
            
            // For each torrent
            foreach( $torrents as $torrent ){     
                // TODO if the link aready exists for the torrent, then don't runn theas steps
                $announce_list = $this->getAnnounceUrls( $torrent[ "info_hash" ] );   
                
                // get the next available port to use,
                $port = $this->getAvailablePortNumber();
                
                // TODO make sure announcements follows the interval rule
                // TODO use statistics from the database                
                $tracker_response = $this->sendTrackerRequest( $torrent[ "info_hash" ], $announce_list[ 0 ], $port, $torrent );
                
                
                
                // links this client and the peers client
                // TODO error check for empty peers
                
                // The download partion
                if( $torrent[ "bytes_left" ] > 0 ){
                    // download
                    $link = $this->establishLinkConnection( $torrent[ "info_hash" ], $tracker_response[ 'peers' ][ 0 ] );
                    $temp_links = array_merge( $temp_links, $link );
                }else{
                    // upload
                
                }
                
                return;
                                    
                // make a handshake              
                //$status = $this->establishHandshake( $torrent[ "info_hash" ], $tracker_response[ 'peers' ][ 0 ] ); //FIXME
                $status = $this->establishHandshake( $link, $tracker_response[ 'peers' ][ 0 ] );
                //$this->requestKeepAlive( $link );
                //$this->requestUnChoke( $link );
                $this->requestRequest( $link, 0, $torrent[ "pieces_length" ] );
                
                
                //if( !$status ){
                //    continue;
               // }
                
                //$this->establishHandshake( $link );
                // The hanshak is made, what next?
                // Lets send a keep alive message
                //$this->requestKeepAlive( $tracker_response[ 'peers' ][ 0 ] );
                
                //var_dump( $tracker_response );
                // we have a responce and a list of peers
                // lets connect and download
            }
            
            
            
            
            break;
        } 
    }
    
    /**
     * Function that proccess any message from the interface socket.
     * Some number codes require a secondary option, that will be found
     * by continueing to read the socket( {number_code}{message_Length}{message} )
     * 
     * 
     * @throws     
     */
    private function processCommands(){
        $read = array( $this->interface_conn );
        $write = null;
        $except = null;
        
        if( socket_select( $read, $write, $except, 0 ) == 0 ){
            return;            
        }
        $client = socket_accept( $this->interface_conn );
        $request = socket_read( $client, 1 );                
        $number_code = current( unpack( 'C', $request ) );   
        
    
    
        switch( $number_code ){
            case Operation::SHUTDOWN:
                $this->is_running_flag = false;
                break;
            case Operation::ADD_TORRENT:
                // 1 add torrent info to database
                // 2 create storage for torrent
                
                $request = socket_read( $client, 4 ); 
                $length = current( unpack( 'N', $request ) );  
                $path = socket_read( $client, $length );                 
                $torrent_data = Torrent::getTorrentInfoFromFile( $path );
                $this->addTorrentToDatabase( $torrent_data );
                
                
                break;
            default:
                echo "operation unkown\n";
                break;        
        }
        socket_close( $client );
    }
    
    /**
     * Algorithm to get torrents that can be worked on right away.
     * This means torrent must be started...
     * @throws     
     * @returns A list of torrents will be returned 
     */
    private function getAvailableTorrents(){
        $torrent_list = array();
    
        $results = $this->db_conn->query( "SELECT * FROM Torrents
        INNER JOIN Statistics USING( info_hash )
        -- INNER JOIN AnnounceUrls USING( info_hash )
        WHERE status = 1
        ORDER BY RANDOM() LIMIT 3;" );
        while( $row = $results->fetchArray( SQLITE3_ASSOC ) ){
            array_push( $torrent_list, $row );
        }
        
        return $torrent_list;
    }
    
    /**
     * Using the database get all the announce urls for a specific torrent.
     *
     * @returns An array of urls from the torrent
     */
    private function getAnnounceUrls( $info_hash ){
        $announce_list = array();
        
        $stmt = $this->db_conn->prepare( "SELECT url FROM AnnounceUrls WHERE info_hash = ? ORDER BY rank DESC" );
        $stmt->bindParam( 1, $info_hash, SQLITE3_TEXT );  
        
        $results = $stmt->execute();
        while( $row = $results->fetchArray( SQLITE3_ASSOC ) ){
            array_push( $announce_list, $row[ "url" ] );
        }        
        
        $stmt->close();      
        
        return $announce_list;
    }
    
    
    /**
     * Add torrent and other things to the database
     * @throws     
     * @bug not a bug with this program but the phptracker. anounce is showing as an array
     */
    private function addTorrentToDatabase( $torrent_data, $destination = null, $status = 1 ){
        $info_hash = sha1( Bencode::encode( $torrent_data[ 'info' ] ), true );
        $count = 0; // makes life easier
        $total_length = 0; // 
        
    
        // Add some torrent info to database       
        $stmt = $this->db_conn->prepare( 'INSERT INTO Torrents( info_hash, name, pieces_length, pieces ) VALUES( ?, ?, ?, ? );' );  
        
        $count = 0;
        $stmt->bindParam( ++$count, $info_hash, SQLITE3_TEXT );        
        $stmt->bindParam( ++$count, $torrent_data[ 'info' ][ 'name' ], SQLITE3_TEXT );
        $stmt->bindParam( ++$count, $torrent_data[ 'info' ][ 'piece length' ], SQLITE3_INTEGER );
        $stmt->bindParam( ++$count, $torrent_data[ 'info' ][ 'pieces' ], SQLITE3_BLOB );
        $stmt->execute();
        $stmt->close();
        
        // Step x. Add file info to 'Files' table 
        $stmt = $this->db_conn->prepare( 'INSERT INTO Files( info_hash, filename, filesize ) VALUES( ?, ?, ? );' );  
        
        
        if( !isset( $torrent_data[ 'info' ][ 'files' ] ) ){
            // Single file Mode
            $total_length = $torrent_data[ 'info' ][ 'length' ];
            $count = 0;            
            $stmt->bindParam( ++$count, $info_hash, SQLITE3_TEXT );        
            $stmt->bindParam( ++$count, $torrent_data[ 'info' ][ 'name' ], SQLITE3_NULL );
            $stmt->bindParam( ++$count, $torrent_data[ 'info' ][ 'length' ], SQLITE3_INTEGER );
            $stmt->execute();            
        }else{
            // Multiple file mode
            foreach( $torrent_data[ 'info' ][ 'files' ] AS $file ){
                $path = implode( DIRECTORY_SEPARATOR, $file[ 'path' ] ); 
                
                $total_length += $file[ 'length' ];
                $count = 0;
                $stmt->bindParam( ++$count, $info_hash, SQLITE3_TEXT );        
                $stmt->bindParam( ++$count, $path, SQLITE3_TEXT );
                $stmt->bindParam( ++$count, $file[ 'length' ], SQLITE3_INTEGER );
                $stmt->execute();
            }            
        }       
        $stmt->close();
        
        
        // Step x. Add all announce urls to the database for the torrent
        $stmt = $this->db_conn->prepare( 'INSERT INTO AnnounceUrls( info_hash, url ) VALUES( ?, ? );' );  
        
        $announce_list = array( $torrent_data[ 'announce' ] );
        if( isset( $torrent_data[ 'announce-list' ] ) ){
            $announce_list = array_merge( $announce_list[0], $torrent_data[ 'announce-list' ][0] );            
            $announce_list = array_unique( $announce_list );
        }
        
        foreach( $announce_list AS $url ){            
            $count = 0;
            $stmt->bindParam( ++$count, $info_hash, SQLITE3_TEXT );        
            $stmt->bindParam( ++$count, $url, SQLITE3_TEXT );            
            $stmt->execute();
        }       
        $stmt->close();
        
        
        // Step x. Add initial Statistics to database
        $stmt = $this->db_conn->prepare( 'INSERT INTO Statistics( info_hash, bytes_left ) VALUES( ?, ? );' );          
        $count = 0;
        $stmt->bindParam( ++$count, $info_hash, SQLITE3_TEXT ); 
        $stmt->bindParam( ++$count, $total_length, SQLITE3_INTEGER ); 
        $stmt->execute();
        $stmt->close();
    }
    //----------------
    
    /**
     * Send request to tracker
     * @todo For now iam connecting to http trackers, 
     * but for later i wish to connect to udp trackers.
     * @param $info_hash torrent.
     * @param $url the url to the tracker.
     * @param $port port
     * @param $statistics 
     * @returns Tracker's response in dictionary form
     */
    private function sendTrackerRequest( $info_hash, $url, $port, $statistics ){
        // build $GET options to send to tracker
        $getdata = http_build_query(
            array(        
                'info_hash' => $info_hash,
                'peer_id' => $this->peer_id,
                'port' =>  $port,
                'uploaded' => $statistics[ 'bytes_uploaded' ],
                'downloaded' => $statistics[ 'bytes_downloaded' ],
                'left' => $statistics[ 'bytes_left' ]
            )
        ); 
        
        // parse url
        $url_comp = parse_url( $url );           
        $port = null;
        if( isset( $url_comp[ "port" ] ) ){
            $port = $url_comp[ "port" ];
        }            
        $new_url = $url_comp[ "scheme" ] . '://' . $url_comp[ "host" ] . $url_comp[ "path" ];
        
        // Send $GET options to tracker
        $curl = curl_init( $new_url . '?' . $getdata ); 
        curl_setopt( $curl, CURLOPT_PORT, $port ); 
        curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true ); 
        $tracker_response = curl_exec( $curl );
        curl_close( $curl );
            
        // parse response message
        return Bencode::decode( $tracker_response );
    }
    
    /**
     * This will link your client with the peer's client for a specific torrent.
     * One of our available server ports wil be logicaly connect with the peers, 
     * so we can communicate without interuptions. A TTL will also be set, by using 
     * unix time on last communicataction.
     *
     * @returns A link type array or false if problems encountered
     */
    private function establishLinkConnection( $info_hash, $peer_info ){
        // Get an available socket;
        $server_socket;
        foreach( $this->ports as &$port ){        
            if( !$port[ 'occupied' ] ){
                $server_socket = $port[ 'resource' ];
                $port[ 'occupied' ] = true;
                break;
            }
        }
        
        // create a socket for the peer
        $client_socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );        
        $result = socket_connect( $client_socket, $peer_info[ 'ip' ], $peer_info[ 'port' ] );
        
        $link1 = array(
            'info_hash' => $info_hash,
            'in_socket' => $server_socket,
            'out_socket' => $client_socket,
            'time' => time()
        );
        $link = array(
            "$info_hash" => array(
                'in_socket' => $server_socket,
                'out_socket' => $client_socket,
                'time' => time()
            )           
        );
        
        //var_dump( $link );
        return $link;
    }
    
    /**
     * Make a handshake with the peer.
     * @bug the returning message must return a lenth of 49+19, 
     * but i need to do 281 to clear the buffer. The might be a problem with the 
     * old tracker
     * @todo If compact mode is own then i dont have to validate peer id also
     *
     * @param $info_hash Torrent     
     * @param $peer_info The subarray 'peers' from tracker response
     * @returns True if successful
     */
     private function establishHandshake( $link, $peer_info ){
     
        $socket = current( $link )[ 'out_socket' ];   
            
        // Signal for handshake
        $signal =
            pack( 'C', 19 ) .                          // Length of protocol string.
            'BitTorrent protocol' .                    // Protocol string.
            pack( 'a8', '' ) .                         // 8 void bytes.
            key( $link ) .                             // Echoing the info hash that the client requested.
            pack( 'a20', $this->peer_id )              // Our peer id.
        ;
                       
        // send the handshake
        socket_write( $socket, $signal, strlen( $signal ) );         
        socket_recv( $socket, $peer_response , 281, MSG_WAITALL ); // WARNING
        
        // evaluate reponse.
        $expected_response = 
            pack( 'C', 19 ) . 'BitTorrent protocol' . 
            pack( 'a8', '' ) . key( $link ) . 
            pack( 'a20', $peer_info[ 'peer id' ] );
        
        //echo $peer_response;
        //socket_write( $socket, pack( 'NC', 1, 0 ), 5 );
        if( strncmp( $peer_response, $expected_response, 68 ) == 0 ){
            // Good handshake            
            return true;
        }else{
            // Bad handshake
            return false;
        }
     }
     private function recieveHandshake( $info_hash, $peer_info ){
     }
     
     //------
     
     /**
      * Send Keep alive.
      */
     private function requestKeepAlive( $link ){       
        socket_write( current( $link )[ 'out_socket' ], pack( 'N', 0 ), 4 );
     }
     
     /**
      * Send choke.
      */
     private function requestChoke( $link ){       
        socket_write( current( $link )[ 'out_socket' ], pack( 'NC', 1, 0 ), 5 );
     }
     
     /**
      * Send unchoke.
      */
     private function requestUnChoke( $link ){       
        socket_write( current( $link )[ 'out_socket' ], pack( 'NC', 1, 1 ), 5 );
     }
     
     // NOTE Iam note sure i want some of theses requests.
     
     /**
      * requestRequest ? lol
      * Request a piece and wait for it.
      * @returns the piece
      */
     private function requestRequest( $link, $index, $piece_length ){   
        $socket = current( $link )[ 'out_socket' ];
        $message = pack( 'NCNNN', 13, 6, $index, 0, $piece_length );
        socket_write( $socket, $message, strlen( $message ) );
        
        // response       
        socket_recv( $socket, $message_len , 4, MSG_WAITALL );            
        $message_len = current( unpack( 'N', $message_len ) );
        
        socket_recv( $socket, $message_id , 1, MSG_WAITALL );            
        $message_id = current( unpack( 'C', $message_id ) );
        
        socket_recv( $socket, $payload_idx , 4, MSG_WAITALL );            
        $payload_idx = current( unpack( 'N', $payload_idx ) );
                                
        socket_recv( $socket, $payload_begin , 4, MSG_WAITALL );            
        $payload_begin = current( unpack( 'N', $payload_begin ) );
        
        socket_recv( $socket, $block, $message_len - 9, MSG_WAITALL );   
        
        echo $block;
        return $block;
     }
     
     
    
}













