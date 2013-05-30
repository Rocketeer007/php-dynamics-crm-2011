<?php

require_once 'DynamicsCRM2011.php';

class DynamicsCRM2011_Lead extends DynamicsCRM2011_Entity {
	protected $entityLogicalName = 'lead'; 
	protected $entityDisplayName = 'fullname';
	
	public function __toString() {
		$description = 'Lead: '.$this->FullName.' <'.$this->ID.'>';
		return $description;
	}
}

?>