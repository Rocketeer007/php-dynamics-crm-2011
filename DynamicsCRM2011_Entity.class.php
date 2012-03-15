<?php

require_once('DynamicsCRM2011_Connector.class.php');

abstract class DynamicsCRM2011_Entity {
	/**
	 * Overridden in each child class; this is how Dynamics refers to this Entity
	 * @var String entityLogicalName
	 */
	protected $entityLogicalName = '<Undefined>';
	/* The details of the Entity structure (SimpleXML object) */
	protected $entityData;
	/* The Properties of the Entity */
	protected $properties = Array();
	/**
	 * 
	 * @param DynamicsCRM2011Connector $conn Connection to the Dynamics CRM server - should be active already.
	 */
	function __construct(DynamicsCRM2011_Connector $conn) {
		/* First, get the full details of what an Incident is on this server */
		$this->entityData = $conn->retrieveEntity($this->entityLogicalName);
		$xml = $this->entityData->asXML();
		/* Next, we analyse this data and determine what Properties this Entity has */
		foreach ($this->entityData->children('http://schemas.microsoft.com/xrm/2011/Metadata')->Attributes[0]->AttributeMetadata as $attribute) {
			$this->properties[(String)$attribute->SchemaName] = Array(
					'Label' => (String)$attribute->DisplayName->children('http://schemas.microsoft.com/xrm/2011/Contracts')->UserLocalizedLabel->Label,
					'Type'  => (String)$attribute->AttributeType,
					'Create' => ((String)$attribute->IsValidForCreate === 'true'),
					'Update' => ((String)$attribute->IsValidForUpdate === 'true'),
					'Read'   => ((String)$attribute->IsValidForRead === 'true'),
					'Value'  => NULL,
					'Changed' => false,
				);
		}
	}
	
	/**
	 * 
	 * @param String $property to be fetched
	 * @return value of the property, if it exists & is readable
	 */
	public function __get($property) {
		/* Only return the value if it exists & is readable */
		if (array_key_exists($property, $this->properties) && $this->properties[$property]['Read'] === true) {
			return $this->properties[$property]['Value'];
		}
		/* Property is not readable, but does exist - different error message! */
		if (array_key_exists($property, $this->properties)) {
			trigger_error('Property '.$property.' of the '.$this->entityLogicalName.' entity is not Readable', E_USER_NOTICE);
			return NULL;
		}
		/* Property doesn't exist - standard error */
		$trace = debug_backtrace();
		trigger_error('Undefined property via __get(): ' . $property 
				. ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
				E_USER_NOTICE);
		return NULL;
	}
	
	/**
	 * 
	 * @param String $property to be changed
	 * @param mixed $value new value for the property
	 */
	public function __set($property, $value) {
		/* Property doesn't exist - standard error */
		if (!array_key_exists($property, $this->properties)) {
			$trace = debug_backtrace();
			trigger_error('Undefined property via __set(): ' . $property 
					. ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
					E_USER_NOTICE);
			return;
		}
		/* Check that this property can be set in Creation or Update */
		if ($this->properties[$property]['Create'] == false && $this->properties[$property]['Update'] == false) {
			trigger_error('Property '.$property.' of the '.$this->entityLogicalName.' entity cannot be set', E_USER_NOTICE);
			return;
		}
		/* Update the property value */
		$this->properties[$property]['Value'] = $value;
		$this->properties[$property]['Changed'] = true;
	}
	
	/**
	 * Reset all changed values to unchanged
	 */
	public function reset() {
		/* Loop through all the properties */
		foreach ($this->properties as &$property) {
			$property['Changed'] = false;
		}
	}
	
	/**
	 * Check if a property has been changed since creation of the Entity
	 * @param String $property
	 * @return boolean
	 */
	public function isChanged($property) {
		/* Property doesn't exist - standard error */
		if (!array_key_exists($property, $this->properties)) {
			$trace = debug_backtrace();
			trigger_error('Undefined property via __set(): ' . $property
					. ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
					E_USER_NOTICE);
			return;
		}
		return $this->properties[$property]['Changed'];
	}
}

?>