<?php

namespace MediaWiki\Extension\VandalBrake;

use Html;
use IP;
use Linker;
use LogEventsList;
use LogPage;
use OutputPage;
use RequestContext;
use SpecialPage;
use Title;
use User;
use Xml;

class VandalForm {
	var $VandAddress, $Reason, $VandAccount, $VandAutoblock, $VandAnonOnly;

	function __construct( $par ) {
		global $wgRequest;
		$this->VandAddress = $wgRequest->getVal( 'wpVandAddress', $par );
		$this->VandAddress = strtr( $this->VandAddress, '_', ' ' );
		$this->Reason = $wgRequest->getText( 'wpVandReason' );
		$this->VandReasonList = $wgRequest->getText( 'wpVandReasonList' );
		// checkboxes
		$byDefault = !$wgRequest->wasPosted();
		$this->VandAccount = $wgRequest->getBool( 'preventaccount', false );
		$this->VandAutoblock = $wgRequest->getBool( 'autoblock', false );
		$this->VandAnonOnly = $wgRequest->getBool( 'anononly', false );
	}

	function showForm( $err ) {
		global $wgOut, $wgUser;
		$wgOut->setPagetitle( wfMessage( 'vandalbrake' )->escaped() );
		$wgOut->addWikiMsg( 'vandalbraketext' );
		$mIpaddress = Xml::label( wfMessage( 'ipaddressorusername' )->text(), 'mw-bi-target' );
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
			Xml::tags( 'script', [ 'type' => 'text/javascript', 'src' => "$wgStylePath/common/block.js?$wgStyleVersion" ], '' ) .
			Xml::openElement( 'form', [ 'method' => 'post', 'action' => $titleObject->getLocalURL( "action=submit" ), 'id' => 'vand' ] ) .
			Xml::openElement( 'fieldset' ) .
			Xml::element( 'legend', null, wfMessage( 'vandalbrake' )->text() ) .
			Xml::openElement( 'table', [ 'border' => '0', 'id' => 'mw-vandal-table' ] ) .
			"<tr>
		<td class='mw-label'>
		  {$mIpaddress}
		</td>
		<td class='mw-input'>"
		);
		$wgOut->addHTML(
			Xml::input(
				'wpVandAddress', 45, $this->VandAddress,
				[ 'tabindex' => '1',
					'id' => 'mw-bi-target',
					'onchange' => 'updateBlockOptions()' ]
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
		<td class='mw-imput'>" .
			Xml::input(
				'wpVandReason', 45, $this->Reason,
				[ 'tabindex' => 2,
					'id' => 'mw-vandal-reason',
					'maxlength' => '200' ]
			) . "
		</td>
	  </tr>" .
			"<tr id='wpAnonOnlyRow'>
		<td>&nbsp;</td>
		<td class='mw-input'>" .
			Xml::checkLabel( wfMessage( 'anononlyblock' )->text(), 'anononly', 'anononly', $this->VandAnonOnly, [ 'tabindex' => '3' ] ) . "
		</td>
	  </tr>" .
			"<tr id='wpCreateAccountRow'>
		<td>&nbsp;</td>
		<td class='mw-input'>" .
			Xml::checkLabel( wfMessage( 'ipbcreateaccount' )->text(), 'preventaccount', 'preventaccount', $this->VandAccount, [ 'tabindex' => '4' ] ) . "
		</td>
	  </tr>" .
			"<tr id='wpEnableAutoblockRow'>
		<td>&nbsp;</td>
		<td class='mw-input'>" .
			Xml::checkLabel( wfMessage( 'ipbenableautoblock' )->text(), 'autoblock', 'autoblock', $this->VandAutoblock, [ 'tabindex' => '5' ] ) . "
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
			Xml::closeElement( 'form' ) .
			Xml::tags( 'script', [ 'type' => 'text/javascript' ], 'updateBlockOptions()' ) . "\n"
		);

		$wgOut->addHTML( $this->getConvenienceLinks() );

		if ( is_object( $user ) ) {
			$this->showLogFragment( $wgOut, $user->getUserPage() );
		} elseif ( preg_match( '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $this->VandAddress ) ) {
			$this->showLogFragment( $wgOut, Title::makeTitle( NS_USER, $this->VandAddress ) );
		} elseif ( preg_match( '/^\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}/', $this->VandAddress ) ) {
			$this->showLogFragment( $wgOut, Title::makeTitle( NS_USER, $this->VandAddress ) );
		}
	}

	/**
	 * @param OutputPage $out
	 * @param Title $title
	 */
	function showLogFragment( $out, $title ) {
		$log = new LogPage( 'vandal' );
		$out->addHTML( Xml::element( 'h2', null, $log->getName() ) );
		$count = LogEventsList::showLogExtract(
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
		$skin = RequestContext::getMain()->getSkin();
		if ( $this->VandAddress ) {
			$links[] = $this->getContribsLink( $skin );
		}
		$links[] = $this->getUnblockLink( $skin );
		$links[] = $this->getVandListLink( $skin );
		$links[] = Linker::userLink( 'MediaWiki:Ipbreason-dropdown', wfMessage( 'ipb-edit-dropdown' )->escaped() );
		return '<p class="mw-ipb-conveniencelinks">' . implode( ' | ', $links ) . '</p>';
	}

	private function getContribsLink( $skin ) {
		$contribsPage = SpecialPage::getTitleFor( 'Contributions', $this->VandAddress );
		return Linker::link( $contribsPage, wfMessage( 'ipb-blocklist-contribs' )->params( $this->VandAddress )->escaped() );
	}

	private function getUnblockLink( $skin ) {
		$list = SpecialPage::getTitleFor( 'VandalBin' );
		if ( $this->VandAddress ) {
			$addr = htmlspecialchars( strtr( $this->VandAddress, '_', ' ' ) );
			return Linker::link(
				$list, wfMessage( 'parole-addr' )->rawParams( $addr )->escaped(),
				[], [ 'action' => 'parole', 'wpVandAddress' => $this->VandAddress ]
			);
		} else {
			return Linker::link( $list, wfMessage( 'parole-any' )->escaped(), [], [ 'action' => 'parole' ] );
		}
	}

	private function getVandListLink( $skin ) {
		$list = SpecialPage::getTitleFor( 'VandalBin' );
		if ( $this->VandAddress ) {
			$addr = htmlspecialchars( strtr( $this->VandAddress, '_', ' ' ) );
			return Linker::link(
				$list, wfMessage( 'vandalbin-addr' )->rawParams( $addr )->escaped(),
				[], [ 'wpVandAddress' => $this->VandAddress ]
			);
		} else {
			return Linker::link( $list, wfMessage( 'vandalbin-any' )->escaped() );
		}
	}

	function doVandal() {
		$userId = 0;
		$this->VandAddress = IP::sanitizeIP( $this->VandAddress );

		$rxIP4 = '\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}';
		$rxIP6 = '\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}';
		$rxIP = "($rxIP4|$rxIP6)";
		if ( !preg_match( "/^$rxIP$/", $this->VandAddress ) ) {
			// username
			$user = User::newFromName( $this->VandAddress );
			if ( $user !== null && is_object( $user ) && $user->getId() ) {
				$userId = $user->getId();
				$this->VandAddress = $user->getName();
			} else {
				return [ 'nosuchusershort', htmlspecialchars( $user ? $user->getName() : $this->VandAddress ) ];
			}
		}

		$reasonstr = $this->VandReasonList;
		if ( $reasonstr != 'other' && $this->Reason != '' ) {
			$reasonstr .= ': ' . $this->Reason;
		} elseif ( $reasonstr == 'other' ) {
			$reasonstr = $this->Reason;
		}

		$dbr = wfGetDB( DB_REPLICA );
		if ( $userId != 0 ) {
			$cond = [ 'vand_user' => $userId ];
		} elseif ( $this->VandAddress ) {
			$cond = [ 'vand_address' => $this->VandAddress ];
		}
		$res = $dbr->select( 'vandals', 'vand_id, vand_address, vand_user', $cond, 'VandalForm::doVandal' );
		$found = ( $res->numRows() != 0 );
		$res->free();
		if ( $found ) {
			return [ 'vandalalready' ];
		}

		VandalBrake::doVandal( $this->VandAddress, $userId, $reasonstr, $this->VandAccount, $this->VandAutoblock, $this->VandAnonOnly );

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
