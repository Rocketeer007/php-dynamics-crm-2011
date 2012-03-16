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
		return substr(date('c'),0,-6) . ".00";
	}
	
	/**
	 * Get an appropriate expiry time for the XML requests, as required in XML format
	 * @ignore
	 */
	protected static function getExpiryTime() {
		return substr(date('c', strtotime('+1 minute')),0,-6) . ".00";
	}
	
	/**
	 * Enable or Disable DEBUG for the Class
	 * @ignore
	 */
	public static function setDebug($_debugMode) {
		self::$debugMode = $_debugMode;
	}
}

/* Register the Class Loader */
spl_autoload_register(Array('DynamicsCRM2011', 'loadClass'));

?>
