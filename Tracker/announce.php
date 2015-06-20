<?php


require_once( __DIR__ . '/config/config.php' );
require_once( __DIR__ . '/bencode/bencode.php' );


// WARNING this is just a rough draft for testing


// Set up an example peer
// First announce
$_GET = array();
$_GET[ 'info_hash' ] = "00000111112222233333";
$_GET[ 'peer_id' ] = "00000111112222233333";
$_GET[ 'uploaded' ] = 0;
$_GET[ 'downloaded' ] = 0;
$_GET[ 'left' ] = 0;
$_GET[ 'port' ] = 6222;
$_SERVER[ 'REMOTE_ADDR' ] = "127.0.0.1";
// other
//$_GET[ 'compact' ] = 1;
// Second announce


/**
 * The Tracker
 * @todo maybe move all the validator functions
 */
class Tracker{
    /**
     * A MySQL Database connection
     * @var resource
     */
    private $db_conn;
    
    public function __construct(){
        // Step 1. validate mandatory options 
        $this->validateHashInfo();
        $this->validatePeerID();
        $this->validateUploadedValue();
        $this->validateDownloadedValue();
        $this->validateLeftValue();
        $this->validatePort();
        
        // Ok, good. open database
        $this->createDatabaseConnection();
        
        // Step 2. Check if the peer already made a request before.
        $last_request_time = $this->peerExistsForTorrent();
        $this->checkPeerInterval( $last_request_time );
                
        // Step 3. Check if the client id is supported by us
        //TODO
        
        // Step 4. Check if we have the torrent(info_hash) in the database
        $this->torrentExists();
        
        // Step 5. Give the requesting the tracker's response
        $this->buildResponse();
        
        // Step 6. Add the peer to the database
        $this->storePeer();
        
    }
    public function __destruct(){
        $this->closeDatabaseConnection();
    }
    
    
    /**
     * Start database connection.
     * 
     * @throws 
     */
    private function createDatabaseConnection(){     
        $this->db_conn = new mysqli( 
            Config::DB_SERVER_HOST, 
            Config::DB_SERVER_USERNAME, 
            Config::DB_SERVER_PASSWORD, 
            Config::DB_SERVER_NAME 
        );
        
        if( $this->db_conn->connect_error ){
            die( "Connection failed: " . $conn->connect_error );
        }     
        
        mysqli_report(MYSQLI_REPORT_ALL); // For testing
    }
    
    /**
     * Close database connection.
     * 
     * @throws 
     */
    private function closeDatabaseConnection(){     
        $this->db_conn->close();
    }
    
    
    /**
     * Check if the peer is already stored for a specific torrent(info_hash) in the database.     
     * 
     * @throw 
     * @returns If exists then return the last time peer made a request, 
     * or false for otherwise
     */
    private function peerExistsForTorrent(){            
        $stmt = $this->db_conn->prepare( "SELECT UNIX_TIMESTAMP(last_request) FROM phptracker_peers_test WHERE peer_id = ? AND info_hash = ?" );
        if( !$stmt ){
            throw new Exception( "Prepare statement failed." );
        }
        
        $stmt->bind_param( "ss", $_GET[ 'peer_id' ], $_GET[ 'info_hash' ] );
        $stmt->execute();      
        
        $stmt->bind_result( $last_request_time );
        if( !$stmt->fetch() ){
            return false;
        }
        
        return $last_request_time;
    }
    
    /**
     * To prevent spamming check if the peer is making requests at the minimum interval.
     * @todo peers that spam might need to be reported, to the admin.     * 
     * 
     * @param $last_request_time the peer's last request time
     */
    private function checkPeerInterval( $last_request_time ){            
        if( time() - $last_request_time < Config::MIN_INTERVAL ){
            // Yes this is not following rules
            die( "Error: request made sooner than min_interval" );
        }       
    }
    
    
    
