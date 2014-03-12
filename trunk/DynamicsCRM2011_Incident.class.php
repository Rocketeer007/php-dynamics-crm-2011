<?php

require_once 'DynamicsCRM2011.php';

class DynamicsCRM2011_Incident extends DynamicsCRM2011_Entity {
	protected $entityLogicalName = 'incident'; 
	protected $entityDisplayName = 'ticketnumber';
	
	public function __toString() {
		$description = 'Incident: '.$this->TicketNumber.' <'.$this->ID.'>';
		return $description;
	}
	
	public function close(DynamicsCRM2011_Connector $crmConnector, $status, $subject = NULL) {
		if ($this->ID == self::EmptyGUID) {
			throw new Exception('Cannot Close an Incident that has not been Created!');
			return FALSE;
		}
		/* Create the Incident Resolution */
		$_resolution = new DynamicsCRM2011_IncidentResolution($crmConnector);
		/* Link the resolution to this Incident */
		$_resolution->IncidentId = $this;
		/* Set the Subject of the Resolution to the supplied Subject, or the Incident Title if none supplied */
		$_resolution->Subject = ($subject == NULL ? $this->Title : $subject);
		/* Resolve the Incident */
		$crmConnector->closeIncident($_resolution, $status);
	}
}

?>