<?php

namespace MediaWiki\Extension\VandalBrake;

use Html;
use Linker;
use LogEventsList;
use LogPage;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use OutputPage;
use SpecialPage;
use Title;
use User;
use Xml;

class VandalForm {
	var $VandAddress, $Reason, $VandReasonList;

	/** @var LinkRenderer */
	private $linker;

	function __construct( $par ) {
		global $wgRequest;
		$this->VandAddress = $wgRequest->getVal( 'wpVandAddress', $par );
		$this->VandAddress = strtr( $this->VandAddress, '_', ' ' );
		$this->Reason = $wgRequest->getText( 'wpVandReason' );
		$this->VandReasonList = $wgRequest->getText( 'wpVandReasonList' );
		// checkboxes
		$this->linker = MediaWikiServices::getInstance()->getLinkRenderer();
	}

	function showForm( $err ) {
		global $wgOut, $wgUser;
		$wgOut->setPagetitle( wfMessage( 'vandalbrake' )->escaped() );
		$wgOut->addWikiMsg( 'vandalbraketext' );
		$mTargetLabel = Xml::label( wfMessage( 'vandal-target-label' )->text(), 'mw-bi-target' );
		$mReason = Xml::label( wfMessage( 'ipbreason' )->text(), 'wpVandReasonList' );
		$mReasonother = Xml::label( wfMessage( 'vandal-otherreason' )->text(), 'vand-reason' );
		$user = User::newFromName( $this->VandAddress );

		if ( $err ) {
			$key = array_shift( $err );
			$msg = wfMessage( $key )->params( $err )->text();
			$wgOut->setSubtitle( wfMessage( 'formerror' )->escaped() );
			$wgOut->addHTML( Xml::tags( 'p', [ 'class' => 'error' ], $msg ) );
		}

		$reasonDropDown = Xml::listDropDown(
			'wpVandReasonList',
			wfMessage( 'ipbreason-dropdown' )->inContentLanguage()->text(),
			wfMessage( 'vandal-reasonotherlist' )->inContentLanguage()->text(), $this->VandReasonList, 'wpVandDropDown', 4
		);
		$titleObject = SpecialPage::getTitleFor( 'VandalBrake' );
		global $wgStylePath, $wgStyleVersion;
		$wgOut->addHTML(
			Xml::openElement( 'form', [ 'method' => 'post', 'action' => $titleObject->getLocalURL( "action=submit" ), 'id' => 'vand' ] ) .
			Xml::openElement( 'fieldset' ) .
			Xml::element( 'legend', null, wfMessage( 'vandalbrake' )->text() ) .
			Xml::openElement( 'table', [ 'border' => '0', 'id' => 'mw-vandal-table' ] ) .
			"<tr>
		<td class='mw-label'>
		  {$mTargetLabel}
		</td>
		<td class='mw-input'>"
		);
		$wgOut->addHTML(
			Xml::input(
				'wpVandAddress', 45, $this->VandAddress,
				[ 'tabindex' => '1',
					'id' => 'mw-bi-target' ]
			)
		);
		$wgOut->addHTML(
			"</td>
	  </tr>
	  <tr>"
		);
		$wgOut->addHTML(
			"
	  </tr>
		<td class='mw-label'>
		  {$mReason}
		</td>
		<td class='mw-input'>
		  {$reasonDropDown}
		</td>
	  </tr>
	  <tr id='wpVandReason'>
		<td class='mw-label'>
		  {$mReasonother}
		</td>
		<td class='mw-input'>" .
			Xml::input(
				'wpVandReason', 45, $this->Reason,
				[ 'tabindex' => 2,
					'id' => 'mw-vandal-reason',
					'maxlength' => '200' ]
			) . "
		</td>
	  </tr>"
		);
		$wgOut->addHTML(
			"
	  <tr>
		<td style='padding-top: 1em;'>" .
			Xml::submitButton(
				wfMessage( 'vandal' )->text(),
				[ 'name' => 'wpVandal',
					'tabindex' => '6',
					'accesskey' => 's' ]
			) . "
		</td>
	  </tr>" .
			Xml::closeElement( 'table' ) .
			html::hidden( 'wpEditToken', $wgUser->getEditToken() ) .
			Xml::closeElement( 'fieldset' ) .
			Xml::closeElement( 'form' ) . "\n"
		);

		$wgOut->addHTML( $this->getConvenienceLinks() );

		if ( is_object( $user ) ) {
			$this->showLogFragment( $wgOut, $user->getUserPage() );
		}
	}

