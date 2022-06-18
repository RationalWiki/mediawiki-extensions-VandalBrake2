<?php

namespace MediaWiki\Extension\VandalBrake;

use ActorMigration;
use Content;
use DatabaseUpdater;
use EditPage;
use IContextSource;
use Linker;
use LogPage;
use MediaWiki\Block\SystemBlock;
use MediaWiki\MediaWikiServices;
use MWException;
use Skin;
use SpecialPage;
use Status;
use Title;
use User;


class VandalBrake {

	/**
	 * Installer hook
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'vandals', __DIR__ . '/../sql/vandals.sql' );
	}

	//logging

	/**
	 * vandal/parole log handler
	 *
	 * @param string $type
	 * @param string $action
	 * @param Title|null $title
	 * @param Skin|null $skin
	 * @param array $params
	 * @param bool $filterWikilinks
	 * @return string
	 */
	public static function vandallogparolehandler( $type, $action, $title = null, $skin = null,
		$params = [], $filterWikilinks = false
	) {
		if ( !$skin ) {
			return wfMessage( 'vandallogparole' )->rawParams( $title->getPrefixedText() )->escaped();
		}

		if ( substr( $title->getText(), 0, 1 ) == '#' ) {
			$titleLink = $title->getText();
		} else {
			$id = User::idFromName( $title->getText() );
			$titleLink = Linker::userLink( $id, $title->getText() )
				. Linker::userToolLinks( $id, $title->getText(), false, Linker::TOOL_LINKS_NOBLOCK );
		}
		return wfMessage( 'vandallogparole' )->rawParams( $titleLink )->escaped();
	}

	/**
	 * vandal/vandal log handler
	 *
	 * @param string $type
	 * @param string $action
	 * @param Title|null $title
	 * @param Skin|null $skin
	 * @param array $params
	 * @param bool $filterWikilinks
	 * @return string
	 */
	public static function vandallogvandalhandler( $type, $action, $title = null, $skin = null,
		$params = [], $filterWikilinks = false
	) {
		if ( !$skin ) {
			return wfMessage( 'vandallogvandal' )->rawParams( $title->getPrefixedText() )->escaped();
		}
		$id = User::idFromName( $title->getText() );
		$titleLink = Linker::userLink( $id, $title->getText() )
			. Linker::userToolLinks( $id, $title->getText(), false, Linker::TOOL_LINKS_NOBLOCK );
		return wfMessage( 'vandallogvandal' )->rawParams( $titleLink )->escaped();
	}

	/**
	 * LogLine handler. Add a parole link to vandal/vandal log entries.
	 *
	 * @param string $log_type
	 * @param string $log_action
	 * @param Title $title
	 * @param array $paramArray
	 * @param string &$comment
	 * @param string &$revert
	 * @param string $time
	 * @return bool
	 * @throws MWException
	 */
	static function onLogLine( $log_type, $log_action, $title, $paramArray, &$comment, &$revert, $time ) {
		if ( $log_type === 'vandal' && $log_action === 'vandal' ) {
			$lr = MediaWikiServices::getInstance()->getLinkRenderer();
			$revert = '(' . $lr->makeKnownLink(
					SpecialPage::getTitleFor( 'VandalBin' ),  wfMessage( 'parolelink' )->escaped(),
					[], [ 'action' => 'parole', 'wpVandAddress' => $title->getText() ]
				) . ')';
		}
		return true;
	}

	/**
	 * Add a vandal to the database and log the action
	 *
	 * @param string $address The block target
	 * @param int $userId The target user ID
	 * @param string $reason The block reason
	 */
	public static function doVandal( $address, $userId, $reason ) {
		global $wgUser;
		$vandaler = $wgUser;
		$dbw = wfGetDB( DB_MASTER );
		$a = [
			'vand_address' => $address,
			'vand_user' => $userId,
			'vand_by' => $vandaler->getId(),
			'vand_reason' => $reason,
			'vand_timestamp' => wfTimestampNow(),
			'vand_account' => false,
			'vand_autoblock' => false,
			'vand_anon_only' => false,
			'vand_auto' => false
		];
		$dbw->insert( 'vandals', $a, __METHOD__ );
		// Log:
		$log = new LogPage( 'vandal' );
		$log->addEntry( 'vandal', Title::makeTitle( NS_USER, $address ), $reason, [], $vandaler );
	}

	static function doParole( $userId, $address, $reason, $dolog = true, $id = null ) {
		global $wgUser;
		if ( $userId != 0 ) {
			$cond = [ 'vand_user' => $userId ];
		} elseif ( $address ) {
			$cond = [ 'vand_address' => $address ];
		} else {
			$cond = [ 'vand_id' => $id ];
		}
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete( 'vandals', $cond, __METHOD__ );
		if ( $dolog ) {
			$log = new LogPage( 'vandal' );
			$target = Title::makeTitle( NS_USER, $address ? $address : "#$id" );
			$log->addEntry( 'parole', $target, $reason, [], $wgUser );
		}
	}

