<?php

require_once 'DynamicsCRM2011.php';

class DynamicsCRM2011_Contact extends DynamicsCRM2011_Entity {
	protected $entityLogicalName = 'contact'; 
	
	public function __toString() {
		$description = 'Contact: '.$this->Name.' <'.$this->ID.'>';
		return $description;
	}
}

?>