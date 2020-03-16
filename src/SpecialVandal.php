<?php

namespace MediaWiki\Extension\VandalBrake;

use PermissionsError;
use ReadOnlyError;
use SpecialPage;

class SpecialVandal extends SpecialPage {
	function __construct() {
		parent::__construct( 'VandalBrake', 'block' );
		// SpecialPage::setGroup('VandalBrake','users');
		global $wgSpecialPageGroups;
		$wgSpecialPageGroups['VandalBrake'] = 'users';
	}

	function execute( $par ) {
		global $wgRequest, $wgOut, $wgUser;
		if ( wfReadOnly() ) {
			throw new ReadOnlyError;
		}

		if ( !$wgUser->isAllowed( 'vandalbin' ) ) {
			throw new PermissionsError( 'vandalbin' );
		}

		$form = new VandalForm( $par );

		$action = $wgRequest->getVal( 'action' );
		if ( 'success' == $action ) {
			$form->showSuccess();
		} elseif ( $wgRequest->wasPosted() && 'submit' == $action
			&& $wgUser->matchEditToken( $wgRequest->getVal( 'wpEditToken' ) )
		) {
			$form->doSubmit();
		} else {
			$form->showForm( '' );
		}
	}
}
