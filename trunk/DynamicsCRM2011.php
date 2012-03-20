<?php

interface DynamicsCRM2011_Interface {
	const EmptyGUID = '00000000-0000-0000-0000-000000000000';
	const MAX_CRM_RECORDS = 5000;
}

abstract class DynamicsCRM2011 implements DynamicsCRM2011_Interface {
	/* Internal details */
	protected static $debugMode = FALSE;
	
	/**
	 * Implementation of Class Autoloader
	 * See http://www.php.net/manual/en/function.spl-autoload-register.php
	 *
	 * @param String $className the name of the Class to load
	 */
	public static function loadClass($className){
		/* Only load classes that don't exist, and are part of DynamicsCRM2011 */
		if ((class_exists($className)) || (strpos($className, 'DynamicsCRM2011') === false)) {
			return false;
		}
	
		/* Work out the filename of the Class to be loaded.
		 * NOTE: If this ever moves to a directory structure, we will need to
		 * compensate for relative paths etc.
		 */
		
		$classFilePath = $className.'.class.php';
	
		/* Only try to load files that actually exist and can be read */
		if ((file_exists($classFilePath) === false) || (is_readable($classFilePath) === false)) {
			return false;
		}
	
		/* Don't load it if it's already been loaded */
		require_once $classFilePath;
	}
	
	/**
	 * Utility function to get the appropriate Class name for a particular Entity.
	 * Note that the class may not actually exist - this function just returns
	 * the name of the class, which can then be used in a class_exists test.
	 * 
	 * The class name is normally DynamicsCRM2011_Entity_Name_Capitalised,
	 * e.g. DyanmicsCRM2011_Incident, or DynamicsCRM2011_Account
	 * 
	 * @param String $entityLogicalName
	 * @return String the name of the class
	 */
	public static function getClassName($entityLogicalName) {
		/* Since EntityLogicalNames are usually in lowercase, we captialise each word */
		$capitalisedEntityName = self::capitaliseEntityName($entityLogicalName);
		$className = 'DynamicsCRM2011_'.$capitalisedEntityName;
		/* Return the generated class name */
		return $className;
	}
	
	/**
	 * Utility function to captialise the Entity Name according to the following rules:
	 * 1. The first letter of each word in the Entity Name is capitalised
	 * 2. Words are separated by underscores only
	 * 
	 * @param String $entityLogicalName as it is stored in the CRM
	 * @return String the Entity Name as it would be in a PHP Class name
	 */
	private static function capitaliseEntityName($entityLogicalName) {
		/* User-defined Entities generally have underscore separated names 
		 * e.g. mycompany_special_item
		 * We capitalise this as Mycompany_Special_Item
		 */
		$words = explode('_', $entityLogicalName);
		foreach($words as $key => $word) $words[$key] = ucwords(strtolower($word));
		$capitalisedEntityName = implode('_', $words);
		/* Return the capitalised name */
		return $capitalisedEntityName;
	}
	
	/**
	 * Utility function to strip any Namespace from an XML attribute value
	 * @param String $attributeValue
	 * @return String Attribute Value without the Namespace
	 */
	protected static function stripNS($attributeValue) {
		return preg_replace('/[a-zA-Z]+:([a-zA-Z]+)/', '$1', $attributeValue);
	}
	
	/**
	 * Get the current time, as required in XML format
	 * @ignore
	 */
	protected static function getCurrentTime() {
		return substr(gmdate('c'),0,-6) . ".00";
	}
	
	/**
	 * Get an appropriate expiry time for the XML requests, as required in XML format
	 * @ignore
	 */
	protected static function getExpiryTime() {
		return substr(gmdate('c', strtotime('+1 minute')),0,-6) . ".00";
	}
	
	/**
	 * Enable or Disable DEBUG for the Class
	 * @ignore
	 */
	public static function setDebug($_debugMode) {
		self::$debugMode = $_debugMode;
	}
	
