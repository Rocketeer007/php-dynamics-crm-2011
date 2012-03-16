<?php

interface DynamicsCRM2011_Interface {
	const EmptyGUID = '00000000-0000-0000-0000-000000000000';
}

abstract class DynamicsCRM2011 implements DynamicsCRM2011_Interface {
	/**
	 * Utility function to strip any Namespace from an XML attribute value
	 * @param String $attributeValue
	 * @return String Attribute Value without the Namespace
	 */
	protected static function stripNS($attributeValue) {
		return preg_replace('/[a-zA-Z]+:([a-zA-Z]+)/', '$1', $attributeValue);
	}
}

require_once 'DynamicsCRM2011_Connector.class.php';
require_once 'DynamicsCRM2011_Entity.class.php';
require_once 'DynamicsCRM2011_Incident.class.php';
require_once 'DynamicsCRM2011_Account.class.php';
