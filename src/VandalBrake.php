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
use RequestContext;
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
			return wfMessage( 'vandallogvandal' )->rawParams( $title->getPrefixedText(), $params[0] )->escaped();
		}
		$id = User::idFromName( $title->getText() );
		$titleLink = Linker::userLink( $id, $title->getText() )
			. Linker::userToolLinks( $id, $title->getText(), false, Linker::TOOL_LINKS_NOBLOCK );
		return wfMessage( 'vandallogvandal' )->rawParams( $titleLink, $params[0] )->escaped();
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
	 * @param bool $blockCreation
	 * @param bool $autoblock Also block the last-known IP address
	 * @param bool $anononly
	 * @param bool $dolog
	 * @param User|null $vandaler The admin user performing the addition
	 * @param bool $automatic
	 */
	public static function doVandal( $address, $userId, $reason, $blockCreation, $autoblock,
		$anononly, $dolog = true, $vandaler = null, $automatic = false
	) {
		global $wgUser;
		if ( !$vandaler ) {
			$vandaler = $wgUser;
		}
		$dbw = wfGetDB( DB_MASTER );
		if ( $userId == 0 ) {
			$autoblock = false;
		} else {
			$anononly = false;
		}
		$a = [
			'vand_address' => $address,
			'vand_user' => $userId,
			'vand_by' => $vandaler->getId(),
			'vand_reason' => $reason,
			'vand_timestamp' => wfTimestampNow(),
			'vand_account' => $blockCreation,
			'vand_autoblock' => $autoblock,
			'vand_anon_only' => $anononly,
			'vand_auto' => $automatic
		];
		$dbw->insert( 'vandals', $a, __METHOD__ );
		// autoblock
		if ( $autoblock ) {
			$res_ip = $dbw->select(
				'recentchanges',
				'rc_ip',
				[ 'rc_user_text' => $address ],
				__METHOD__
			);
			if ( $row = $res_ip->fetchRow() ) {
				$ip = $row['rc_ip'];
				// parole first to prevent duplicate rows
				self::doParole( 0, $ip, '', false );
				$a = [
					'vand_address' => $ip,
					'vand_user' => 0,
					'vand_by' => $vandaler->getId(),
					'vand_reason' => wfMessage( 'vandallogauto' )->params( $address, $reason )->text(),
					'vand_timestamp' => wfTimestampNow(),
					'vand_account' => $blockCreation,
					'vand_autoblock' => false,
					'vand_anon_only' => false,
					'vand_auto' => true,
				];
				$dbw->insert( 'vandals', $a, __METHOD__ );
			}
		}
		// Log:
		if ( $dolog ) {
			$log = new LogPage( 'vandal' );
			$flags = [];
			if ( $anononly ) {
				$flags[] = wfMessage( 'block-log-flags-anononly' )->text();
			}
			if ( $blockCreation ) {
				$flags[] = wfMessage( 'block-log-flags-nocreate' )->text();
			}
			if ( !$autoblock && $userId ) {
				$flags[] = wfMessage( 'block-log-flags-noautoblock' )->text();
			}
			$params = [];
			$params[] = implode( ', ', $flags );
			$log->addEntry( 'vandal', Title::makeTitle( NS_USER, $address ), $reason, $params, $vandaler );
		}
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
			$log->addEntry( 'parole', $target, $reason, null, $wgUser );
		}
	}

	static function checkVandal( $ip, $userId, &$reason, &$vandaler, &$accountallowed, &$vand_id, &$checkip ) {
		//get master to ensure that lag does not allow vandal to escape block
		$dbr = wfGetDB( DB_MASTER );
		// check for username block
		$performautoblock = false;
		$usernamefound = false;
		$checkip = false;
		if ( $userId != 0 ) {
			$cond = [
				'vand_user' => $userId,
			];
			$res = $dbr->select(
				'vandals',
				[
					'vand_id',
					'vand_address',
					'vand_user',
					'vand_anon_only',
					'vand_autoblock',
					'vand_account',
					'vand_reason',
					'vand_by'
				],
				$cond,
				__METHOD__ );
			if ( $res->numRows() != 0 ) {
				$row = $res->fetchRow();
				$accountallowed = $row['vand_account'];
				$accountallowed = !$accountallowed;
				$vand_id = $row['vand_id'];
				// perform autoblock if needed
				$autoblock = $row['vand_autoblock'];
				if ( $autoblock ) {
					$checkip = true;
					$performautoblock = true;
				}
				$reason = $row['vand_reason'];
				$vandaler = User::newFromId( $row['vand_by'] );
				$usernamefound = true;
			}
		}

		// check if the user is ip-blocked
		if ( $ip != 0 ) {
			$cond = [
				'vand_address' => $ip,
			];
			$res = $dbr->select(
				'vandals',
				[
					'vand_id',
					'vand_address',
					'vand_user',
					'vand_anon_only',
					'vand_autoblock',
					'vand_account',
					'vand_reason',
					'vand_by'
				],
				$cond,
				__METHOD__ );
			if ( $res->numRows() != 0 ) {
				// user is ip blocked, return true if also username blocked
				// if the user is logged in and anon_only is set, don't apply ip block
				$row = $res->fetchRow();
				$anononly = $row['vand_anon_only'];
				$vand_id = $row['vand_id'];
				if ( $usernamefound ) {
					// if there was no autoblock, but the ip block is not anon only, then we have to check the ip
					if ( !$checkip ) {
						$checkip = !$anononly;
					}
					return true;
				} elseif ( !$anononly || $userId == 0 ) {
					if ( !$checkip ) {
						$checkip = !$anononly;
					}
					$accountallowed = !$row['vand_account'];
					$reason = $row['vand_reason'];
					$vandaler = User::newFromId( $row['vand_by'] );
					return true;
				}
			} elseif ( $performautoblock ) {
				$user = User::newFromId( $userId );
				// parole to prevent duplicate rows
				self::doParole( 0, $ip, '', false );
				$reason_new = wfMessage( 'vandallogauto' )->params( $user->getName(), $reason )->text();
				$vandaler = User::newFromId( $row['vand_by'] );
				self::doVandal( $ip, 0, $reason_new, !$accountallowed, false, false, false, $vandaler, true );
				return true;
			} elseif ( $usernamefound ) {
				return true;
			}
		} else {
			// special case for userGetRights hook
			if ( $usernamefound ) {
				return true;
			}
		}

		$accountallowed = true;
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
			array_merge( $arQuery['conds'], $actorQuery['conds'] ),
			__METHOD__,
			[
				'LIMIT' => 1,
				'ORDER BY' => 'ar_timestamp DESC'
			],
			array_merge( $arQuery['join_conds'], $actorQuery['join_conds'] )
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
		$actorQuery = ActorMigration::newMigration()
			->getWhere( $dbr, 'rev_user', $user );
		$row = $dbr->selectRow(
			array_merge( $revQuery['tables'], $actorQuery['tables'] ),
			[ 'rev_timestamp' ],
			array_merge( $revQuery['conds'], $actorQuery['conds'] ),
			__METHOD__,
			[
				'LIMIT' => 1,
				'ORDER BY' => 'rev_timestamp DESC'
			],
			array_merge( $revQuery['join_conds'], $actorQuery['join_conds'] )
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
		if ( self::checkVandal( 0, $user->getId(), $reason, $vandaler, $accountallowed, $vand_id, $autoblocked ) ) {
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
		if ( self::checkVandal( 0, $user->getId(), $reason, $vandaler, $accountallowed, $vand_id, $autoblocked ) ) {
			$t = self::getLastEdit( $user );
			global $wgVandalBrakeConfigLimit;
			$dt = time() - $t;
			$dt = $wgVandalBrakeConfigLimit - $dt;
			if ( $dt > 0 ) {
				// user is binned and brake is active
				$aUserGroups[] = "vandalbrake";
				array_unique( $aUserGroups );
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
		// we cannot be sure that the current IP belongs to $user, so we skip the ip checking
		$userip = $user->isAnon() ? $user->getName() : 0;
		$userid = $user->getId();
		if ( self::checkVandal( $userip, $userid, $reason, $vandaler, $accountallowed, $vand_id, $autoblocked ) ) {
			/** @var User $vandaler */
			$t = self::getLastEdit( $user );
			global $wgVandalBrakeConfigLimit;
			$dt = time() - $t;
			$dt = $wgVandalBrakeConfigLimit - $dt;
			if ( $dt > 0 ) {
				// user is binned and brake is active
				$block = new SystemBlock( [
					'address' => $userip,
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
		if ( self::checkVandal( RequestContext::getMain()->getRequest()->getIP(), $user->getId(), $reason, $vandaler, $accountallowed, $vand_id, $autoblocked ) ) {
			$t = self::getLastEdit( $user );
			// Check the user's IP too, for logged out edits or edits from another account
			// but only if the user is autoblocked or if the block is on the IP, not the user
			if ( !$user->isAnon() && $autoblocked ) {
				$t2 = self::getLastEdit( new User );
				$t = max( $t, $t2 );
			}
			global $wgVandalBrakeConfigLimit;
			$dt = time() - $t;
			$dt = $wgVandalBrakeConfigLimit - $dt;
			if ( $dt > 0 ) {
				/** @var User $vandaler */
				$status->fatal( 'vandalbrakenotice', round( $dt / 60 ), $vandaler->getName(), $reason, $vand_id );
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
	 * @param User $user
	 * @param string &$message
	 * @return bool
	 */
	static function onAccountCreation( $user, &$message ) {
		global $wgUser;
		if ( self::checkVandal( RequestContext::getMain()->getRequest()->getIP(), $wgUser->getId(), $reason, $vandaler, $accountallowed, $vand_id, $autoblocked ) ) {
			if ( !$accountallowed ) {
				$message = wfMessage( 'vandalbrakenoticeaccountcreation' )->params( $vandaler->getName(), $reason, $vand_id )->parse();
				return false;
			} else {
				return true;
			}
		} else {
			return true;
		}
	}

	//FIXME: Doesn't work, no appropriate hook
	/*  static function onRC(&$changeslist, &$s, &$rc)
	{
	global $wgUser;
	if( $wgUser->isAllowed( 'block' ) ) {
	  if( !$changeslist->isDeleted($rc,Revision::DELETED_USER) ) {
		$link = Linker::link( SpecialPage::getTitleFor( 'VandalBrake' ),
													  wfMessage( 'vandalbin-contribs' )->escaped(), array(),
													  array( 'wpVandAddress' => $rc->getAttribute(rc_user_text) ) );
		$s .= $link;
		//$s .= implode(',',$rc->mAttribs) . ' keys: ' . implode(',',array_keys($rc->mAttribs));
	  }
	}
	return true;
	}
	*/

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