	/**
	 * Utility function to parse time from XML - includes handling Windows systems with no strptime
	 * @param String $timestamp
	 * @param String $formatString
	 * @return integer PHP Timestamp
	 * @ignore
	 */
	protected static function parseTime($timestamp, $formatString) {
		/* Quick solution: use strptime */
		if(function_exists("strptime") == true) {
			$time_array = strptime($timestamp, $formatString);
		} else {
			$masks = Array(
					'%d' => '(?P<d>[0-9]{2})',
					'%m' => '(?P<m>[0-9]{2})',
					'%Y' => '(?P<Y>[0-9]{4})',
					'%H' => '(?P<H>[0-9]{2})',
					'%M' => '(?P<M>[0-9]{2})',
					'%S' => '(?P<S>[0-9]{2})',
					// usw..
			);
			$rexep = "#".strtr(preg_quote($formatString), $masks)."#";
			if(!preg_match($rexep, $timestamp, $out)) return false;
			$time_array = Array(
					"tm_sec"  => (int) $out['S'],
					"tm_min"  => (int) $out['M'],
					"tm_hour" => (int) $out['H'],
					"tm_mday" => (int) $out['d'],
					"tm_mon"  => $out['m']?$out['m']-1:0,
					"tm_year" => $out['Y'] > 1900 ? $out['Y'] - 1900 : 0,
			);
	
	
		}
		$phpTimestamp = mktime($time_array['tm_hour'], $time_array['tm_min'], $time_array['tm_sec'],
				$time_array['tm_mon']+1, $time_array['tm_mday'], 1900+$time_array['tm_year']);
		return $phpTimestamp;
	
	}
	
	/**
	 * Add a list of Formatted Values to an Array of Attributes, using appropriate handling
	 * avoiding over-writing existing attributes already in the array
	 *
	 * Optionally specify an Array of sub-keys, and a particular sub-key
	 * - If provided, each sub-key in the Array will be created as an Object attribute,
	 *   and the value will be set on the specified sub-key only (e.g. (New, Old) / New)
	 *
	 * @ignore
	 */
	protected static function addFormattedValues(Array &$targetArray, DOMNodeList $keyValueNodes, Array $keys = NULL, $key1 = NULL) {
		foreach ($keyValueNodes as $keyValueNode) {
			/* Get the Attribute name (key) */
			$attributeKey = $keyValueNode->getElementsByTagName('key')->item(0)->textContent;
			$attributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->textContent;
			/* If we are working normally, just store the data in the array */
			if ($keys == NULL) {
				/* Assume that if there is a duplicate, it's an un-formatted version of this */
				if (array_key_exists($attributeKey, $targetArray)) {
					$targetArray[$attributeKey] = (Object)Array(
							'Value' => $targetArray[$attributeKey],
							'FormattedValue' => $attributeValue
					);
				} else {
					$targetArray[$attributeKey] = $attributeValue;
				}
			} else {
				/* Store the data in the array for this AuditRecord's properties */
				if (array_key_exists($attributeKey, $targetArray)) {
					/* We assume it's already a "good" Object, and just set this key */
					if (isset($targetArray[$attributeKey]->$key1)) {
						/* It's already set, so add the Formatted version */
						$targetArray[$attributeKey]->$key1 = (Object)Array(
								'Value' => $targetArray[$attributeKey]->$key1,
								'FormattedValue' => $attributeValue);
					} else {
						/* It's not already set, so just set this as a value */
						$targetArray[$attributeKey]->$key1 = $attributeValue;
					}
				} else {
					/* We need to create the Object */
					$obj = (Object)Array();
					foreach ($keys as $k) {
						$obj->$k = NULL;
					}
					/* And set the particular property */
					$obj->$key1 = $attributeValue;
					/* And store the Object in the target Array */
					$targetArray[$attributeKey] = $obj;
				}
			}
		}
	}
}

/* Register the Class Loader */
spl_autoload_register(Array('DynamicsCRM2011', 'loadClass'));

?>
