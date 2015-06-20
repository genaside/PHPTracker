CREATE TABLE IF NOT EXISTS `phptracker_peers_test` (
  `peer_id`           binary(20)            NOT NULL  COMMENT 'Peer unique ID.',
  `ip_address`        int(10) unsigned      NOT NULL  COMMENT 'IP address of the client.',
  `port`              smallint(5) unsigned  NOT NULL  COMMENT 'Listening port of the peer.',
  `info_hash`         binary(20)            NOT NULL  COMMENT 'Info hash of the torrent.',
  `bytes_uploaded`    int(10) unsigned  DEFAULT NULL  COMMENT 'Uploaded bytes since started.',
  `bytes_downloaded`  int(10) unsigned  DEFAULT NULL  COMMENT 'Downloaded bytes since started.',
  `bytes_left`        int(10) unsigned  DEFAULT NULL  COMMENT 'Bytes left to download.',  
  
  -- `event_status`      enum( '', 'started', 'stopped', 'completed' )  NOT NULL  DEFAULT 0  COMMENT 'Activity status of the torrent.',
  `last_request`      timestamp         NOT NULL  DEFAULT CURRENT_TIMESTAMP  COMMENT 'Timestamp when peer last made a request to tracker.',  
  
  PRIMARY KEY( `peer_id`, `info_hash` ),
  INDEX ( `info_hash` ),
  INDEX ( `bytes_left` )  
) ENGINE=InnoDB COMMENT='Current peers for torrents.';