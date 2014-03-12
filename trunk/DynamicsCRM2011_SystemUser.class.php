<?php

require_once 'DynamicsCRM2011.php';

class DynamicsCRM2011_SystemUser extends DynamicsCRM2011_Entity {
	protected $entityLogicalName = 'systemuser'; 
	protected $entityDisplayName = 'fullname';
	
	public function __toString() {
		$description = 'System User: '.$this->FullName.' <'.$this->ID.'>';
		return $description;
	}
}

?>