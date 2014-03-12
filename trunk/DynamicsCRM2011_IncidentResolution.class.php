<?php

require_once 'DynamicsCRM2011.php';

class DynamicsCRM2011_IncidentResolution extends DynamicsCRM2011_Entity {
	protected $entityLogicalName = 'incidentresolution'; 
	protected $entityDisplayName = 'subject';
	
	public function __toString() {
		$description = 'IncidentResolution: '.$this->Subject.' <'.$this->ID.'>';
		return $description;
	}
}

?>