	static function checkVandal( $userId, &$reason, &$vandaler ) {
		//get master to ensure that lag does not allow vandal to escape block
		$dbr = wfGetDB( DB_MASTER );
		// check for username block
		$usernamefound = false;
		if ( $userId != 0 ) {
			$cond = [
				'vand_user' => $userId,
			];
			$res = $dbr->select(
				'vandals',
				[
					'vand_address',
					'vand_user',
					'vand_reason',
					'vand_by'
				],
				$cond,
				__METHOD__ );
			if ( $res->numRows() != 0 ) {
				$row = $res->fetchRow();
				$reason = $row['vand_reason'];
				$vandaler = User::newFromId( $row['vand_by'] );
				$usernamefound = true;
			}
		}

		// special case for userGetRights hook
		if ( $usernamefound ) {
			return true;
		}

		return false;
	}

	/**
	 * @param User $user
	 * @return int UNIX timestamp
	 */
	static function getLastEdit( $user ) {
		$t1 = self::getLastRevisionTimestamp( $user );
		$t2 = self::getLastArchiveTimestamp( $user );
		$dbr = wfGetDB( DB_REPLICA );
		$t3 = 0;
		// If we have the user's IP, we can also check the recent changes table to see if there's a logged in edit
		// If we are checking an anon user, this should count. If we are checking a logged in user's IP, this should only count if they are autoblocked
		if ( $user->isAnon() ) {
			$res3 = $dbr->select( 'recentchanges', 'rc_timestamp', [ 'rc_ip' => $user->getName() ], __METHOD__, [ 'ORDER BY' => 'rc_timestamp desc' ] );
			if ( $res3->numRows() != 0 ) {
				$row = $res3->fetchRow();
				$t3 = $row['rc_timestamp'];
			}
		}
		if ( max( $t1, $t2, $t3 ) != 0 ) {
			$t = wfTimestamp( TS_UNIX, max( $t1, $t2, $t3 ) );
			return $t;
		} else {
			return 0;
		}
	}

	/**
	 * Get the most recent timestamp from the archive table
	 * @param User $user
	 * @return string|int
	 */
	private static function getLastArchiveTimestamp( $user ) {
		$dbr = wfGetDB( DB_REPLICA );
		$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
		$arQuery = $revisionStore->getArchiveQueryInfo();
		$actorQuery = ActorMigration::newMigration()
			->getWhere( $dbr, 'ar_user', $user );
		$row = $dbr->selectRow(
			array_merge( $arQuery['tables'], $actorQuery['tables'] ),
			[ 'ar_timestamp' ],
			$actorQuery['conds'],
			__METHOD__,
			[
				'LIMIT' => 1,
				'ORDER BY' => 'ar_timestamp DESC'
			],
			array_merge( $arQuery['joins'], $actorQuery['joins'] )
		);
		if ( $row ) {
			return $row->ar_timestamp;
		} else {
			return 0;
		}
	}

	/**
	 * Get the most recent timestamp from the revision table
	 *
	 * @param User $user
	 * @return int|string
	 */
	private static function getLastRevisionTimestamp( $user ) {
		$dbr = wfGetDB( DB_REPLICA );
		$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
		$revQuery = $revisionStore->getQueryInfo();
		$row = $dbr->selectRow(
			$revQuery['tables'],
			[ 'rev_timestamp' ],
			[ $revQuery['fields']['rev_user_text'] => $user->getName() ],
			__METHOD__,
			[
				'LIMIT' => 1,
				'ORDER BY' => 'rev_timestamp DESC'
			],
			$revQuery['joins']
		);
		if ( $row ) {
			return $row->rev_timestamp;
		} else {
			return 0;
		}
	}

	static function onRenameUserSQL( $thing ) {
		$thing->tables['vandals'] = [ 'vand_address','vand_user' ];
		return true;
	}

	static function onRenameUserLogs( &$logs ) {
		$logs[] = 'vandal';
		return true;
	}

	/**
	 * @param User $user
	 * @param array &$aRights
	 * @return bool
	 */
	static function onUserGetRights( $user, &$aRights ) {
		// we cannot be sure that the current IP belongs to $user, so we skip the ip checking
		if ( self::checkVandal( $user->getId(), $reason, $vandaler ) ) {
			$t = self::getLastEdit( $user );
			global $wgVandalBrakeConfigLimit, $wgVandalBrakeConfigRemoveRights, $wgVandalBrakeConfigLimitRights;
			$dt = time() - $t;
			$dt = $wgVandalBrakeConfigLimit - $dt;
			$aRights = array_diff( $aRights, $wgVandalBrakeConfigRemoveRights );
			if ( $dt > 0 ) {
				// user is binned and brake is active
				$aRights = array_diff( $aRights, $wgVandalBrakeConfigLimitRights );
			}
		}
		return true;
	}

