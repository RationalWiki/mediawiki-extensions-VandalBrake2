<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

// time limit, default 30 minutes
$wgVandalBrakeConfigLimit = 1800;
// time limits for non vandaled users
$wgVandalBrakeConfigAnonLimit = 30;
$wgVandalBrakeConfigUserLimit = 15;

// give sysops the right to bin users
$wgGroupPermissions['sysop']['vandalbin'] = true;

// which rights to remove from a vandal:
$wgVandalBrakeConfigRemoveRights = [ 'move', 'skipcaptcha', 'rollback' ];
// which rights to limit by the edit limit (i.e. they will be available only if more than $wgVandalBrakeConfigLimit seconds have passed since the user's last action):
$wgVandalBrakeConfigLimitRights = [];

$wgExtensionCredits['other'][] = [
	'name' => 'VandalBrake2',
	'author' => '[http://rationalwiki.com/wiki/User:Nx Nx]',
	'url' => 'http://rationalwiki.com',
	'description' => 'Limits the editing rate of vandals'
];

// Installer
$wgHooks['LoadExtensionSchemaUpdates'][] = 'VandalBrake::onLoadExtensionSchemaUpdates';

// Edit hook
$wgHooks['EditFilterMergedContent'][] = 'VandalBrake::onEditFilterMergedContent';

// Account creation hook
$wgHooks['AbortNewAccount'][] = 'VandalBrake::onAccountCreation';

// add vandal link to Special:Contributions and recent changes
//$wgHooks['OldChangesListRecentChangesLine'][] = 'VandalBrake::onRC'; //FIXME: Doesn't work
$wgHooks['ContributionsToolLinks'][] = 'VandalBrake::onContributionsToolLinks';

// Remove certain user rights from vandals
$wgHooks['UserGetRights'][] = 'VandalBrake::onUserGetRights';

// Handle user renames
$wgHooks['RenameUserSQL'][] = 'VandalBrake::onRenameUserSQL';
$wgHooks['RenameUserLogs'][] = 'VandalBrake::onRenameUserLogs';

// Dynamically asign the vandalbrake user group
// $wgHooks['UserEffectiveGroups'][] = 'VandalBrake::onUserEffectiveGroups';

// Dynamically alter the block status
// $wgHooks['GetBlockedStatus'][] = 'VandalBrake::onGetBlockedStatus';

// Register special pages
$wgSpecialPages['VandalBrake'] = 'SpecialVandal';
$wgSpecialPages['VandalBin'] = 'SpecialVandalbin';

// Create new log type
$wgLogTypes[] = 'vandal';
$wgLogNames['vandal'] = 'vandallogname';
$wgLogHeaders['vandal'] = 'vandallogheader';
$wgLogActionsHandlers['vandal/parole'] = 'VandalBrake::vandallogparolehandler';
$wgLogActionsHandlers['vandal/vandal'] = 'VandalBrake::vandallogvandalhandler';

// Modify vandal log lines to show parole link
$wgHooks['LogLine'][] = 'VandalBrake::onLogLine';

// include path
$wgVandalBrakeIP = __DIR__;
$wgExtensionMessagesFiles['VandalBrake'] = "$wgVandalBrakeIP/VandalBrake2.i18n.php";

// Load classes
$wgAutoloadClasses['VandalBrake'] = "$wgVandalBrakeIP/VandalBrake2.body.php";
$wgAutoloadClasses['SpecialVandal'] = "$wgVandalBrakeIP/VandalBrake2.body.php";
$wgAutoloadClasses['SpecialVandalbin'] = "$wgVandalBrakeIP/VandalBrake2.body.php";

