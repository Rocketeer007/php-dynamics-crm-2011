<?php

require_once 'DynamicsCRM2011.php';

/**
 *
 * @property string $ID
 * @property string $LOGICALNAME
 * @property string $DISPLAYNAME
 *
 * @property integer $createdon
 * @property integer $createdby
 * @property integer $modifiedon
 * @property integer $modifiedby
 *
 * @property DynamicsCRM2011_OptionSetValue $statecode
 * @property DynamicsCRM2011_OptionSetValue $statuscode
 *
 * @property DynamicsCRM2011_Entity $ownerid
 */
class DynamicsCRM2011_Entity extends DynamicsCRM2011 {
	/**
	 * Overridden in each child class
	 * @var String entityLogicalName this is how Dynamics refers to this Entity
	 */
	protected $entityLogicalName = NULL;
	/** @var String entityDisplayName the field to use to display the entity's Name */
	protected $entityDisplayName = NULL;
	/* The details of the Entity structure (SimpleXML object) */
	protected $entityData;
	/* The details of the Entity structure (as Arrays) */
	protected $properties = Array();
	protected $mandatories = Array();
	protected $optionSets = Array();
	/* The details of this instance of the Entity - the added AliasedValue properites */
	protected $localProperties = Array();
	/* The details of this instance of the Entity - the property Values */
	protected $propertyValues = Array();
	/* The ID of the Entity */
	private $entityID;
	/* The Domain/URL of the Dynamics CRM 2011 Server where this is stored */
	private $entityDomain = NULL;

	/**
	 *
	 * @param DynamicsCRM2011Connector $conn Connection to the Dynamics CRM server - should be active already.
	 * @param String $_logicalName Allows constructing arbritrary Entities by setting the EntityLogicalName directly
	 */
	function __construct(DynamicsCRM2011_Connector $conn, $_logicalName = NULL) {
		/* If a new LogicalName was passed, set it in this Entity */
		if ($_logicalName != NULL && $_logicalName != $this->entityLogicalName) {
			/* If this value was already set, don't allow changing it. */
			/* - otherwise, you could have a DynamicsCRM2011_Incident that was actually an Account! */
			if ($this->entityLogicalName != NULL) {
				throw new Exception('Cannot override the Entity Logical Name on a strongly typed Entity');
			}
			/* Set the Logical Name */
			$this->entityLogicalName = $_logicalName;
		}
		/* Check we have a Logical Name for the Entity */
		if ($this->entityLogicalName == NULL) {
			throw new Exception('Cannot instantiate an abstract Entity - specify the Logical Name');
		}
		/* Set the Domain that this Entity is associated with */
		$this->setEntityDomain($conn);
		/* Check if the Definition of this Entity is Cached on the Connector */
		if ($conn->isEntityDefinitionCached($this->entityLogicalName)) {
			/* Use the Cached values */
			$isDefined = $conn->getCachedEntityDefinition($this->entityLogicalName,
					$this->entityData, $this->properties, $this->propertyValues, $this->mandatories,
					$this->optionSets, $this->entityDisplayName);
			if ($isDefined) return;
		}

		/* At this point, we assume Entity is not Cached */
		/* So, get the full details of what an Incident is on this server */
		$this->entityData = $conn->retrieveEntity($this->entityLogicalName);

		/* Next, we analyse this data and determine what Properties this Entity has */
		foreach ($this->entityData->children('http://schemas.microsoft.com/xrm/2011/Metadata')->Attributes[0]->AttributeMetadata as $attribute) {
			/* Determine the Type of the Attribute */
			$attributeList = $attribute->attributes('http://www.w3.org/2001/XMLSchema-instance');
			$attributeType = self::stripNS($attributeList['type']);
			/* Handle the special case of Lookup types */
			$isLookup = ($attributeType == 'LookupAttributeMetadata');
			/* If it's a Lookup, check what Targets are allowed */
			if ($isLookup) {
				$lookupTypes = Array();
				foreach ($attribute->Targets->children('http://schemas.microsoft.com/2003/10/Serialization/Arrays') as $target) {
					$lookupTypes[] = (String)$target;
				}
			} else {
				$lookupTypes = NULL;
			}
			/* Check if this field is mandatory */
			$requiredLevel = (String)$attribute->RequiredLevel->children('http://schemas.microsoft.com/xrm/2011/Contracts')->Value;
			/* If this is an OptionSet, determine the OptionSet details */
			if (!empty($attribute->OptionSet) && !empty($attribute->OptionSet->Name)) {
				/* Determine the Name of the OptionSet */
				$optionSetName = (String)$attribute->OptionSet->Name;
				$optionSetGlobal = ($attribute->OptionSet->IsGlobal == 'true');
				/* Determine the Type of the OptionSet */
				$optionSetType = (String)$attribute->OptionSet->OptionSetType;
				/* Array to store the Options for this OptionSet */
				$optionSetValues = Array();

				/* Debug logging - Identify the OptionSet */
				if (self::$debugMode) {
					echo 'Attribute '.(String)$attribute->SchemaName.' is an OptionSet'.PHP_EOL;
					echo "\tName:\t".$optionSetName.($optionSetGlobal ? ' (Global)' : '').PHP_EOL;
					echo "\tType:\t".$optionSetType.PHP_EOL;
				}

				/* Handle the different types of OptionSet */
				switch ($optionSetType) {
					case 'Boolean':
						/* Parse the FalseOption */
						$value = (int)$attribute->OptionSet->FalseOption->Value;
						$label = (String)$attribute->OptionSet->FalseOption->Label->children('http://schemas.microsoft.com/xrm/2011/Contracts')->UserLocalizedLabel->Label;
						$optionSetValues[$value] = $label;
						/* Parse the TrueOption */
						$value = (int)$attribute->OptionSet->TrueOption->Value;
						$label = (String)$attribute->OptionSet->TrueOption->Label->children('http://schemas.microsoft.com/xrm/2011/Contracts')->UserLocalizedLabel->Label;
						$optionSetValues[$value] = $label;
						break;
					case 'State':
					case 'Status':
					case 'Picklist':
						/* Loop through the available Options */
						foreach ($attribute->OptionSet->Options->OptionMetadata as $option) {
							/* Parse the Option */
							$value = (int)$option->Value;
							$label = (String)$option->Label->children('http://schemas.microsoft.com/xrm/2011/Contracts')->UserLocalizedLabel->Label;
							/* Check for duplicated Values */
							if (array_key_exists($value, $optionSetValues)) {
								trigger_error('Option '.$label.' of OptionSet '.$optionSetName.' used by field '.(String)$attribute->SchemaName.' has the same Value as another Option in this Set',
										E_USER_WARNING);
							} else {
								/* Store the Option */
								$optionSetValues[$value] = $label;
							}
						}
						break;
					default:
						/* If we're using Default, Warn user that the OptionSet handling is not defined */
						trigger_error('No OptionSet handling implemented for Type '.$optionSetType.' used by field '.(String)$attribute->SchemaName.' in Entity '.$this->entityLogicalName,
								E_USER_WARNING);
				}

				/* DebugLogging - Identify the OptionSet Values */
				if (self::$debugMode) {
					foreach ($optionSetValues as $value => $label) {
						echo "\t\tOption ".$value.' => '.$label.PHP_EOL;
					}
				}

				/* Save this OptionSet in the Design */
				if (array_key_exists($optionSetName, $this->optionSets)) {
					/* If this isn't a Global OptionSet, warn of the name clash */
					if (!$optionSetGlobal) {
						trigger_error('OptionSet '.$optionSetName.' used by field '.(String)$attribute->SchemaName.' has a name clash with another OptionSet in Entity '.$this->entityLogicalName,
								E_USER_WARNING);
					}
				} else {
					/* Not already present - store the details */
					$this->optionSets[$optionSetName] = $optionSetValues;
				}
			} else {
				/* Not an OptionSet */
				$optionSetName = NULL;
			}
			/* If this is the Primary Name of the Entity, set the Display Name to match */
			if ((String)$attribute->IsPrimaryName === 'true') {
				$this->entityDisplayName = strtolower((String)$attribute->LogicalName);
			}
			/* Add this property to the Object's Property array */
			$this->properties[strtolower((String)$attribute->LogicalName)] = Array(
					'Label' => (String)$attribute->DisplayName->children('http://schemas.microsoft.com/xrm/2011/Contracts')->UserLocalizedLabel->Label,
					'Description' => (String)$attribute->Description->children('http://schemas.microsoft.com/xrm/2011/Contracts')->UserLocalizedLabel->Label,
					'isCustom' => ((String)$attribute->IsCustomAttribute === 'true'),
					'isPrimaryId' => ((String)$attribute->IsPrimaryId === 'true'),
					'isPrimaryName' => ((String)$attribute->IsPrimaryName === 'true'),
					'Type'  => (String)$attribute->AttributeType,
					'isLookup' => $isLookup,
					'lookupTypes' => $lookupTypes,
					'Create' => ((String)$attribute->IsValidForCreate === 'true'),
					'Update' => ((String)$attribute->IsValidForUpdate === 'true'),
					'Read'   => ((String)$attribute->IsValidForRead === 'true'),
					'RequiredLevel' => $requiredLevel,
					'AttributeOf' => (String)$attribute->AttributeOf,
					'OptionSet' => $optionSetName,
				);
			$this->propertyValues[strtolower((String)$attribute->LogicalName)] = Array(
					'Value'  => NULL,
					'Changed' => false,
				);
			/* If appropriate, add this to the Mandatory Field list */
			if ($requiredLevel != 'None' && $requiredLevel != 'Recommended') {
				$this->mandatories[strtolower((String)$attribute->LogicalName)] = $requiredLevel;
			}
		}

		/* Ensure that this Entity Definition is Cached for next time */
		$conn->setCachedEntityDefinition($this->entityLogicalName,
				$this->entityData, $this->properties, $this->propertyValues, $this->mandatories,
				$this->optionSets, $this->entityDisplayName);
		return;
	}