    /**
     * Using $_GET, validate hash_info
     *
     * @note Validating rules: hash_info must exist and be 20 bytes long
     */
    private function validateHashInfo(){        
        if( !isset( $_GET[ 'info_hash' ] ) ){
            $this->announceFailure( "Missing info_hash." );
        }
        if( strlen( $_GET[ 'info_hash' ] ) != 20 ){
            $this->announceFailure( "Invalid infohash: infohash is not 20 bytes long." );
        }        
    }
    
    /**
     * Using $_GET, validate peer_id
     *
     * @note Validating rules: peer_id must exist and be 20 bytes long
     */
    private function validatePeerID(){
        
        if( !isset( $_GET[ 'peer_id' ] ) ){
            $this->announceFailure( "Missing peer_id" );
        }
        if( strlen( $_GET[ 'peer_id' ] ) != 20 ){
            $this->announceFailure( "   Invalid peerid: peerid is not 20 bytes long." );
        }        
    }
    
    /**
     * Using $_GET, validate uploaded
     *
     * @note Validating rules: uploaded must exist and be a non-negative number
     */
    private function validateUploadedValue(){        
        if( !isset( $_GET[ 'uploaded' ] ) ){
            $this->announceFailure( "Missing get parameter. uploaded" );
        }
        if( !( is_int( $_GET[ 'uploaded' ] ) && $_GET[ 'uploaded' ] > -1 ) ){
            $this->announceFailure( "Invalid uploaded value." );
        }        
    }
    
    /**
     * Using $_GET, validate downloaded
     *
     * @note Validating rules: downloaded must exist and be a non-negative number
     */
    private function validateDownloadedValue(){        
        if( !isset( $_GET[ 'downloaded' ] ) ){
            $this->announceFailure( "Missing get parameter. downloaded" );
        }
        if( !( is_int( $_GET[ 'downloaded' ] ) && $_GET[ 'downloaded' ] > -1 ) ){
            $this->announceFailure( "Invalid downloaded value." );
        }        
    }
    
    /**
     * Using $_GET, validate left
     *
     * @note Validating rules: left must exist and be a non-negative number
     */
    private function validateLeftValue(){        
        if( !isset( $_GET[ 'left' ] ) ){
            $this->announceFailure( "Missing get parameter. left" );
        }
        if( !( is_int( $_GET[ 'left' ] ) && $_GET[ 'left' ] > -1 ) ){
            $this->announceFailure( "Invalid left value." );
        }        
    }    
    
    /**
     * Using $_GET, validate port
     *
     * @note Validating rules: port must exist and be a between 0 and 65535
     */
    private function validatePort(){        
        if( !isset( $_GET[ 'port' ] ) ){
            $this->announceFailure( "Missing port." );
        }
        if( !( is_int( $_GET[ 'port' ] ) && $_GET[ 'port' ] > -1 && $_GET[ 'port' ] < 65535 ) ){
            $this->announceFailure( "Invalid port value." );
        }        
    }   
    
    
    /**
     * Using $_GET, validate numwhat
     *
     * @note Validating rules: numwhat must exist, be positive, and above 0
     * @return false if numwhat fails validation, true other wise.
     */
    private function validateNumwhat(){        
        if( !isset( $_GET[ 'numwhat' ] ) ){
            return false;
        }
        if( !( is_int( $_GET[ 'numwhat' ] ) && $_GET[ 'numwhat' ] > 0 ) ){
            return false;
        }        
        return true;
    } 
    
    /**
     * Using $_GET, validate compact
     *
     * @note Validating rules: compact must exist, and be equal to 1
     * @return false if compact fails validation, true other wise.
     */
    private function validateCompact(){        
        if( !isset( $_GET[ 'compact' ] ) ){
            return false;
        }
        if( !( is_int( $_GET[ 'compact' ] ) && $_GET[ 'compact' ] == 1 ) ){
            return false;
        }        
        return true;
    } 
    
        
    /**
     *
     * @param string $messege the error messege to display
     */
    private function announceFailure( $messege ){
        $response = Bencode::encode( array(
            'failure reason' => $messege
        ));
        echo $response;
        exit;
    }
    