	/**
	 * @param OutputPage $out
	 * @param Title $title
	 */
	function showLogFragment( $out, $title ) {
		$log = new LogPage( 'vandal' );
		$out->addHTML( Xml::element( 'h2', null, $log->getName() ) );
		LogEventsList::showLogExtract(
			$out, 'vandal', $title->getPrefixedText(), '', [
				'lim' => 10,
				'msgKey' => [
					'vandallog-showlog',
					$title->getText()
				],
				'showIfEmpty' => false
			]
		);
	}

	private function getConvenienceLinks() {
		if ( $this->VandAddress ) {
			$links[] = $this->getContribsLink();
		}
		$links[] = $this->getUnblockLink();
		$links[] = $this->getVandListLink();
		$links[] = Linker::userLink( 'MediaWiki:Ipbreason-dropdown', wfMessage( 'ipb-edit-dropdown' )->escaped() );
		return '<p class="mw-ipb-conveniencelinks">' . implode( ' | ', $links ) . '</p>';
	}

	private function getContribsLink() {
		$contribsPage = SpecialPage::getTitleFor( 'Contributions', $this->VandAddress );
		return $this->linker->makeLink( $contribsPage, wfMessage( 'ipb-blocklist-contribs' )->params( $this->VandAddress ) );
	}

	private function getUnblockLink() {
		$list = SpecialPage::getTitleFor( 'VandalBin' );
		if ( $this->VandAddress ) {
			$addr = htmlspecialchars( strtr( $this->VandAddress, '_', ' ' ) );
			return $this->linker->makeLink(
				$list, wfMessage( 'parole-addr' )->rawParams( $addr )->escaped(),
				[], [ 'action' => 'parole', 'wpVandAddress' => $this->VandAddress ]
			);
		} else {
			return $this->linker->makeLink( $list, wfMessage( 'parole-any' ), [], [ 'action' => 'parole' ] );
		}
	}

	private function getVandListLink() {
		$list = SpecialPage::getTitleFor( 'VandalBin' );
		if ( $this->VandAddress ) {
			$addr = htmlspecialchars( strtr( $this->VandAddress, '_', ' ' ) );
			return $this->linker->makeLink(
				$list, wfMessage( 'vandalbin-addr' )->rawParams( $addr ),
				[], [ 'wpVandAddress' => $this->VandAddress ]
			);
		} else {
			return $this->linker->makeLink( $list, wfMessage( 'vandalbin-any' ) );
		}
	}

	function doVandal() {
		$user = User::newFromName( $this->VandAddress );
		if ( $user !== null && is_object( $user ) && $user->getId() ) {
			$userId = $user->getId();
			$this->VandAddress = $user->getName();
		} else {
			return [ 'nosuchusershort', htmlspecialchars( $user ? $user->getName() : $this->VandAddress ) ];
		}

		$reasonstr = $this->VandReasonList;
		if ( $reasonstr != 'other' && $this->Reason != '' ) {
			$reasonstr .= ': ' . $this->Reason;
		} elseif ( $reasonstr == 'other' ) {
			$reasonstr = $this->Reason;
		}

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'vandals',
			['vand_id', 'vand_address', 'vand_user' ],
			[ 'vand_user' => $userId ],
			__METHOD__ );
		$found = ( $res->numRows() != 0 );
		if ( $found ) {
			return [ 'vandalalready' ];
		}

		VandalBrake::doVandal( $this->VandAddress, $userId, $reasonstr );

		return [];
	}

	function doSubmit() {
		global $wgOut;
		$retval = $this->doVandal();
		if ( empty( $retval ) ) {
			$titleObj = SpecialPage::getTitleFor( 'VandalBrake' );
			$wgOut->redirect( $titleObj->getFullURL( 'action=success&' . 'wpVandAddress=' . urlencode( $this->VandAddress ) ) );
			return;
		}
		$this->showForm( $retval );
	}

	function showSuccess() {
		global $wgOut;
		$wgOut->setPagetitle( wfMessage( 'vandalbrake' )->escaped() );
		$wgOut->setSubtitle( wfMessage( 'vandalsuccessub' )->escaped() );
		$text = wfMessage( 'vandalsuccesstext' )->params( $this->VandAddress )->parse();
		$wgOut->addHTML( $text );
	}
}
