<?php

namespace MediaWiki\Extension\VandalBrake;

use Html;
use IP;
use SpecialPage;
use User;
use Xml;

class ParoleForm {
	var $VandAddress, $Reason, $VandId;

	function __construct( $par ) {
		global $wgRequest;
		$this->VandAddress = $wgRequest->getVal( 'wpVandAddress', $par );
		$this->VandAddress = strtr( $this->VandAddress, '_', ' ' );
		$this->VandId = $wgRequest->getVal( 'id' );
		$this->Reason = $wgRequest->getText( 'wpVandReason' );
	}

	function showForm( $err ) {
		global $wgOut, $wgUser;
		$wgOut->setPagetitle( wfMessage( 'paroletitle' )->escaped() );
		$wgOut->addWikiMsg( 'paroletext' );
		$mIpaddress = Xml::label( wfMessage( 'ipaddressorusername' )->text(), 'mw-bi-target' );
		$mReason = Xml::label( wfMessage( 'ipbreason' )->text(), 'vand-reason' );

		if ( $err ) {
			$key = array_shift( $err );
			$msg = wfMessage( $key )->params( $err )->text();
			$wgOut->setSubtitle( wfMessage( 'formerror' )->escaped() );
			$wgOut->addHTML( Xml::tags( 'p', [ 'class' => 'error' ], $msg ) );
		}

		$titleObject = SpecialPage::getTitleFor( 'VandalBin' );
		global $wgStylePath, $wgStyleVersion;
		$wgOut->addHTML(
			Xml::openElement( 'form', [ 'method' => 'post', 'action' => $titleObject->getLocalURL( "action=submit" ), 'id' => 'parole' ] ) .
			Xml::openElement( 'fieldset' ) .
			Xml::element( 'legend', null, wfMessage( 'parolelegend' )->text() ) .
			Xml::openElement( 'table', [ 'border' => '0', 'id' => 'mw-parole-table' ] ) .
			"<tr>
		<td class='mw-label'>
		  {$mIpaddress}
		</td>
		<td class='mw-input'>"
		);

		if ( $this->VandAddress ) {
			$wgOut->addHTML(
				Xml::input(
					'wpVandAddress', 45, $this->VandAddress,
					[ 'tabindex' => '1',
						'id' => 'mw-bi-target', ]
				)
			);
		} else {
			$wgOut->addHTML(
				"#$this->VandId" . Html::hidden( 'id', $this->VandId )
			);
		}
		$wgOut->addHTML(
			"</td>
	  </tr>
	  <tr>"
		);
		$wgOut->addHTML(
			"
	  <tr id='wpVandReason'>
		<td class='mw-label'>
		  {$mReason}
		</td>
		<td class='mw-imput'>" .
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
				wfMessage( 'parole' )->text(),
				[ 'name' => 'wpParole',
					'tabindex' => '6',
					'accesskey' => 's' ]
			) . "
		</td>
	  </tr>" .
			Xml::closeElement( 'table' ) .
			Html::hidden( 'wpEditToken', $wgUser->getEditToken() ) .
			Xml::closeElement( 'fieldset' ) .
			Xml::closeElement( 'form' )
		);
	}

	function doParole() {
		if ( $this->VandAddress ) {
			$userId = 0;
			$this->VandAddress = IP::sanitizeIP( $this->VandAddress );

			$rxIP4 = '\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}';
			$rxIP6 = '\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}:\w{1,4}';
			$rxIP = "($rxIP4|$rxIP6)";
			if ( !preg_match( "/^$rxIP$/", $this->VandAddress ) ) {
				// username
				$user = User::newFromName( $this->VandAddress );
				if ( $user !== null && $user->getId() ) {
					$userId = $user->getId();
					$this->VandAddress = $user->getName();
				} else {
					return [ 'nosuchusershort', htmlspecialchars( $user ? $user->getName() : $this->VandAddress ) ];
				}
			}
		}

		$reasonstr = $this->Reason;

		$dbr = wfGetDB( DB_REPLICA );
		if ( $userId != 0 ) {
			$cond = [ 'vand_user' => $userId ];
		} elseif ( $this->VandAddress ) {
			$cond = [ 'vand_address' => $this->VandAddress ];
		} else {
			$cond = [ 'vand_id' => $this->VandId ];
		}
		$res = $dbr->select( 'vandals', 'vand_id, vand_address, vand_user', $cond, 'VandalForm::doVandal' );
		$found = ( $res->numRows() != 0 );
		$res->free();
		if ( !$found ) {
			return [ 'vandalnot' ];
		}

		VandalBrake::doParole( $userId, $this->VandAddress, $reasonstr, true, $this->VandId );

		return [];
	}

	function doSubmit() {
		global $wgOut;
		$retval = $this->doParole();
		if ( empty( $retval ) ) {
			$titleObj = SpecialPage::getTitleFor( 'VandalBin' );
			$wgOut->redirect( $titleObj->getFullURL( 'action=success&' . 'wpVandAddress=' . urlencode( $this->VandAddress ) ) );
			return;
		}
		$this->showForm( $retval );
	}

	function showSuccess() {
		global $wgOut;
		$wgOut->setPagetitle( wfMessage( 'VandalBin' )->escaped() );
		$wgOut->setSubtitle( wfMessage( 'parolesuccessub' )->escaped() );
		$text = $this->VandAddress ? ( wfMessage( 'parolesuccesstext' )->params( $this->VandAddress )->parse() )
			: wfMessage( 'parolesuccesstextanon' )->escaped();
		$wgOut->addHTML( $text );
	}
}