    /**
     * Look in the database to find if the torrent exists. 
     *     
     */
    private function torrentExists(){        
        $stmt = $this->db_conn->prepare( "SELECT status FROM phptracker_torrents_test WHERE info_hash = ?" );
        $stmt->bind_param( "s", $_GET[ 'info_hash' ] );
        $stmt->execute();
        $stmt->bind_result( $status );
        if( !$stmt->fetch() ){
            $this->announceFailure( "Torrent does not exists." );
        }        
        if( $status == 0 ){
            $this->announceFailure( "Torrent is no long active." );
        }      
        $stmt->close();
        
        return true;
    }
    
    /**
     * Get all peers, but to a limit. If numwhat is present used that for a limit.
     * 
     *
     *
     * @todo This shouldn't be simple as to get the first x amount of peers 
     * straight from the database. I think completed peers have a lot of heart 
     * to give. Maybe order the query to show all complete first the complete.
     * oh byte_left.
     * @todo what about private tracking?
     *
     * @returns A dictionary of peers or binary model when compact = 1 according to the specs
     */
    private function getPeers(){
        // Step 1. validate numwant then assign numwhat
        $numwhat = 0;
        if( $this->validateNumwhat() ){
            $numwhat = $_GET[ 'numwhat' ];
        }else{
            $numwhat = Config::DEFAULT_NUMBER_OF_PEERS;
        }        
        
        $stmt = $this->db_conn->prepare( "SELECT peer_id, ip_address, port FROM phptracker_peers_test 
        WHERE info_hash = ? ORDER BY bytes_left ASC LIMIT ?" );
        $stmt->bind_param( "si", $_GET[ 'info_hash' ], $numwhat );        
        $stmt->execute();
        $stmt->bind_result( $peer_id, $peer_ip, $peer_port );
        
        $peer_list;        
        if( $this->validateCompact() ){
            // compact mode
            $peer_list = "";
            while( $stmt->fetch() ){
                $peer_list .= pack( "Nn", $peer_ip, $peer_port );
            } 
        }else{
            // list mode
            $peer_list = array();
            while( $stmt->fetch() ){
                $temp = array(
                    "peer id" => $peer_id,
                    "ip" => long2ip( $peer_ip ),
                    "port" => $peer_port
                );
                array_push( $peer_list, $temp );
            }         
        }
        $stmt->close();
        
        return $peer_list;
    }
    
    /**
     * Build a  tracker response
     *
     * @returns bencode string
     */
    private function buildResponse(){        
        //var_dump($this->getPeers());
        //
        $response = Bencode::encode( array(
            'interval'     => Config::INTERVAL,
            'min interval' => Config::MIN_INTERVAL,
            'tracker id'   => Config::TRACKER_ID,
            'complete'     => 2,
            'incomplete'   => 10,
            'peers'        => $this->getPeers(),           
        ));
        
        return $response;
    }
    
    /**
     * Put peer in the Database
     *
     * @returns bencode string
     */
    private function storePeer(){        
        $stmt = $this->db_conn->prepare( "REPLACE INTO 
        phptracker_peers_test( peer_id, ip_address, port, info_hash, bytes_uploaded, bytes_downloaded, bytes_left ) 
        VALUES( ?, ?, ?, ?, ?, ?, ? );" );        
        
        $ip = ip2long( $_SERVER[ 'REMOTE_ADDR' ] );
        $stmt->bind_param( 
            "siisiii", 
            $_GET[ 'peer_id' ],
            $ip,
            $_GET[ 'port' ],
            $_GET[ 'info_hash' ],
            $_GET[ 'uploaded' ],
            $_GET[ 'downloaded' ],
            $_GET[ 'left' ]
        );
        $stmt->execute();
        $stmt->close();       
    }
    

}

$track = new Tracker;





// 

