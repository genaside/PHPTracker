<?php



/**
 * Handles files for the bittorent client.
 * The contents of the directory will be treated
 * as one continuous file.
 */
class Storage{
    
    /**
     * The full path to the directory or file
     * @var string
     */
    private $path;
    
    /**
     * Multiple file mode flag
     * @var bool
     */
    private $mfm_flag = false;
    
    
    
    /**
     * Create storage passed on the type of file mode in the
     * torrent
     * @param $path The directory path to store the downloaded data in.
     * @param $torrent_info 
     *
     * @returns false if unsuccessful 
     */
    public function createStorage( $path, $torrent_info ){
        
        $info = new SplFileInfo( $path );
        if( !$info->isDir() ){
            echo "$path is not a directory";
        }
        
        // must of read/write access to the directory        
        if( !( $info->isReadable() && $info->isWritable() ) ){
            echo "read/write access denied to $path";
            //throw new Exception( "Download destination, permission denied." );
        }
        
        if( isset( $torrent_info[ 'info' ][ 'files' ] ) ){
            $mfm_flag = true;
        }
        
        if( !$mfm_flag ){
            $this->createFile( $path, $torrent_info[ 'info' ][ 'name' ], $torrent_info[ 'length' ] );
        }else{
        
        }
        
    }
    
    /**
     * Create single file and fill up its size
     * @param $path
     * @param $file_name
     * @param $file_size This will automaticly fill the file
     * @returns False if file creation fails
     */
    private function createFile( $path, $file_name, $file_size  ){
        if( !file_exists( $path . DIRECTORY_SEPARATOR . $file_name ) ){  
        
            $file_handle = fopen( $working_file, "wb" );
            fseek( $file_handle, $file_size - 1, SEEK_CUR );
            fwrite( $file_handle, "a" );
            fclose( $file_handle );
        }
    }
    
    /**
     * Create the directories and files for 
     * Mutiple file mode
     * @param $path
     * @param $structure
     * @returns False if file creation fails
     */
    private function createDirectoryStructure( $path, $file_name, $file_size  ){
    }
    
    
    /**
     * Create the directories and files for 
     * Mutiple file mode
     * @param $path
     * @param $structure
     * @returns False if file creation fails
     */
    public function write( $data  ){
    }
    
    
    //____________
    
    /**
     *
     */
    public function getNextAvailablePiece(){
    }
    
    /**
     *
     */
    public function hashCheck(){
    }
    
    
    
    
    



}