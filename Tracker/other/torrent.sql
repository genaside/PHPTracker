CREATE TABLE IF NOT EXISTS `phptracker_torrents_test` (
  `info_hash` binary(20)  NOT NULL             COMMENT 'Info hash.', 
  `status`    TINYINT(1)  NOT NULL  DEFAULT 1  COMMENT 'Activity status of the torrent.',
  PRIMARY KEY (`info_hash`)
) ENGINE=InnoDB COMMENT='Table to store basic torrent file information upon creation.';