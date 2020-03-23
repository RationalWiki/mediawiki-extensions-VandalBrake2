<?php

namespace MediaWiki\Extension\VandalBrake;

use MediaWiki\MediaWikiServices;
use PermissionsError;
use ReadOnlyError;
use SpecialPage;

class SpecialVandal extends SpecialPage {
	function __construct() {
		parent::__construct( 'VandalBrake', 'block' );
	}

	public function getGroupName() {
		return 'users';
	}

	function execute( $par ) {
		if ( wfReadOnly() ) {
			throw new ReadOnlyError;
		}

		$pm = MediaWikiServices::getInstance()->getPermissionManager();
		if ( !$pm->userHasRight( $this->getUser(), 'vandalbin' ) ) {
			throw new PermissionsError( 'vandalbin' );
		}

		$form = new VandalForm( $par );

		$action = $this->getRequest()->getVal( 'action' );
		if ( 'success' == $action ) {
			$form->showSuccess();
		} elseif ( $this->getRequest()->wasPosted() && 'submit' == $action
			&& $this->getUser()->matchEditToken( $this->getRequest()->getVal( 'wpEditToken' ) )
		) {
			$form->doSubmit();
		} else {
			$form->showForm( '' );
		}
	}
}
