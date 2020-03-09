create table /*$wgDBprefix*/vandals (
	vand_id int NOT NULL auto_increment,
	vand_address tinyblob NOT NULL,
	vand_user int unsigned NOT NULL default '0',
	vand_by int unsigned NOT NULL default '0',
	vand_reason tinyblob NOT NULL,
	vand_timestamp binary(14) NOT NULL default '',
	vand_account bool NOT NULL default 0,
	vand_autoblock bool NOT NULL default 0,
	vand_anon_only bool NOT NULL default 0,
	vand_auto bool NOT NULL default 0,
	PRIMARY KEY vand_id (vand_id),
	INDEX vand_user (vand_user)
) /*$wgDBTableOptions*/;
