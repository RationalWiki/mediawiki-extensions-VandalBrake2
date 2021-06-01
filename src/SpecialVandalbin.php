<?php

namespace MediaWiki\Extension\VandalBrake;

use Html;
use MediaWiki\MediaWikiServices;
use PermissionsError;
use ReadOnlyError;
use SpecialPage;
use Xml;

class SpecialVandalbin extends SpecialPage {
	var $VandAddress;

	function __construct() {
		parent::__construct( 'VandalBin' );
	}

	public function getGroupName() {
		return 'users';
	}

	function searchForm() {
		global $wgScript, $wgTitle;
		return Xml::tags(
			'form', [ 'action' => $wgScript ],
			Html::hidden( 'title', $wgTitle->getPrefixedDbKey() ) .
			Xml::openElement( 'fieldset' ) .
			Xml::element( 'legend', null, wfMessage( 'vandalbin-legend' )->text() ) .
			Xml::inputLabel( wfMessage( 'vandal-target-label' )->text(), 'wpVandAddress', 'wpVandAddress', /* size */ false, $this->VandAddress ) .
			'&nbsp;' .
			Xml::submitButton( wfMessage( 'ipblocklist-submit' )->text() ) . '<br />' .
			Xml::closeElement( 'fieldset' )
		);
	}

	function execute( $par ) {
		if ( wfReadOnly() ) {
			throw new ReadOnlyError;
		}

		$this->VandAddress = $this->getRequest()->getVal( 'wpVandAddress', $par );
		$this->VandAddress = strtr( $this->VandAddress, '_', ' ' );
		$action = $this->getRequest()->getText( 'action' );

		$pm = MediaWikiServices::getInstance()->getPermissionManager();
		$pform = new ParoleForm( $par );
		if ( $action == 'parole' ) {
			// Check permissions
			if ( !$pm->userHasRight( $this->getUser(), 'vandalbin' ) ) {
				throw new PermissionsError( 'vandalbin' );
			}
			// Check for database lock
			if ( wfReadOnly() ) {
				throw new ReadOnlyError;
			}
			$pform->showForm( '' );
		} elseif ( $action == 'submit' && $this->getRequest()->wasPosted()
			&& $this->getUser()->matchEditToken( $this->getRequest()->getVal( 'wpEditToken' ) )
		) {
			// Check permissions
			if ( !$pm->userHasRight( $this->getUser(), 'vandalbin' ) ) {
				throw new PermissionsError( 'vandalbin' );
			}
			// Check for database lock
			if ( wfReadOnly() ) {
				throw new ReadOnlyError;
			}
			$pform->doSubmit();
		} elseif ( $action == 'success' ) {
			$pform->showSuccess();
			$this->VandAddress = null;
		}

		// $this->getOutput()->addWikiMsg( 'vandalbintext' );
		$this->getOutput()->addHTML( $this->searchForm() );
		$this->setHeaders();
		$conds = [];
		if ( $this->VandAddress ) {
			$conds['vand_address'] = $this->VandAddress;
		}
		$pager = new VandalbinPager( $conds );
		if ( $pager->getNumRows() ) {
			$this->getOutput()->addHTML(
				$pager->getNavigationBar() .
				Xml::tags( 'ul', null, $pager->getBody() ) .
				$pager->getNavigationBar()
			);
		} else {
			if ( $this->VandAddress ) {
				$this->getOutput()->addWikiMsg( 'vandalbin-notfound' );
			} else {
				$this->getOutput()->addWikiMsg( 'vandalbin-empty' );
			}
		}
	}

}
