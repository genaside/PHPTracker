<?php

/**
 * Object providing operations to a physical file on the disk.
 *
 * @package PHPTracker
 * @subpackage File
 */
class PHPTracker_File_File
{
    /**
     * Full path of the file on the disk.
     *
     * @var string
     */
    protected $path;

    /**
     * If the file os opened for reading, this contains its read handle.
     *
     * @var resource
     */
    protected $read_handle;
    
    /**
    * iterator for scanning files in a directory tree.  
    *
    * @param iterator $iter
    */ 
    protected $iter;   
     
    /**
     * Initializing the object with the file full path.
     *
     * @throws PHPTracker_File_Error_NotExits If the file does not exists.
     * @throws PHPTracker_File_Error_EmptyDir If the directory is empty.
     * @param string $path
     */ 
    public function  __construct( $path )
    {
        $this->path = $path;
        $this->shouldExist();

        $this->path = realpath( $this->path );
        
        if( $this->isMultipleFile() )
        {
            $this->iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( 
		    $this->path, 
		    RecursiveDirectoryIterator::SKIP_DOTS |
		    FilesystemIterator::CURRENT_AS_FILEINFO
		),
		RecursiveIteratorIterator::SELF_FIRST
	    );
	    
	    $this->shouldContainValidFile();
        }
    }

    /**
     * Returnt eh file full path is the object is used as string.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->path;
    }

    /**
     * If the file is open for reading, it's properly closed while destructing.
     */
    public function  __destruct()
    {
        if ( isset( $this->read_handle ) )
        {
            fclose( $this->read_handle );
        }
    }

    /**
     * Tells if the file exists on the disk.
     *
     * @return boolean
     */
    protected function exists()
    {
        return file_exists( $this->path );
    }
    
    /**
     * Tells whether this is a single regular file,     
     *
     * @return boolean
     */
    public function isSingleFile()
    {
        return is_file( $this->path );
    }
    
    /**
     * Tell if the path is a directory, so that multifile mode can be assumed.
     *
     * @return boolean
     */
    public function isMultipleFile()
    {
        return is_dir( $this->path );
    }

    /**
     * Tells the size of the file in bytes or the total size of all files in the directory tree.
     *
     * @return integer
     */
    public function size()
    {
	if( $this->isSingleFile() )
	{
	    if ( false === ( $size = @filesize( $this->path ) ) )
            {
                throw new PHPTracker_File_Error_Unreadable( "File $this is unreadable." );
            }	
	}
	elseif( $this->isMultipleFile() )
	{
	    $total = 0;
	    foreach ( $this->iter as $path )
	    {
		if( $this->isValidFile( $path ) )
		{		   	    
		    $total += $path->getSize();			      
		}
	    } 
	    $size = $total;	
	}
	else
	{
	    // No symlinks please
	    throw new PHPTracker_File_Error_Unreadable( "File $this is not a regular file nor is it a directory." );
	}            
        
        return $size;
    }    
    
    /**
     * Return the files in a directory tree and 
     * repersents it as the 'files' structure(bencode) in the torrent
     *
     * @return array 
     */
    public function getFilesForTorrent()
    {        
	$paths = array();
	foreach ( $this->iter as $path )
	{ 
	    if( $this->isValidFile( $path )  )
	    {
		  $relative_path = str_replace( $this->path . DIRECTORY_SEPARATOR, "", $path->getRealPath() );		  
		  $temp = array(                                 
		      'path'   => explode( DIRECTORY_SEPARATOR, $relative_path ),
		      'length' => $path->getSize()
		  );                             
		  array_push( $paths, $temp );
	    }
	}                  
	return $paths;
    }    
    
    /**
     * Function to reduce code. 
     * Check if a file is valid for a torrent
     *
     * @param SplFileInfo info of the file
     * @throws PHPTracker_File_Error_Unreadable If the file can't be read.     
     * @return bool 
     */
    private function isValidFile( $file_info )
    {   
        if( !$file_info->isFile() )
        {
            // Not a file, non files will be skipped          
            return false;
        }
        
	if( !$file_info->isReadable() )
	{	    
	    throw new PHPTracker_File_Error_Unreadable( "File $this is unreadable." );
	} 
	
	if( !$file_info->getSize() > 0 )
	{
	    // File length is Zero, Empty files will be skipped.
	    return false;
	}	
	return true;
    }

    /**
     * Returns the basename of the file.
     *
     * @return string
     */
    public function basename()
    {
        return basename( $this->path );
    }

    /**
     * Generates SHA1 hashes of each piece of the file or a each piece from a file list.
     *
     * @param integer $size_piece Size of one piece of a file on bytes.
     * @return string Byte string of the concatenated SHA1 hashes of each pieces.
     */
    public function getHashesForPieces( $size_piece )
    {   
        $size_piece = intval( $size_piece );
        if ( $size_piece <= 0 )
        {
            // TODO: Throwing exception? 
            // This is already checked in the torrent constructor, so this might not be necessary 
            return null;
        }
        
        $c_pieces = ceil( $this->size() / $size_piece );
        $hashes = '';        
    
        if( $this->isSingleFile() )
        {
            for ( $n_piece = 0; $n_piece < $c_pieces; ++$n_piece )
	    {
		$hashes .= $this->hashPiece( $n_piece, $size_piece );
	    }
        }
        else
        {	    
	    for ( $n_piece = 0; $n_piece < $c_pieces; ++$n_piece )
	    {
		$data = $this->readDirectory( $n_piece * $size_piece, $size_piece );		
		$hashes .= sha1( $data, true );		
	    }	   
        }
        
        return $hashes;
    }
    
    
    /**
     * Reads one arbitrary length chunk of a file beginning from a byte index.
     *
     * @param integer $begin Where to start reading (bytes).
     * @param integer $length How many bytes to read.
     * @return string Binary string with the read data.
     */
    public function readBlock( $begin, $length )
    {	
        if( $this->isMultipleFile() )
        {
	    $buffer = $this->readDirectory( $begin, $length );
        }
        else
        {
            $file_handle = $this->getReadHandle();        

	    fseek( $file_handle, $begin );
	    if ( false === $buffer = @fread( $file_handle , $length ) )
	    {
		throw new PHPTracker_File_Error_Unreadable( "File $this is unreadable." );
	    }	           
        }
        return $buffer;         
    }
    
    /**
     * Treats all files in a directory tree as one file continuous file
     *
     * @param integer $begin Where to start reading (bytes).
     * @param integer $length How many bytes to read.
     * @return string Binary string with the read data.
     */
    public function readDirectory( $begin, $length )
    {
        // NOTE i have to creat another iter cuase it doesnt rewind well or something
        // need to check why
        $iter = new RecursiveIteratorIterator(
	    new RecursiveDirectoryIterator( 
		$this->path, 
		RecursiveDirectoryIterator::SKIP_DOTS |
		FilesystemIterator::CURRENT_AS_FILEINFO
	    ),
	    RecursiveIteratorIterator::SELF_FIRST
	);	    
        // First lets seek.
        $iter->rewind();   
                
        $seek = $begin; // seek till 0
       
        while( $iter->valid() ) // if i ever hit this then its a problem
        {   
            $cfile = $iter->current();  
            if( !$this->isValidFile( $cfile ) )
            {
                $iter->next();
                continue;
            }
                        
            if( $seek >= ( $cfile_size = $cfile->getSize() ) ){
                // Seek is greater than or equal the size of this file, so adjust seek and goto next file               
	      $seek -= $cfile_size;      
	    }else{
	        // the file support the current size of seek 
	      break;	      
	    }    	    
	    $iter->next();	    
        }
        
               
        // now read       
        $hasleft = $length; // how much left to read 
        $buffer = ''; // read data
        
        while( $iter->valid() && ( $hasleft > 0 ) )
        {   
            $cfile = $iter->current();
            
            if( !$this->isValidFile( $cfile ) )
            {
                $iter->next();
                continue;
            }
            
            $cfile_size = $cfile->getSize();
            
            // Open up the file last left off
            $file_handle = fopen( $cfile->getRealPath(), 'rb' );
            // Now in that file we run the remaining seek            
            fseek( $file_handle, $seek );
                        
            //echo $cfile_size . ' ' . $seek . ' vs ' . ( $hasleft ) . "\n"; 
            if( ( $diff = $cfile_size - $seek ) < $hasleft ){ 
                // if the remaining file data is less than the remaining bytes to read
                // read whats left, and goto the next file
                $buffer = fread( $file_handle , $diff );
                $seek = 0; // I dont need this no more for this loop                
                $hasleft -= $diff;
            }else{
	        // The file has way more than than any remaining read length
                $buffer .= fread( $file_handle , $hasleft );                
                $hasleft = 0;
                fclose( $file_handle );
                break; // all done
            }
            
            fclose( $file_handle );
            $iter->next();
        }
                        
        return $buffer;
    }    

    /**
     * Lazy-opens a file for reading and returns its resource.
     *
     * @throws PHPTracker_File_Error_Unreadable If the file can't be read.
     * @return resource
     */
    protected function getReadHandle()
    {
        if ( !isset( $this->read_handle ) )
        {
            $this->read_handle = @fopen( $this->path, 'rb' );

            if ( false === $this->read_handle )
            {
                unset( $this->read_handle );
                throw new PHPTracker_File_Error_Unreadable( "File $this is unreadable." );
            }

        }
        return $this->read_handle;
    }

    /**
     * Gets SHA1 hash (binary) of a piece of a file.
     *
     * @param integer $n_piece 0 bases index of the current peice.
     * @param integer $size_piece Generic piece size of the file in bytes.
     * @return string Byte string of the SHA1 hash of this piece.
     */
    protected function hashPiece( $n_piece, $size_piece )
    {
        $file_handle = $this->getReadHandle();
        $hash_handle = hash_init( 'sha1' );

        fseek( $file_handle, $n_piece * $size_piece );
        hash_update_stream( $hash_handle, $file_handle, $size_piece );

        // Getting hash of the piece as raw binary.
        return hash_final( $hash_handle, true );
    }

    /**
     * Throws exception if the file does not exist.
     *
     * @throws PHPTracker_File_Error_NotExits
     */
    protected function shouldExist()
    {
        if ( !$this->exists() )
        {
            throw new PHPTracker_File_Error_NotExits( "File $this does not exist." );
        }
    }
    
    /**
     * Throws exception if the directory does not contain at least one valid file
     *
     * @throws PHPTracker_File_Error_EmptyDir
     */
    protected function shouldContainValidFile()
    {
        foreach ( $this->iter as $path )
	{ 
	    if( $this->isValidFile( $path )  )
	    {
	        return;
	    }
	}
	throw new Exception( "Directory $this Doesn't have at least one valid file ." );
    }
}
