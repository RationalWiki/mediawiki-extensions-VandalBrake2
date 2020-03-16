<?php

class SpecialVandalbin extends SpecialPage {
	var $VandAddress;

	function __construct() {
		parent::__construct( 'VandalBin' );
		// SpecialPage::setGroup('VandalBrake','users');
		global $wgSpecialPageGroups;
		$wgSpecialPageGroups['VandalBin'] = 'users';
	}

	function searchForm() {
		global $wgScript, $wgTitle, $wgRequest;
		return Xml::tags(
			'form', [ 'action' => $wgScript ],
			html::hidden( 'title', $wgTitle->getPrefixedDbKey() ) .
			Xml::openElement( 'fieldset' ) .
			Xml::element( 'legend', null, wfMessage( 'vandalbin-legend' )->text() ) .
			Xml::inputLabel( wfMessage( 'ipaddressorusername' )->text(), 'wpVandAddress', 'wpVandAddress', /* size */ false, $this->VandAddress ) .
			'&nbsp;' .
			Xml::submitButton( wfMessage( 'ipblocklist-submit' )->text() ) . '<br />' .
			Xml::closeElement( 'fieldset' )
		);
	}

	function execute( $par ) {
		global $wgRequest, $wgOut, $wgUser;
		if ( wfReadOnly() ) {
			throw new ReadOnlyError;
		}

		global $wgRequest;
		$this->VandAddress = $wgRequest->getVal( 'wpVandAddress', $par );
		$this->VandAddress = strtr( $this->VandAddress, '_', ' ' );
		$action = $wgRequest->getText( 'action' );

		$pform = new ParoleForm( $par );
		if ( $action == 'parole' ) {
			// Check permissions
			if ( !$wgUser->isAllowed( 'vandalbin' ) ) {
				throw new PermissionsError( 'vandalbin' );
			}
			// Check for database lock
			if ( wfReadOnly() ) {
				throw new ReadOnlyError;
			}
			$pform->showForm( '' );
		} elseif ( $action == 'submit' && $wgRequest->wasPosted()
			&& $wgUser->matchEditToken( $wgRequest->getVal( 'wpEditToken' ) )
		) {
			// Check permissions
			if ( !$wgUser->isAllowed( 'vandalbin' ) ) {
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

		// $wgOut->addWikiMsg( 'vandalbintext' );
		$wgOut->addHTML( $this->searchForm() );
		$this->setHeaders();
		$dbr = wfGetDB( DB_REPLICA );
		$conds = [];
		if ( $this->VandAddress ) {
			$conds['vand_address'] = $this->VandAddress;
		}
		$pager = new VandalbinPager( $conds );
		if ( $pager->getNumRows() ) {
			$wgOut->addHTML(
				$pager->getNavigationBar() .
				Xml::tags( 'ul', null, $pager->getBody() ) .
				$pager->getNavigationBar()
			);
		} else {
			if ( $this->VandAddress ) {
				$wgOut->addWikiMsg( 'vandalbin-notfound' );
			} else {
				$wgOut->addWikiMsg( 'vandalbin-empty' );
			}
		}
	}

}