	/**
	 * @param User $user
	 * @param array &$aUserGroups
	 * @return bool
	 */
	static function onUserEffectiveGroups( $user, &$aUserGroups ) {
		// we cannot be sure that the current IP belongs to $user, so we skip the ip checking
		if ( self::checkVandal( $user->getId(), $reason, $vandaler ) ) {
			$t = self::getLastEdit( $user );
			global $wgVandalBrakeConfigLimit;
			$dt = time() - $t;
			$dt = $wgVandalBrakeConfigLimit - $dt;
			if ( $dt > 0 ) {
				// user is binned and brake is active
				$aUserGroups[] = "vandalbrake";
				$aUserGroups = array_unique( $aUserGroups );
			}
		}
		return true;
	}

	/**
	 * @param User $user
	 * @return bool
	 */
	static function onGetUserBlock( $user, $ip, &$block ) {
		if ( $block ) {
			return true;
		}
		$userid = $user->getId();
		if ( self::checkVandal( $userid, $reason, $vandaler ) ) {
			/** @var User $vandaler */
			$t = self::getLastEdit( $user );
			global $wgVandalBrakeConfigLimit;
			$dt = time() - $t;
			$dt = $wgVandalBrakeConfigLimit - $dt;
			if ( $dt > 0 ) {
				// user is binned and brake is active
				$block = new SystemBlock( [
					'address' => $user,
					'by' => $vandaler->getId(),
					'byText' => $vandaler->getName(),
					'reason' => wfMessage( 'vandalbrakenoticeblock' )
						->params( $reason, round( $dt / 60 ) )->escaped()
				] );
			}
		}
		return true;
	}

	/**
	 * @param IContextSource $context
	 * @param Content $content
	 * @param Status $status
	 * @param string $summary
	 * @param User $user
	 * @param bool $minoredit
	 * @return bool
	 */
	static function onEditFilterMergedContent( $context, $content, $status, $summary, $user, $minoredit ) {
		$t = false;
		if ( self::checkVandal( $user->getId(), $reason, $vandaler ) ) {
			$t = self::getLastEdit( $user );
			global $wgVandalBrakeConfigLimit;
			$dt = time() - $t;
			$dt = $wgVandalBrakeConfigLimit - $dt;
			if ( $dt > 0 ) {
				/** @var User $vandaler */
				$status->fatal( 'vandalbrakenotice', round( $dt / 60 ), $vandaler->getName(), $reason );
				$status->value = EditPage::AS_HOOK_ERROR_EXPECTED;
				return false;
			}
		}
		$anon = $user->isAnon();
		$pm = MediaWikiServices::getInstance()->getPermissionManager();
		$limited = !$pm->userHasRight( $user, 'noratelimit' );
		if ( $anon || $limited ) {
			global $wgVandalBrakeConfigAnonLimit, $wgVandalBrakeConfigUserLimit;
			if ( !$t ) { $t = self::getLastEdit( $user );
			}
			$dt = time() - $t;
			$dt = ( $anon ? $wgVandalBrakeConfigAnonLimit : $wgVandalBrakeConfigUserLimit ) - $dt;
			if ( $dt > 0 ) {
				$status->fatal( 'editlimitnotice', $dt );
				$status->value = EditPage::AS_HOOK_ERROR_EXPECTED;
				return false;
			}
		}
		return true;
	}

	/**
	 * @param int $id
	 * @param Title $title
	 * @param array &$tools
	 * @return bool
	 */
	static function onContributionsToolLinks( $id, $title, &$tools ) {
		global $wgUser;
		$services = MediaWikiServices::getInstance();
		$pm = $services->getPermissionManager();
		$lr = $services->getLinkRenderer();
		if ( $pm->userHasRight( $wgUser, 'block' ) ) {
			//insert at end
			$tools[] = $lr->makeKnownLink(
				SpecialPage::getTitleFor( 'VandalBrake' ),
				wfMessage( 'vandalbin-contribs' )->escaped(),
				[],
				[ 'wpVandAddress' => $title->getText() ]
			);
		}
		//insert vandal log
		$tools[] = $lr->makeKnownLink(
			SpecialPage::getTitleFor( 'Log' ),
			wfMessage( 'vandallog-contribs' )->escaped(),
			[],
			[ 'type' => 'vandal', 'page' => $title->getPrefixedUrl() ]
		);
		return true;
	}

}
