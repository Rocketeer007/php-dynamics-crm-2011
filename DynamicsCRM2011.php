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
}

/* Register the Class Loader */
spl_autoload_register(Array('DynamicsCRM2011', 'loadClass'));

?>