	/**
	 *
	 * @param String $property to be fetched
	 * @return value of the property, if it exists & is readable
	 */
	public function __get($property) {
		/* Handle special fields */
		switch (strtoupper($property)) {
			case 'ID':
				return $this->getID();
				break;
			case 'LOGICALNAME':
				return $this->entityLogicalName;
				break;
			case 'DISPLAYNAME':
				if ($this->entityDisplayName != NULL) {
					$property = $this->entityDisplayName;
				} else {
					return NULL;
				}
				break;
		}
		/* Handle dynamic properties... */
		$property = strtolower($property);
		/* Only return the value if it exists & is readable */
		if (array_key_exists($property, $this->properties) && $this->properties[$property]['Read'] === true) {
			return $this->propertyValues[$property]['Value'];
		}
		/* Also check for an AliasedValue */
		if (array_key_exists($property, $this->localProperties) && $this->localProperties[$property]['Read'] === true) {
			return $this->propertyValues[$property]['Value'];
		}
		/* Property is not readable, but does exist - different error message! */
		if (array_key_exists($property, $this->properties) || array_key_exists($property, $this->localProperties)) {
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
		/* Handle special fields */
		switch (strtoupper($property)) {
			case 'ID':
				$this->setID($value);
				return;
			case 'DISPLAYNAME':
				if ($this->entityDisplayName != NULL) {
					$property = $this->entityDisplayName;
				} else {
					return;
				}
				break;
		}
		/* Handle dynamic properties... */
		$property = strtolower($property);
		/* Property doesn't exist - standard error */
		if (!array_key_exists($property, $this->properties)) {
			$trace = debug_backtrace();
			trigger_error('Undefined property via __set() - ' . $this->entityLogicalName . ' does not support property: ' . $property
					. ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
					E_USER_NOTICE);
			return;
		}
		/* Check that this property can be set in Creation or Update */
		if ($this->properties[$property]['Create'] == false && $this->properties[$property]['Update'] == false) {
			trigger_error('Property '.$property.' of the '.$this->entityLogicalName
					.' entity cannot be set', E_USER_NOTICE);
			return;
		}
		/* If this is a Lookup field, it MUST be set to an Entity of an appropriate type */
		if ($this->properties[$property]['isLookup'] && !is_null($value)) {
			/* Check the new value is an Entity */
			if (!$value instanceOf self) {
				$trace = debug_backtrace();
				trigger_error('Property '.$property.' of the '.$this->entityLogicalName
						.' entity must be a '.get_class()
						. ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
						E_USER_ERROR);
				return;
			}
			/* Check the new value is the right type of Entity */
			if (!in_array($value->entityLogicalName, $this->properties[$property]['lookupTypes'])) {
				$trace = debug_backtrace();
				trigger_error('Property '.$property.' of the '.$this->entityLogicalName
						.' entity must be a '.implode(' or ', $this->properties[$property]['lookupTypes'])
						. ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
						E_USER_ERROR);
				return;
			}
			/* Clear any AttributeOf related to this field */
			$this->clearAttributesOf($property);
		}
		/* If this is an OptionSet field, it MUST be set to a valid OptionSetValue
		 * according to the definition of the OptionSet
		 */
		if ($this->properties[$property]['OptionSet'] != NULL) {
			/* Which OptionSet is used? */
			$optionSetName = $this->properties[$property]['OptionSet'];
			/* Container for the final value */
			$optionSetValue = NULL;

			/* Handle passing a Boolean value */
			if ($value === TRUE) $value = 1;
			elseif ($value === FALSE) $value = 0;
			/* Handle passing a String value */
			if (is_string($value)) {
				/* Look for an option with this label */
				foreach ($this->optionSets[$optionSetName] as $optionValue => $optionLabel) {
					/* Check for a case-insensitive match */
					if (strcasecmp($value, $optionLabel) == 0) {
						/* Create the Value object */
						$optionSetValue = new DynamicsCRM2011_OptionSetValue($optionValue, $optionLabel);
						break;
					}
				}
			}
			/* Handle passing an Integer value */
			if (is_int($value)) {
				/* Look for an option with this value */
				if (array_key_exists($value, $this->optionSets[$optionSetName])) {
					/* Create the Value object */
					$optionSetValue = new DynamicsCRM2011_OptionSetValue($value, $this->optionSets[$optionSetName][$value]);
				}
			}
			/* Handle passing an OptionSetValue */
			if ($value instanceof DynamicsCRM2011_OptionSetValue) {
				/* Check it's a valid option (by Value) */
				if (array_key_exists($value->Value, $this->optionSets[$optionSetName])) {
					/* Copy the Value object */
					$optionSetValue = $value;
				}
			}

			/* Check we found a valid OptionSetValue */
			if ($optionSetValue != NULL) {
				/* Set the value to be retained */
				$value = $optionSetValue;
				/* Clear any AttributeOf related to this field */
				$this->clearAttributesOf($property);
			} else {
				$trace = debug_backtrace();
				trigger_error('Property '.$property.' of the '.$this->entityLogicalName
						.' entity must be a valid OptionSetValue of type '.$optionSetName
						. ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
						E_USER_WARNING);
				return;
			}
		}
		/* Update the property value with whatever value was passed */
		$this->propertyValues[$property]['Value'] = $value;
		/* Mark the property as changed */
		$this->propertyValues[$property]['Changed'] = true;
	}

	/**
	 * Check if a property exists on this entity.  Called by isset().
	 * Note that this implementation does not check if the property is actually a non-null value.
	 *
	 * @param String $property to be checked
	 * @return boolean true, if it exists & is readable
	 */
	public function __isset($property) {
		/* Handle special fields */
		switch (strtoupper($property)) {
			case 'ID':
				return ($this->entityID == NULL);
				break;
			case 'LOGICALNAME':
				return true;
				break;
			case 'DISPLAYNAME':
				if ($this->entityDisplayName != NULL) {
					$property = $this->entityDisplayName;
				} else {
					return false;
				}
				break;
		}
		/* Handle dynamic properties... */
		$property = strtolower($property);
		/* Value "Is Set" if it exists as a property, and is readable */
		/* Note: NULL values count as "Set" -> use "Empty" on the return of "Get" to check for NULLs */
		if (array_key_exists($property, $this->properties) && $this->properties[$property]['Read'] === true) {
			return true;
		}
		/* Also check if this is an AliasedValue */
		if (array_key_exists($property, $this->localProperties) && $this->localProperties[$property]['Read'] === true) {
			return true;
		}
		return false;
	}

	/**
	 * Utility function to clear all "AttributeOf" fields relating to the base field
	 * @param String $baseProperty
	 */
	private function clearAttributesOf($baseProperty) {
		/* Loop through all the properties */
		foreach ($this->properties as $property => $propertyDetails) {
			/* Check if this Property is an "AttributeOf" the base Property */
			if ($propertyDetails['AttributeOf'] == $baseProperty) {
				/* Clear the property value */
				$this->propertyValues[$property]['Value'] = NULL;
			}
		}
	}

	/**
	 * @return String description of the Entity including Type, DisplayName and ID
	 */
	public function __toString() {
		/* Does this Entity have a DisplayName part? */
		if ($this->entityDisplayName != NULL) {
			/* Use the magic __get to determine the DisplayName */
			$displayName = ': '.$this->DisplayName.' ';
		} else {
			/* No DisplayName */
			$displayName = '';
		}
		/* EntityType: Display Name <GUID> */
		return $this->entityLogicalName.$displayName.'<'.$this->getID().'>';
	}

	/**
	 * Reset all changed values to unchanged
	 */
	public function reset() {
		/* Loop through all the properties */
		foreach ($this->propertyValues as &$property) {
			$property['Changed'] = false;
		}
	}

	/**
	 * Check if a property has been changed since creation of the Entity
	 * @param String $property
	 * @return boolean
	 */
	public function isChanged($property) {
		/* Dynamic properties are all stored in lowercase */
		$property = strtolower($property);
		/* Property doesn't exist - standard error */
		if (!array_key_exists($property, $this->propertyValues)) {
			$trace = debug_backtrace();
			trigger_error('Undefined property via isChanged(): ' . $property
					. ' in ' . $trace[0]['file'] . ' on line ' . $trace[0]['line'],
					E_USER_NOTICE);
			return;
		}
		return $this->propertyValues[$property]['Changed'];
	}

	/**
	 * Private utility function to get the ID field; enforces NULL --> EmptyGUID
	 * @ignore
	 */
	private function getID() {
		if ($this->entityID == NULL) return self::EmptyGUID;
		else return $this->entityID;
	}

	/**
	 * Private utility function to set the ID field; enforces "Set Once" logic
	 * @param String $value
	 * @throws Exception if the ID is already set
	 */
	private function setID($value) {
		/* Only allow setting the ID once */
		if ($this->entityID != NULL) {
			throw new Exception('Cannot change the ID of an Entity');
		}
		$this->entityID = $value;
	}

	/**
	 * Utility function to check all mandatory fields are filled
	 * @param Array $details populated with any failures found
	 * @return boolean true if all mandatories are filled
	 */
	public function checkMandatories(Array &$details = NULL) {
		/* Assume true, until proved false */
		$allMandatoriesFilled = true;
		$missingFields = Array();
		/* Loop through all the Mandatory fields */
		foreach ($this->mandatories as $property => $reason) {
			/* If this is an attribute of another property, check that property instead */
			if ($this->properties[$property]['AttributeOf'] != NULL) {
				/* Check the other property */
				$propertyToCheck = $this->properties[$property]['AttributeOf'];
			} else {
				/* Check this property */
				$propertyToCheck = $property;
			}
			if ($this->propertyValues[$propertyToCheck]['Value'] == NULL) {
				/* Ignore values that can't be in Create or Update */
				if ($this->properties[$propertyToCheck]['Create'] || $this->properties[$propertyToCheck]['Update']) {
					$missingFields[$propertyToCheck] = $reason;
					$allMandatoriesFilled = false;
				}
			}
		}
		/* If not all Mandatories were filled, and we have been given a Details array, populate it */
		if (is_array($details) && $allMandatoriesFilled == false) {
			$details += $missingFields;
		}
		/* Return the result */
		return $allMandatoriesFilled;
	}

	/**
	 * Create a DOMNode that represents this Entity, and can be used in a Create or Update
	 * request to the CRM server
	 *
	 * @param boolean $allFields indicates if we should include all fields, or only changed fields
	 */
	public function getEntityDOM($allFields = false) {
		/* Generate the Entity XML */
		$entityDOM = new DOMDocument();
		$entityNode = $entityDOM->appendChild($entityDOM->createElement('entity'));
		$entityNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:i', 'http://www.w3.org/2001/XMLSchema-instance');
		$attributeNode = $entityNode->appendChild($entityDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts', 'b:Attributes'));
		$attributeNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.datacontract.org/2004/07/System.Collections.Generic');
		/* Loop through all the attributes of this Entity */
		foreach ($this->properties as $property => $propertyDetails) {
			/* Only include changed properties */
			if ($this->propertyValues[$property]['Changed']) {
				/* Create a Key/Value Pair of String/Any Type */
				$propertyNode = $attributeNode->appendChild($entityDOM->createElement('b:KeyValuePairOfstringanyType'));
				/* Set the Property Name */
				$propertyNode->appendChild($entityDOM->createElement('c:key', $property));
				/* Check the Type of the Value */
				if ($propertyDetails['isLookup'] && !is_null($this->propertyValues[$property]['Value'])) {
					/* Special handling for Lookups - use an EntityReference, not the AttributeType */
					$valueNode = $propertyNode->appendChild($entityDOM->createElement('c:value'));
					$valueNode->setAttribute('i:type', 'b:EntityReference');
					$valueNode->appendChild($entityDOM->createElement('b:Id', $this->propertyValues[$property]['Value']->ID));
					$valueNode->appendChild($entityDOM->createElement('b:LogicalName', $this->propertyValues[$property]['Value']->entityLogicalName));
					$valueNode->appendChild($entityDOM->createElement('b:Name'))->setAttribute('i:nil', 'true');
				} else {
					/* Determine the Type, Value and XML Namespace for this field */
					$xmlValue = $this->propertyValues[$property]['Value'];
					$xmlValueChild = NULL;
					$xmlType = strtolower($propertyDetails['Type']);
					$xmlTypeNS = 'http://www.w3.org/2001/XMLSchema';
					/* Special Handing for certain types of field */
					switch (strtolower($propertyDetails['Type'])) {
						case 'memo':
							/* Memo - This gets treated as a normal String */
							$xmlType = 'string';
							break;
						case 'integer':
							/* Integer - This gets treated as an "int" */
							$xmlType = 'int';
							break;
						case 'datetime':
							/* Date/Time - Stored in the Entity as a PHP Date, needs to be XML format. Type is also mixed-case */
							if ($xmlValue !== null) $xmlValue = gmdate("Y-m-d\TH:i:s\Z", $xmlValue);
							$xmlType = 'dateTime';
							break;
						case 'uniqueidentifier':
							/* Uniqueidentifier - This gets treated as a guid */
							$xmlType = 'guid';
							break;
						case 'picklist':
						case 'state':
						case 'status':
							/* OptionSetValue - Just get the numerical value, but as an XML structure */
							$xmlType = 'OptionSetValue';
							$xmlTypeNS = 'http://schemas.microsoft.com/xrm/2011/Contracts';
							$xmlValue = NULL;
							$xmlValueChild = $entityDOM->createElement('b:Value', $this->propertyValues[$property]['Value']->Value);
							break;
						case 'boolean':
							/* Boolean - Just get the numerical value */
							if (is_object($this->propertyValues[$property]['Value']))
								$xmlValue = $this->propertyValues[$property]['Value']->Value;
							else $xmlValue = $this->propertyValues[$property]['Value'];
							break;
						case 'string':
						case 'int':
						case 'decimal':
						case 'double':
						case 'Money':
						case 'guid':
							/* No special handling for these types */
							break;
						default:
							/* If we're using Default, Warn user that the XML handling is not defined */
							trigger_error('No Create/Update handling implemented for type '.$propertyDetails['Type'].' used by field '.$property,
									E_USER_WARNING);
					}
					/* Now create the XML Node for the Value */
					$valueNode = $propertyNode->appendChild($entityDOM->createElement('c:value'));

					if ($xmlValue !== null || $xmlType == 'OptionSetValue')
					{
						/* Set the Type of the Value */
						$valueNode->setAttribute('i:type', 'd:'.$xmlType);
						$valueNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:d', $xmlTypeNS);
						/* If there is a child node needed, append it */
						if ($xmlValueChild !== NULL) $valueNode->appendChild($xmlValueChild);
					}
					else
					{
						$valueNode->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'i:nil', 'true');
					}

					/* If there is a value, set it */
					if ($xmlValue !== NULL) $valueNode->appendChild(new DOMText($xmlValue));
				}
			}
		}
		/* Entity State */
		$entityNode->appendChild($entityDOM->createElement('b:EntityState'))->setAttribute('i:nil', 'true');
		/* Formatted Values */
		$formattedValuesNode = $entityNode->appendChild($entityDOM->createElement('b:FormattedValues'));
		$formattedValuesNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.datacontract.org/2004/07/System.Collections.Generic');
		/* Entity ID */
		$entityNode->appendChild($entityDOM->createElement('b:Id', $this->getID()));
		/* Logical Name */
		$entityNode->appendChild($entityDOM->createElement('b:LogicalName', $this->entityLogicalName));
		/* Related Entities */
		$relatedEntitiesNode = $entityNode->appendChild($entityDOM->createElement('b:RelatedEntities'));
		$relatedEntitiesNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.datacontract.org/2004/07/System.Collections.Generic');
		/* Return the root node for the Entity */
		return $entityNode;
	}

	/**
	 * Generate an Entity based on a particular Logical Name - will try to be as Strongly Typed as possible
	 *
	 * @param DynamicsCRM2011_Connector $conn
	 * @param String $entityLogicalName
	 * @return DynamicsCRM2011_Entity of the specified type, or a generic Entity if no Class exists
	 */
	public static function fromLogicalName(DynamicsCRM2011_Connector $conn, $entityLogicalName) {
		/* Determine which Class we will create */
		$entityClassName = self::getClassName($entityLogicalName);
		/* If a specific class for this Entity doesn't exist, use the Entity class */
		if (!class_exists($entityClassName, true)) {
			$entityClassName = 'DynamicsCRM2011_Entity';
		}
		/* Create a new instance of the Class */
		return new $entityClassName($conn, $entityLogicalName);
	}

	/**
	 * Generate an Entity from the DOM object that describes its properties
	 *
	 * @param DynamicsCRM2011_Connector $conn
	 * @param String $entityLogicalName
	 * @param DOMElement $domNode
	 * @return DynamicsCRM2011_Entity of the specified type, with the properties found in the DOMNode
	 */
	public static function fromDOM(DynamicsCRM2011_Connector $conn, $entityLogicalName, DOMElement $domNode) {
		/* Create a new instance of the appropriate Class */
		$entity = self::fromLogicalName($conn, $entityLogicalName);

		/* Store values from the main RetrieveResult node */
		$relatedEntitiesNode = NULL;
		$attributesNode = NULL;
		$formattedValuesNode = NULL;
		$retrievedEntityName = NULL;
		$entityState = NULL;

 		/* Loop through the nodes directly beneath the RetrieveResult node */
 		foreach ($domNode->childNodes as $childNode) {
			switch ($childNode->localName) {
				case 'RelatedEntities':
					$relatedEntitiesNode = $childNode;
					break;
				case 'Attributes':
					$attributesNode = $childNode;
					break;
				case 'FormattedValues':
					$formattedValuesNode = $childNode;
					break;
				case 'Id':
					/* Set the Entity ID */
					$entity->ID = $childNode->textContent;
					break;
		 		case 'LogicalName':
		 			$retrievedEntityName = $childNode->textContent;
		 			break;
		 		case 'EntityState':
		 			$entityState = $childNode->textContent;
		 			break;
			}
 		}

 		/* Verify that the Retrieved Entity Name matches the expected one */
 		if ($retrievedEntityName != $entityLogicalName) {
 			trigger_error('Expected to get a '.$entityLogicalName.' but actually received a '.$retrievedEntityName.' from the server!',
 					E_USER_WARNING);
 		}

 		/* Log the Entity State - Never seen this used! */
 		if (self::$debugMode) echo 'Entity <'.$entity->ID.'> has EntityState: '.$entityState.PHP_EOL;

 		/* Parse the Attributes & FormattedValues to set the properties of the Entity */
 		$entity->setAttributesFromDOM($conn, $attributesNode, $formattedValuesNode);

		/* Before returning the Entity, reset it so all fields are marked unchanged */
		$entity->reset();
		return $entity;
	}

	/**
	 *
	 * @param DynamicsCRM2011_Connector $conn
	 * @param DOMElement $attributesNode
	 * @param DOMElement $formattedValuesNode
	 * @ignore
	 */
	private function setAttributesFromDOM(DynamicsCRM2011_Connector $conn, DOMElement $attributesNode, DOMElement $formattedValuesNode) {
		/* First, parse out the FormattedValues - these will be required when analysing Attributes */
		$formattedValues = Array();
		/* Identify the FormattedValues */
		$keyValueNodes = $formattedValuesNode->getElementsByTagName('KeyValuePairOfstringstring');
		/* Add the Formatted Values in the Key/Value Pairs of String/String to the Array */
		self::addFormattedValues($formattedValues, $keyValueNodes);

		/* Identify the Attributes */
		$keyValueNodes = $attributesNode->getElementsByTagName('KeyValuePairOfstringanyType');
		foreach ($keyValueNodes as $keyValueNode) {
			/* Get the Attribute name (key) */
			$attributeKey = $keyValueNode->getElementsByTagName('key')->item(0)->textContent;
			/* Check the Value Type */
			$attributeValueType = $keyValueNode->getElementsByTagName('value')->item(0)->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type');
			/* Strip any Namespace References from the Type */
			$attributeValueType = self::stripNS($attributeValueType);
			/* Get the basic Text Content of the Attribute */
			$attributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->textContent;
			/* Handle the Value in an appropriate way */
			switch ($attributeValueType) {
				case 'string':
				case 'guid':
					/* String, Guid - just take the attribute text content */
					$storedValue = $attributeValue;
					break;
				case 'dateTime':
					/* Date/Time - Parse this into a PHP Date/Time */
					$storedValue = self::parseTime($attributeValue, '%Y-%m-%dT%H:%M:%SZ');
					break;
				case 'boolean':
					/* Boolean - Map "True" to TRUE, all else is FALSE (case insensitive) */
					$storedValue = (strtolower($attributeValue) == 'true' ? true : false);
					break;
				case 'decimal':
				case 'double':
				case 'Money':
					/* Decimal - Cast the String to a Float */
					$storedValue = (float)$attributeValue;
					break;
				case 'int':
					/* Int - Cast the String to an Int */
					$storedValue = (int)$attributeValue;
					break;
				case 'OptionSetValue':
					/* OptionSetValue - We need the Numerical Value for Updates, Text for Display */
					$optionSetValue = (int)$attributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Value')->item(0)->textContent;
					$storedValue = new DynamicsCRM2011_OptionSetValue($optionSetValue, $formattedValues[$attributeKey]);
					/* Check if we have a matching "xxxName" property, and set that too */
					if (array_key_exists($attributeKey.'name', $this->properties)) {
						/* Don't overwrite something that's already set */
						if ($this->propertyValues[$attributeKey.'name']['Value'] == NULL) {
							$this->propertyValues[$attributeKey.'name']['Value'] = $formattedValues[$attributeKey];
						}
					}
					break;
				case 'EntityReference':
					/* EntityReference - We need the Id and Type to create a placeholder Entity */
					$entityReferenceType = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('LogicalName')->item(0)->textContent;
					$entityReferenceId = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Id')->item(0)->textContent;
					/* Also get the Name of the Entity - might be able to store this for View */
					$entityReferenceName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Name')->item(0)->textContent;
					/* Create the Placeholder Entity */
					$storedValue = self::fromLogicalName($conn, $entityReferenceType);
					$storedValue->ID = $entityReferenceId;
					/* Check if we have a matching "xxxName" property, and set that too */
					if (array_key_exists($attributeKey.'name', $this->properties)) {
						/* Don't overwrite something that's already set */
						if ($this->propertyValues[$attributeKey.'name']['Value'] == NULL) {
							$this->propertyValues[$attributeKey.'name']['Value'] = $entityReferenceName;
						}
						/* If the Entity has a defined way to get the Display Name, use it too */
						if ($storedValue->entityDisplayName != NULL) {
							$storedValue->propertyValues[$storedValue->entityDisplayName]['Value'] = $entityReferenceName;
						}
					}
					break;
				case 'AliasedValue':
					/* If there is a "." in the AttributeKey, it's a proper "Entity" alias */
					/* Otherwise, it's an Alias for an Aggregate Field */
					if (strpos($attributeKey, '.') === FALSE) {
						/* This is an Aggregate Field alias - do NOT create an Entity */
						$aliasedFieldName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('AttributeLogicalName')->item(0)->textContent;
						/* Create a new Attribute on this Entity for the Alias */
						$this->localProperties[$attributeKey] = Array(
								'Label' => 'AliasedValue: '.$attributeKey,
								'Description' => 'Aggregate field with alias '.$attributeKey.' based on field '.$aliasedFieldName,
								'isCustom' => true,
								'isPrimaryId' => false,
								'isPrimaryName' => false,
								'Type'  => 'AliasedValue',
								'isLookup' => false,
								'lookupTypes' => NULL,
								'Create' => false,
								'Update' => false,
								'Read'   => true,
								'RequiredLevel' => 'None',
								'AttributeOf' => NULL,
								'OptionSet' => NULL,
							);
						$this->propertyValues[$attributeKey] = Array(
								'Value'  => NULL,
								'Changed' => false,
							);
						/* Determine the Value for this field */
						$valueType =  $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Value')->item(0)->getAttribute('type');
						$storedValue = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Value')->item(0)->textContent;
					} else {
						/* For an AliasedValue, we need to find the Alias first */
						list($aliasName, $aliasedFieldName) = explode('.', $attributeKey);
						/* Get the Entity type that is being Aliased */
						$aliasEntityName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('EntityLogicalName')->item(0)->textContent;
						/* Get the Field of the Entity that is being Aliased */
						$aliasedFieldName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('AttributeLogicalName')->item(0)->textContent;
						/* Next, check if this Alias already has been used */
						if (array_key_exists($aliasName, $this->propertyValues)) {
							/* Get the existing Entity */
							$storedValue = $this->propertyValues[$aliasName]['Value'];
							/* Check if the existing Entity is NULL */
							if ($storedValue == NULL) {
								/* Create a new Entity of the appropriate type */
								$storedValue = self::fromLogicalName($conn, $aliasEntityName);
								/* Alias overlaps with normal field - check this is allowed */
								if (!in_array($aliasEntityName, $this->properties[$aliasName]['lookupTypes'])) {
									trigger_error('Alias '.$aliasName.' overlaps and existing field of type '.implode(' or ', $this->properties[$aliasName]['lookupTypes'])
											.' but is being set to a '.$aliasEntityName,
											E_USER_WARNING);
								}
							} else {
								/* Check it's the right type */
								if ($storedValue->logicalName != $aliasEntityName) {
									trigger_error('Alias '.$aliasName.' was created as a '.$storedValue->logicalName.' but is now referenced as a '.$aliasEntityName.' in field '.$attributeKey,
											E_USER_WARNING);
								}
							}
						} else {
							/* Create a new Entity of the appropriate type */
							$storedValue = self::fromLogicalName($conn, $aliasEntityName);
							/* Create a new Attribute on this Entity for the Alias */
							$this->localProperties[$aliasName] = Array(
									'Label' => 'AliasedValue: '.$aliasName,
									'Description' => 'Related '.$aliasEntityName.' with alias '.$aliasName,
									'isCustom' => true,
									'isPrimaryId' => false,
									'isPrimaryName' => false,
									'Type'  => 'AliasedValue',
									'isLookup' => true,
									'lookupTypes' => NULL,
									'Create' => false,
									'Update' => false,
									'Read'   => true,
									'RequiredLevel' => 'None',
									'AttributeOf' => NULL,
									'OptionSet' => NULL,
								);
							$this->propertyValues[$aliasName] = Array(
									'Value'  => NULL,
									'Changed' => false,
								);
						}
						/* Re-create the DOMElement for just this Attribute */
						$aliasDoc = new DOMDocument();
						$aliasAttributesNode = $aliasDoc->appendChild($aliasDoc->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts', 'b:Attributes'));
						$aliasAttributeNode = $aliasAttributesNode->appendChild($aliasDoc->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts', 'b:KeyValuePairOfstringanyType'));
						$aliasAttributeNode->appendChild($aliasDoc->createElementNS('http://schemas.datacontract.org/2004/07/System.Collections.Generic', 'c:key', $aliasedFieldName));
						$aliasAttributeValueNode = $aliasAttributeNode->appendChild($aliasDoc->createElementNS('http://schemas.datacontract.org/2004/07/System.Collections.Generic', 'c:value'));
						/* Ensure we have all the child nodes of the Value */
						foreach ($keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Value')->item(0)->childNodes as $child){
							$aliasAttributeValueNode->appendChild($aliasDoc->importNode($child, true));
						}
						/* Ensure we have the Type attribute, with Namespace */
						$aliasAttributeValueNode->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'i:type',
								$keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Value')->item(0)->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type'));
						/* Re-create the DOMElement for this Attribute's FormattedValue */
						$aliasFormattedValuesNode = $aliasDoc->appendChild($aliasDoc->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts', 'b:FormattedValues'));
						$aliasFormattedValuesNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.datacontract.org/2004/07/System.Collections.Generic');
						/* Check if there is a formatted value to add */
						if (array_key_exists($attributeKey, $formattedValues)) {
							$aliasFormattedValueNode = $aliasFormattedValuesNode->appendChild($aliasDoc->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts', 'b:KeyValuePairOfstringstring'));
							$aliasFormattedValueNode->appendChild($aliasDoc->createElementNS('http://schemas.datacontract.org/2004/07/System.Collections.Generic', 'c:key', $aliasedFieldName));
							$aliasFormattedValueNode->appendChild($aliasDoc->createElementNS('http://schemas.datacontract.org/2004/07/System.Collections.Generic', 'c:value', $formattedValues[$attributeKey]));
						}
						/* Now set the DOM values on the Entity */
						$storedValue->setAttributesFromDOM($conn, $aliasAttributesNode, $aliasFormattedValuesNode);
						/* Finally, ensure that this is stored on the Entity using the Alias */
						$attributeKey = $aliasName;
					}
					break;
				default:
					trigger_error('No parse handling implemented for type '.$attributeValueType.' used by field '.$attributeKey,
							E_USER_WARNING);
					$attributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->C14N();
					/* Check for a Formatted Value */
					if (array_key_exists($attributeKey, $formattedValues)) {
						$storedValue = Array('XML' => $attributeValue, 'FormattedText' => $formattedValues[$attributeKey]);
					} else {
						$storedValue = $attributeValue;
					}
			}
			/* Bypass __set, and set the Value directly in the Properties array */
			$this->propertyValues[$attributeKey]['Value'] = $storedValue;
			/* If we have just set the Primary ID of the Entity, update the ID field if necessary */
			/* Note that "localProperties" (AliasedValues) cannot be a Primary ID */
			if (array_key_exists($attributeKey, $this->properties) && $this->properties[$attributeKey]['isPrimaryId'] && $this->entityID == NULL) {
				/* Only if the new value is valid */
				if ($storedValue != NULL && $storedValue != self::EmptyGUID) {
					$this->entityID = $storedValue;
				}
			}
		}
	}

	/**
	 * Print a human-readable summary of the Entity with all details and fields
	 *
	 * @param boolean $recursive if TRUE, prints full details for all sub-entities as well
	 * @param int $tabLevel the started level of indentation used (tabs)
	 * @param boolean $printEmpty if TRUE, prints the details of NULL fields
	 */
	public function printDetails($recursive = false, $tabLevel = 0, $printEmpty = true) {
		/* Print the Entity Summary at current Tab level */
		echo str_repeat("\t", $tabLevel).$this.' ('.$this->getURL(true).')'.PHP_EOL;
		/* Increment the tabbing level */
		$tabLevel++;
		$linePrefix = str_repeat("\t", $tabLevel);
		/* Get a list of properties of this Entity, in Alphabetical order */
		$propertyList = array_keys($this->propertyValues);
		sort($propertyList);
		/* Loop through each property */
		foreach ($propertyList as $property) {
			/* Get the details of the Property */
			if (array_key_exists($property, $this->properties)) {
				$propertyDetails = $this->properties[$property];
			} else {
				$propertyDetails = $this->localProperties[$property];
			}

			/* In Recursive Mode, don't display "AttributeOf" fields */
			if ($recursive && $propertyDetails['AttributeOf'] != NULL) continue;
			/* Don't print NULL fields if printEmpty is FALSE */
			if (!$printEmpty && $this->propertyValues[$property]['Value'] == NULL) continue;
			/* Output the Property Name & Description */
			echo $linePrefix.$property.' ['.$propertyDetails['Label'].']: ';
			/* For NULL values, just output NULL and the Type on one line */
			if ($this->propertyValues[$property]['Value'] == NULL) {
				echo 'NULL ('.$propertyDetails['Type'].')'.PHP_EOL;
				continue;
			} else {
				echo PHP_EOL;
			}
			/* Handle the Lookup types */
			if ($propertyDetails['isLookup']) {
				/* EntityReference - Either just summarise the Entity, or Recurse */
				if ($recursive) {
					$this->propertyValues[$property]['Value']->printDetails($recursive, $tabLevel+1);
				} else {
					echo $linePrefix."\t".$this->propertyValues[$property]['Value'].PHP_EOL;
				}
				continue;
			}
			/* Any other Property Type - depending on its Type */
			switch ($propertyDetails['Type']) {
				case 'DateTime':
					/* Date/Time - Print this as a formatted Date/Time */
					echo $linePrefix."\t".date('Y-m-d H:i:s P', $this->propertyValues[$property]['Value']).PHP_EOL;
					break;
				case 'Boolean':
					/* Boolean - Print as TRUE or FALSE */
					if ($this->propertyValues[$property]['Value']) {
						echo $linePrefix."\t".'('.$propertyDetails['Type'].') TRUE'.PHP_EOL;
					} else {
						echo $linePrefix."\t".'('.$propertyDetails['Type'].') FALSE'.PHP_EOL;
					}
					break;
				case 'Picklist':
				case 'State':
				case 'Status':
				case 'Decimal':
				case 'Double':
				case 'Money':
				case 'Uniqueidentifier':
				case 'Memo':
				case 'String':
				case 'Virtual':
				case 'EntityName':
				case 'Integer':
					/* Just cast it to a String to display */
					echo $linePrefix."\t".'('.$propertyDetails['Type'].') '. $this->propertyValues[$property]['Value'].PHP_EOL;
					break;
				default:
					/* If we're using Default, Warn user that the output handling is not defined */
					trigger_error('No output handling implemented for type '.$propertyDetails['Type'].' used by field '.$property,
							E_USER_WARNING);
					/* Use print_r to display unknown formats */
					echo $linePrefix."\t".'('.$propertyDetails['Type'].') '.print_r($this->propertyValues[$property]['Value'], true).PHP_EOL;
			}
		}
	}

	/**
	 * Get a URL that can be used to directly open the Entity Details on the CRM
	 *
	 * @param boolean $absolute If true, include the full domain; otherwise, just return a relative URL.
	 * @return NULL|string the URL for the Entity on the CRM
	 */
	public function getURL($absolute = false) {
		/* Cannot return a URL for an Entity with no ID */
		if ($this->entityID == NULL) return NULL;
		/* The "relative" part of the Entity URL */
		$entityURL = 'main.aspx?etn='.$this->entityLogicalName.'&pagetype=entityrecord&id='.$this->entityID;
		/* If we want an Absolute URL, pre-pend the Domain for the Entity */
		if ($absolute) {
			return $this->entityDomain.$entityURL;
		} else {
			return $entityURL;
		}
	}

	/**
	 * Update the Domain Name that this Entity will use when constructing an absolute URL
	 * @param DynamicsCRM2011_Connector $conn Connection to the Server currently used
	 */
	protected function setEntityDomain(DynamicsCRM2011_Connector $conn) {
		/* Get the URL of the Organization */
		$organizationURL = $conn->getOrganizationURI();
		$urlDetails = parse_url($organizationURL);
		/* Generate the base URL for Entities */
		$domainURL = $urlDetails['scheme'].'://'.$urlDetails['host'].'/';
		/* If the Organization Unique Name is part of the Organization URL, add it to the Domain */
		if (strstr($organizationURL, '/'.$conn->getOrganization().'/') !== FALSE) {
			$domainURL = $domainURL . $conn->getOrganization() .'/';
		}
		/* Update the Entity */
		$this->entityDomain = $domainURL;
	}

	/**
	 * Get the possible values for a particular OptionSet property
	 *
	 * @param String $property to list values for
	 * @return Array list of the available options for this Property
	 */
	public function getOptionSetValues($property) {
		/* Check that the specified property exists */
		$property = strtolower($property);
		if (!array_key_exists($property, $this->properties)) return NULL;
		/* Check that the specified property is indeed an OptionSet */
		$optionSetName = $this->properties[$property]['OptionSet'];
		if ($optionSetName == NULL) return NULL;
		/* Return the available options for this property */
		return $this->optionSets[$optionSetName];
	}

	/**
	 * Get the label for a field
	 *
	 * @param String $property
	 * @return string
	 */
	public function getPropertyLabel($property) {
		/* Handle dynamic properties... */
		$property = strtolower($property);
		/* Only return the value if it exists & is readable */
		if (array_key_exists($property, $this->properties)) {
			return $this->properties[$property]['Label'];
		}
		/* Also check for an AliasedValue */
		if (array_key_exists($property, $this->localProperties)) {
			return $this->localProperties[$property]['Label'];
		}
		/* Property doesn't exist, return empty string */
		return '';
	}

	/**
	 * Reset the fields so this Entity can be used in a Create
	 *
	 * @param DynamicsCRM2011_Connector $conn - the connection that will be used to recreate this entity
	 */
	public function resetForCreate(DynamicsCRM2011_Connector $conn = NULL) {
		/* Clear the ID */
		$this->entityID = NULL;
		/* If we're moving Server, reset the Domain */
		if ($conn != NULL)  $this->setEntityDomain($conn);

		/* Loop through all the properties */
		foreach ($this->properties as $property => $propertyDetails) {
			/* Check if the property can be set on Create */
			if ($propertyDetails['Create'] == false || $propertyDetails['Type'] == 'Uniqueidentifier') {
				/* If the property can't be set on Create, clear it and mark it Unchanged */
				$this->propertyValues[$property]['Changed'] = false;
				$this->propertyValues[$property]['Value'] = NULL;
			} elseif ($conn != NULL && $propertyDetails['isLookup'] && $this->propertyValues[$property]['Value'] != NULL) {
				/* If the property is a non-null lookup, and we are moving server, find the new value */
				$oldEntity = $this->propertyValues[$property]['Value'];
				$newEntity = $conn->retrieveByName($oldEntity->entityLogicalName, $oldEntity->entityDisplayName, $oldEntity->DisplayName);
				/* If we found the value, mark it as changed - if not, mark it as unchanged */
				if (is_array($newEntity)) {
					/* Multiple options found - log a warning, use the first one */
					trigger_error('Multiple options for '.$property.' when moving to the new CRM instance: using first value found!', E_USER_WARNING);
					$this->propertyValues[$property]['Value'] = $newEntity[0];
					$this->propertyValues[$property]['Changed'] = true;
				} elseif ($newEntity != NULL) {
					/* One match found - use it */
					$this->propertyValues[$property]['Value'] = $newEntity;
					$this->propertyValues[$property]['Changed'] = true;
				} else {
					/* No matches found - log a warning */
					trigger_error('No new value for '.$property.' when moving to the new CRM instance: clearing!', E_USER_WARNING);
					$this->propertyValues[$property]['Value'] = NULL;
					$this->propertyValues[$property]['Changed'] = false;
				}
			} else {
				/* Otherwise, leave as is and mark it changed if not NULL */
				$this->propertyValues[$property]['Changed'] = ($this->propertyValues[$property]['Value'] != NULL);
			}
		}
	}
}

?>