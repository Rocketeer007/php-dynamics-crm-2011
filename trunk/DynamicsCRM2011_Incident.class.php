<?php

require_once 'DynamicsCRM2011.php';

class DynamicsCRM2011_Incident extends DynamicsCRM2011_Entity {
	protected $entityLogicalName = 'incident'; 
	
	public function __toString() {
		$description = 'Incident: '.$this->TicketNumber.' <'.$this->ID.'>';
		return $description;
	}
}

?>