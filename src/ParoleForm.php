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
		$mTargetLabel = Xml::label( wfMessage( 'vandal-target-label' )->text(), 'mw-bi-target' );
		$mReason = Xml::label( wfMessage( 'ipbreason' )->text(), 'vand-reason' );

		if ( $err ) {
			$key = array_shift( $err );
			$msg = wfMessage( $key )->params( $err )->text();
			$wgOut->setSubtitle( wfMessage( 'formerror' )->escaped() );
			$wgOut->addHTML( Xml::tags( 'p', [ 'class' => 'error' ], $msg ) );
		}

		$titleObject = SpecialPage::getTitleFor( 'VandalBin' );
		$wgOut->addHTML(
			Xml::openElement( 'form', [ 'method' => 'post', 'action' => $titleObject->getLocalURL( "action=submit" ), 'id' => 'parole' ] ) .
			Xml::openElement( 'fieldset' ) .
			Xml::element( 'legend', null, wfMessage( 'parolelegend' )->text() ) .
			Xml::openElement( 'table', [ 'border' => '0', 'id' => 'mw-parole-table' ] ) .
			"<tr>
		<td class='mw-label'>
		  {$mTargetLabel}
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
		$userId = 0;
		if ( $this->VandAddress ) {
			$user = User::newFromName( $this->VandAddress );
			if ( $user !== null && $user->getId() ) {
				$userId = $user->getId();
				$this->VandAddress = $user->getName();
			} else {
				return [ 'nosuchusershort', htmlspecialchars( $user ? $user->getName() : $this->VandAddress ) ];
			}
		}

		$reasonstr = $this->Reason;

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select( 'vandals', [ 'vand_id', 'vand_address', 'vand_user' ],
			[ 'vand_user' => $userId ], __METHOD__ );
		$found = ( $res->numRows() != 0 );
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
