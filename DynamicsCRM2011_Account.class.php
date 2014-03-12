<?php

require_once 'DynamicsCRM2011.php';

class DynamicsCRM2011_Account extends DynamicsCRM2011_Entity {
	protected $entityLogicalName = 'account';
	protected $entityDisplayName = 'name';

	public function __toString() {
		$description = 'Account: '.$this->Name.' <'.$this->ID.'>';
		return $description;
	}
}

?>