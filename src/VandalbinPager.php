<?php

namespace MediaWiki\Extension\VandalBrake;

use LinkBatch;
use Linker;
use MediaWiki\MediaWikiServices;
use ReverseChronologicalPager;
use SpecialPage;
use User;

class VandalbinPager extends ReverseChronologicalPager {
	public $mConds;

	function __construct( $conds = [] ) {
		$this->mConds = $conds;
		parent::__construct();
	}

	function getStartBody() {
		// Do a link batch query
		$this->mResult->seek( 0 );
		$lb = new LinkBatch;

		while ( $row = $this->mResult->fetchObject() ) {
			$user = User::newFromId( $row->vand_by );
			$name = str_replace( ' ', '_', $user->getName() );
			$lb->add( NS_USER, $name );
			$lb->add( NS_USER_TALK, $name );
			if ( $row->vand_user != 0 ) {
				$user = User::newFromId( $row->vand_user );
				$name = $user->getName();
			} else {
				$name = $row->vand_address;
			}
			$name = str_replace( ' ', '_', $name );
			$lb->add( NS_USER, $name );
			$lb->add( NS_USER_TALK, $name );
		}
		$lb->execute();
		return '';
	}

	function formatRow( $row ) {
		global $wgLang;
		$vand_by_id = $row->vand_by;
		$vand_by_user = User::newFromId( $vand_by_id );
		$vand_by_name = $vand_by_user->getName();
		$vandaler = Linker::userLink( $vand_by_id, $vand_by_name ) . Linker::userToolLinks( $vand_by_id, $vand_by_name );
		$reason = ( $row->vand_reason ) ? "$row->vand_reason" : '';
		$action = [ 'action' => 'parole' ];
		if ( $row->vand_auto ) {
			$action['id'] = $row->vand_id;
			$target = "#$row->vand_id";
		} else {
			if ( $row->vand_user != 0 ) {
				$user = User::newFromId( $row->vand_user );
				$name = $user->getName();
			} else {
				$name = $row->vand_address;
			}
			$action['wpVandAddress'] = $name;//$row->vand_address;
			$target = Linker::userLink( $row->vand_user, /*$row->vand_address*/ $name ) . Linker::userToolLinks( $row->vand_user, /*$row->vand_address*/ $name, false, Linker::TOOL_LINKS_NOBLOCK );
		}
		$formattedTime = $wgLang->timeanddate( $row->vand_timestamp, true );
		$line = wfMessage( 'vandalbinmsg' )->rawParams( $formattedTime, $vandaler, $target )->escaped();

		$lr = MediaWikiServices::getInstance()->getLinkRenderer();
		$parolelink = $lr->makeKnownLink( SpecialPage::getTitleFor( 'vandalbin' ), wfMessage( 'parolelink' ), [], $action );

		$flags = [];
		if ( $row->vand_anon_only ) {
			$flags[] = wfMessage( 'block-log-flags-anononly' )->text();
		}
		if ( $row->vand_account ) {
			$flags[] = wfMessage( 'block-log-flags-nocreate' )->text();
		}
		if ( !$row->vand_autoblock && $row->vand_user ) {
			$flags[] = wfMessage( 'block-log-flags-noautoblock' )->text();
		}
		$flagsstr = implode( ', ', $flags );
		$comment = Linker::commentBlock( $reason );
		return "<li>$line ($flagsstr) $comment ($parolelink)</li>\n";
	}

	function getQueryInfo() {
		$conds = $this->mConds;
		return [
			'tables' => 'vandals',
			'fields' => '*',
			'conds' => $conds,
		];
	}

	function getIndexField() {
		return 'vand_timestamp';
	}
}
