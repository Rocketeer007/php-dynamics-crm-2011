<?php
/**
 * DynamicsCRM2011_Connector.class.php
 *
 * This file defines the DynamicsCRM2011_Connector class that can be used to access
 * the Microsoft Dynamics 2011 system through SOAP calls from PHP.
 *
 * @author Nick Price
 * @version $Revision: 1.7 $
 * @package DynamicsCRM2011
 *
 * ChangeLog
 * $Log: DynamicsCRM2011Connector.class.php,v $
 * Revision 1.7  2012/02/28 18:34:32  nick.price
 * Added error handling for Soap Errors
 *
 * Revision 1.6  2012/02/28 17:39:12  nick.price
 * Removed additional debug
 *
 * Revision 1.5  2012/02/28 17:37:21  nick.price
 * Added RetrieveEntity functionality to get definition of an Entity
 *
 * Revision 1.4  2012/02/27 23:53:32  nick.price
 * Improved handling consistency of various Key/Value Types
 * AliasedValue fields now preserve the Alias, allowing multiple references to the same Entity Type in one query
 * Implemented handling of Retrieve function
 * Added limitCount to RetrieveMultiple functions
 *
 * Revision 1.3  2012/02/27 20:55:05  nick.price
 * New function to show possible SoapActions
 * Refactored to use RetrieveMultiple directly, rather than via an Execute call
 *
 * Revision 1.2  2012/02/24 10:12:07  nick.price
 * Added functionality for retrieving RecordChangeHistory data
 *
 * Revision 1.1  2012/02/16 16:38:31  nick.price
 * Created Connector Class for Microsoft Dynamics CRM 2011
 *
 */


/**
 * This class creates and manages SOAP connections to a Microsoft Dynamics CRM 2011 server
 *
 * Authentication requirements are all handled automatically - although only
 * Federation security is current supported, and an Exception is generated if
 * any other security method is detected on the server.
 *
 * The goal of this class is to make it as simple as possible to use SOAP data fetched
 * directly from the Dynamics CRM server, without having to manually generate long stretches
 * of XML to be converted into SOAP calls.
 *
 * Additionally, the returned data can be parsed in such a way that it can be used as
 * simple PHP Objects, rather than complex XML to be parsed.
 */
class DynamicsCRM2011_Connector extends DynamicsCRM2011 {
	/* Organization Details */
	protected $discoveryURI;
	protected $organizationUniqueName;
	protected $organizationURI;
	/* Security Details */
	protected $security = Array();
	protected $callerId = NULL;
	/* Cached Discovery data */
	protected $discoveryDOM;
	protected $discoverySoapActions;
	protected $discoveryExecuteAction;
	protected $discoverySecurityPolicy;
	/* Cached Organization data */
	protected $organizationDOM;
	protected $organizationSoapActions;
	protected $organizationCreateAction;
	protected $organizationDeleteAction;
	protected $organizationExecuteAction;
	protected $organizationRetrieveAction;
	protected $organizationRetrieveMultipleAction;
	protected $organizationUpdateAction;
	protected $organizationSecurityPolicy;
	protected $organizationSecurityToken;
	/* Cached Entity Definitions */
	private $cachedEntityDefintions = Array();
	/* Connection Details */
	protected static $connectorTimeout = 600;
	protected static $maximumRecords = self::MAX_CRM_RECORDS;

	/**
	 * Create a new instance of the DynamicsCRM2011Connector
	 *
	 * This function is automatically called when a new instance is created.
	 * At a minimum, you must provide the URL of the DiscoveryService (which can
	 * be found on the Customizations / Developer Resources section of the Microsoft
	 * Dynamics CRM 2011 application), and the Unique Name of the Organization to connect
	 * to.  Note that it is often possible to connect to multiple Organizations from a
	 * single Discovery Service, which is why this parameter is mandatory.
	 *
	 * Optionally, you may supply the username & password to login to the server immediately.
	 *
	 * @param string $_discoveryURI the URL of the DiscoveryService
	 * @param string $_organizationUniqueName the Unique Name of the Organization to connect to
	 * @param string $_username the Username to login with
	 * @param string $_password the Password of the user
	 * @param boolean $_debug display debug information when accessing the server - not recommended in Production!
	 * @return DynamicsCRM2011Connector
	 */
	function __construct($_discoveryURI, $_organizationUniqueName = NULL, $_username = NULL, $_password = NULL, $_debug = FALSE) {
		/* Enable or disable debug mode */
		self::$debugMode = $_debug;

		/* Check if we're using a cached login */
		if (is_array($_discoveryURI)) {
			return $this->loadLoginCache($_discoveryURI);
		}

		/* Store the organization details */
		$this->discoveryURI = $_discoveryURI;
		$this->organizationUniqueName = $_organizationUniqueName;

		/* If either mandatory parameter is NULL, throw an Exception */
		if ($this->discoveryURI == NULL || $this->organizationUniqueName == NULL) {
			throw new BadMethodCallException(get_class($this).' constructor requires the Discovery URI and Organization Unique Name');
		}

		/* Store the security details */
		$this->security['username'] = $_username;
		$this->security['password'] = $_password;

		/* Determine the Security used by this Organization */
		$this->security['discovery_authmode'] = $this->getDiscoveryAuthenticationMode();

		/* Only Federation security is supported */
		if ($this->security['discovery_authmode'] != 'Federation') {
			throw new UnexpectedValueException(get_class($this).' does not support "'.$this->security['discovery_authmode'].'" authentication mode used by Discovery Service');
		}

		/* Determine the address to send security requests to */
		$this->security['discovery_authuri'] = $this->getDiscoveryAuthenticationAddress();

		/* Store the Security Service Endpoint for future use */
		$this->security['discovery_authendpoint'] = $this->getFederationSecurityURI('discovery');

		/* If we already have all the Discovery Security details, determine the Organization URI */
		if ($this->checkSecurity('discovery'))
			$this->organizationURI = $this->getOrganizationURI();
	}

	/**
	 * Set the Federation Security Details for the Discovery Service
	 *
	 * If the constructor was called without the username and password, you must call
	 * this function to login to the server.  Otherwise, all future calls to functions
	 * to retrieve data will fail.
	  *
	 * Note that the current implementation assumes that only one login and password
	 * is required for both the Discovery and Organization services.
	 *
	 * Once the login details are provided, the system will connect to the Discovery service
	 * and find the URL for the Organization Service for the Organization specified in the
	 * constructor.
	 *
	 * @param string $_username the Username to login with
	 * @param string $_password the Password of the user
	 * @return boolean indication as to whether the login details were successfully used to fetch the
	 * Organization service URL
	 * @throws Exception if the username or password is missing, or if the system does not use Federation security
	 */
	public function setDiscoveryFederationSecurity($_username, $_password) {
		/* Store the security details */
		$this->security['username'] = $_username;
		$this->security['password'] = $_password;
		/* If either mandatory parameter is NULL, throw an Exception */
		if ($this->security['username'] == NULL || $this->security['password'] == NULL) {
			throw new BadMethodCallException(get_class($this).' Federation Security requires both a Username & Password');
		}
		/* Store the Security Service Endpoint for future use */
		$this->security['discovery_authendpoint'] = $this->getFederationSecurityURI('discovery');
		/* If we already have all the Discovery Security details, determine the Organization URI */
		if ($this->checkSecurity('discovery'))
			$this->organizationURI = $this->getOrganizationURI();
		/* If this failed, return FALSE, otherwise return TRUE */
		if ($this->organizationURI == NULL) return FALSE;
		return TRUE;
	}

	/**
	 * Get the Discovery URL which is currently in use
	 * @return string the URL of the Discovery Service
	 */
	public function getDiscoveryURI() {
		return $this->discoveryURI;
	}

	/**
	 * Get the Organization Unique Name which is currently in use
	 * @return string the Unique Name of the Organization
	 */
	public function getOrganization() {
		return $this->organizationUniqueName;
	}

	/**
	 * Get the maximum records for a query
	 * @return int the maximum records that will be returned from RetrieveMultiple per page
	 */
	public static function getMaximumRecords() {
		return self::$maximumRecords;
	}

	/**
	 * Set the maximum records for a query
	 * @param int $_maximumRecords the maximum number of records to fetch per page
	 */
	public static function setMaximumRecords($_maximumRecords) {
		if (!is_int($_maximumRecords)) return;
		self::$maximumRecords = $_maximumRecords;
	}

	/**
	 * Get the connector timeout value
	 * @return int the maximum time the connector will wait for a response from the CRM in seconds
	 */
	public static function getConnectorTimeout() {
		return self::$connectorTimeout;
	}

	/**
	 * Set the connector timeout value
	 * @param int $_connectorTimeout maximum time the connector will wait for a response from the CRM in seconds
	 */
	public static function setConnectorTimeout($_connectorTimeout) {
		if (!is_int($_connectorTimeout)) return;
		self::$connectorTimeout = $_connectorTimeout;
	}

	/**
	 * Get the Discovery URL which is currently in use
	 * @return string the URL of the Organization service
	 * @throws Exception if the Discovery Service security details have not been set,
	 * or the Organization Service URL cannot be found for the current Organization
	 */
	public function getOrganizationURI() {
		/* If it's set, return the details from the class instance */
		if ($this->organizationURI != NULL) return $this->organizationURI;

		/* Check we have the appropriate security details for the Discovery Service */
		if ($this->checkSecurity('discovery') == FALSE)
			throw new Exception('Cannot determine Organization URI before Discovery Service Security Details are set!');

		/* Request a Security Token for the Discovery Service */
		$securityToken = $this->requestSecurityToken($this->security['discovery_authendpoint'], $this->discoveryURI, $this->security['username'], $this->security['password']);

		/* Determine the Soap Action for the Execute method of the Discovery Service */
		$discoveryServiceSoapAction = $this->getDiscoveryExecuteAction();

		/* Generate a Soap Request for the Retrieve Organization Request method of the Discovery Service */
		$discoverySoapRequest = self::generateSoapRequest($this->discoveryURI, $discoveryServiceSoapAction, $securityToken, self::generateRetrieveOrganizationRequest());
		$discovery_data = self::getSoapResponse($this->discoveryURI, $discoverySoapRequest);

		/* Parse the returned data to determine the correct EndPoint for the OrganizationService for the selected Organization */
		$organizationServiceURI = NULL;
		$discoveryDOM = new DOMDocument(); $discoveryDOM->loadXML($discovery_data);
		if ($discoveryDOM->getElementsByTagName('OrganizationDetail')->length > 0) {
			foreach ($discoveryDOM->getElementsByTagName('OrganizationDetail') as $organizationNode) {
				if ($organizationNode->getElementsByTagName('UniqueName')->item(0)->textContent == $this->organizationUniqueName) {
					foreach ($organizationNode->getElementsByTagName('Endpoints')->item(0)->getElementsByTagName('KeyValuePairOfEndpointTypestringztYlk6OT') as $endpointDOM) {
						if ($endpointDOM->getElementsByTagName('key')->item(0)->textContent == 'OrganizationService') {
							$organizationServiceURI = $endpointDOM->getElementsByTagName('value')->item(0)->textContent;
							break;
						}
					}
					break;
				}
			}
		} else {
			throw new Exception('Error fetching Organization details:'.PHP_EOL.$discovery_data);
			return FALSE;
		}
		if ($organizationServiceURI == NULL) {
			throw new Exception('Could not find OrganizationService URI for the Organization <'.$this->organizationUniqueName.'>');
			return FALSE;
		}
		$this->organizationURI = $organizationServiceURI;
		$this->cacheOrganizationDetails();
		return $organizationServiceURI;
	}

	/**
	 * Utility function to get the details of the Organization
	 * Determines the Authenticaion mode, Authentication URL & Endpoint and SoapAction
	 * @ignore
	 */
	private function cacheOrganizationDetails() {
		/* Check if this is already done... */
		if ($this->organizationSoapActions != NULL) return;

		/* Determine the Security used by this Organization */
		$this->security['organization_authmode'] = $this->getOrganizationAuthenticationMode();

		/* Only Federation security is supported */
		if ($this->security['organization_authmode'] != 'Federation') {
			throw new UnexpectedValueException(get_class($this).' does not support "'.$this->security['organization_authmode'].'" authentication mode used by Organization Service');
		}

		/* Determine the address to send security requests to */
		$this->security['organization_authuri'] = $this->getOrganizationAuthenticationAddress();
		/* Store the Security Service Endpoint for future use */
		$this->security['organization_authendpoint'] = $this->getFederationSecurityURI('organization');

		/* Determine the Soap Action for the Execute method of the Organization Service */
		$organizationExecuteAction = $this->getOrganizationExecuteAction();

	}

	/**
	 * Utility function to get the SoapAction for the Discovery Service
	 * @ignore
	 */
	private function getDiscoveryExecuteAction() {
		/* If it's not cached, update the cache */
		if ($this->discoveryExecuteAction == NULL) {
			$actions = $this->getAllDiscoverySoapActions();
			$this->discoveryExecuteAction = $actions['Execute'];
		}

		return $this->discoveryExecuteAction;
	}

	/**
	 * Utility function to get the SoapAction for the Execute method of the Organization Service
	 * @ignore
	 */
	private function getOrganizationExecuteAction() {
		/* If it's not cached, update the cache */
		if ($this->organizationExecuteAction == NULL) {
			$actions = $this->getAllOrganizationSoapActions();
			$this->organizationExecuteAction = $actions['Execute'];
		}

		return $this->organizationExecuteAction;
	}

	/**
	 * Utility function to get the SoapAction for the RetrieveMultiple method
	 * @ignore
	 */
	private function getOrganizationRetrieveMultipleAction() {
		/* If it's not cached, update the cache */
		if ($this->organizationRetrieveMultipleAction == NULL) {
			$actions = $this->getAllOrganizationSoapActions();
			$this->organizationRetrieveMultipleAction = $actions['RetrieveMultiple'];
		}

		return $this->organizationRetrieveMultipleAction;
	}

	/**
	 * Utility function to get the SoapAction for the Retrieve method
	 * @ignore
	 */
	private function getOrganizationRetrieveAction() {
		/* If it's not cached, update the cache */
		if ($this->organizationRetrieveAction == NULL) {
			$actions = $this->getAllOrganizationSoapActions();
			$this->organizationRetrieveAction = $actions['Retrieve'];
		}

		return $this->organizationRetrieveAction;
	}

	/**
	 * Utility function to get the SoapAction for the Create method
	 * @ignore
	 */
	private function getOrganizationCreateAction() {
		/* If it's not cached, update the cache */
		if ($this->organizationCreateAction == NULL) {
			$actions = $this->getAllOrganizationSoapActions();
			$this->organizationCreateAction = $actions['Create'];
		}

		return $this->organizationCreateAction;
	}

	/**
	 * Utility function to get the SoapAction for the Delete method
	 * @ignore
	 */
	private function getOrganizationDeleteAction() {
		/* If it's not cached, update the cache */
		if ($this->organizationDeleteAction == NULL) {
			$actions = $this->getAllOrganizationSoapActions();
			$this->organizationDeleteAction = $actions['Delete'];
		}

		return $this->organizationDeleteAction;
	}

	/**
	 * Utility function to get the SoapAction for the Update method
	 * @ignore
	 */
	private function getOrganizationUpdateAction() {
		/* If it's not cached, update the cache */
		if ($this->organizationUpdateAction == NULL) {
			$actions = $this->getAllOrganizationSoapActions();
			$this->organizationUpdateAction = $actions['Update'];
		}

		return $this->organizationUpdateAction;
	}

	/**
	 * Utility function to validate the security details for the selected service
	 * @return boolean indicator showing if the security details are okay
	 * @ignore
	 */
	private function checkSecurity($service) {
		if ($this->security[$service.'_authmode'] == NULL) return FALSE;
		switch ($this->security[$service.'_authmode']) {
			case 'Federation':
				return $this->checkFederationSecurity($service);
				break;
		}
		return FALSE;
	}

	/**
	 * Utility function to validate Federation security details for the selected service
	 * Checks the Authentication Mode is Federation, and verifies all the necessary data exists
	 * @return boolean indicator showing if the security details are okay
	 * @ignore
	 */
	private function checkFederationSecurity($service) {
		if ($this->security[$service.'_authmode'] != 'Federation') return FALSE;
		if ($this->security[$service.'_authuri'] == NULL) return FALSE;
		if ($this->security[$service.'_authendpoint'] == NULL) return FALSE;
		if ($this->security['username'] == NULL || $this->security['password'] == NULL) {
			return FALSE;
		}
		return TRUE;
	}

	/**
	 * Utility function to generate the XML for a Retrieve Organization request
	 * This XML can be sent as a SOAP message to the Discovery Service to determine all Organizations
	 * available on that service.
	 * @return DOMNode containing the XML for a RetrieveOrganizationRequest message
	 * @ignore
	 */
	protected static function generateRetrieveOrganizationRequest() {
		$retrieveOrganizationRequestDOM = new DOMDocument();
		$executeNode = $retrieveOrganizationRequestDOM->appendChild($retrieveOrganizationRequestDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts/Discovery', 'Execute'));
		$requestNode = $executeNode->appendChild($retrieveOrganizationRequestDOM->createElement('request'));
		$requestNode->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'i:type', 'RetrieveOrganizationsRequest');
		$requestNode->appendChild($retrieveOrganizationRequestDOM->createElement('AccessType', 'Default'));
		$requestNode->appendChild($retrieveOrganizationRequestDOM->createElement('Release', 'Current'));

		return $executeNode;
	}

	/**
	 * Get the SOAP Endpoint for the Federation Security service
	 * @ignore
	 */
	protected function getFederationSecurityURI($service) {
		/* If it's set, return the details from the Security array */
		if (isset($this->security[$service.'_authendpoint']))
			return $this->security[$service.'_authendpoint'];

		/* Fetch the WSDL for the Authentication Service as a parseable DOM Document */
		if (self::$debugMode) echo 'Getting WSDL data from: '.$this->security[$service.'_authuri'].PHP_EOL;
		$authenticationDOM = new DOMDocument();
		@$authenticationDOM->load($this->security[$service.'_authuri']);
		/* Flatten the WSDL and include all the Imports */
		$this->mergeWSDLImports($authenticationDOM);

		// Note: Find the real end-point to use for my security request - for now, we hard-code to Trust13 Username & Password using known values
		// See http://code.google.com/p/php-dynamics-crm-2011/issues/detail?id=4
		$authEndpoint = self::getTrust13UsernameAddress($authenticationDOM);
		return $authEndpoint;
	}

	/**
	 * Return the Authentication Mode used by the Discovery service
	 * @ignore
	 */
	protected function getDiscoveryAuthenticationMode() {
		/* If it's set, return the details from the Security array */
		if (isset($this->security['discovery_authmode']))
			return $this->security['discovery_authmode'];

		/* Get the Discovery DOM */
		$discoveryDOM = $this->getDiscoveryDOM();
		/* Get the Security Policy for the Organization Service from the WSDL */
		$this->discoverySecurityPolicy = self::findSecurityPolicy($discoveryDOM, 'DiscoveryService');
		/* Find the Authentication type used */
		$authType = $this->discoverySecurityPolicy->getElementsByTagName('Authentication')->item(0)->textContent;
		return $authType;
	}

	/**
	 * Return the Authentication Mode used by the Organization service
	 * @ignore
	 */
	protected function getOrganizationAuthenticationMode() {
		/* If it's set, return the details from the Security array */
		if (isset($this->security['organization_authmode']))
			return $this->security['organization_authmode'];

		/* Get the Organization DOM */
		$organizationDOM = $this->getOrganizationDOM();
		/* Get the Security Policy for the Organization Service from the WSDL */
		$this->organizationSecurityPolicy = self::findSecurityPolicy($organizationDOM, 'OrganizationService');
		/* Find the Authentication type used */
		$authType = $this->organizationSecurityPolicy->getElementsByTagName('Authentication')->item(0)->textContent;
		return $authType;
	}

	/**
	 * Return the Authentication Address used by the Discovery service
	 * @ignore
	 */
	protected function getDiscoveryAuthenticationAddress() {
		/* If it's set, return the details from the Security array */
		if (isset($this->security['discovery_authuri']))
			return $this->security['discovery_authuri'];

		/* If we don't already have a Security Policy, get it */
		if ($this->discoverySecurityPolicy == NULL) {
			/* Get the Discovery DOM */
			$discoveryDOM = $this->getDiscoveryDOM();
			/* Get the Security Policy for the Organization Service from the WSDL */
			$this->discoverySecurityPolicy = self::findSecurityPolicy($discoveryDOM, 'DiscoveryService');
		}

		/* Find the Authentication type used */
		$authAddress = self::getFederatedSecurityAddress($this->discoverySecurityPolicy);
		return $authAddress;
	}

	/**
	 * Return the Authentication Address used by the Organization service
	 * @ignore
	 */
	protected function getOrganizationAuthenticationAddress() {
		/* If it's set, return the details from the Security array */
		if (isset($this->security['organization_authuri']))
			return $this->security['organization_authuri'];

		/* If we don't already have a Security Policy, get it */
		if ($this->organizationSecurityPolicy == NULL) {
			/* Get the Organization DOM */
			$organizationDOM = $this->getOrganizationDOM();
			/* Get the Security Policy for the Organization Service from the WSDL */
			$this->organizationSecurityPolicy = self::findSecurityPolicy($organizationDOM, 'OrganizationService');
		}

		/* Find the Authentication type used */
		$authAddress = self::getFederatedSecurityAddress($this->organizationSecurityPolicy);
		return $authAddress;
	}

	/**
	 * Fetch and flatten the Discovery Service WSDL as a DOM
	 * @ignore
	 */
	protected function getDiscoveryDOM() {
		/* If it's already been fetched, use the one we have */
		if ($this->discoveryDOM != NULL) return $this->discoveryDOM;

		/* Fetch the WSDL for the Discovery Service as a parseable DOM Document */
		if (self::$debugMode) echo 'Getting WSDL data from: '.$this->discoveryURI.'?wsdl'.PHP_EOL;
		$discoveryDOM = new DOMDocument();
		@$discoveryDOM->load($this->discoveryURI.'?wsdl');
		/* Flatten the WSDL and include all the Imports */
		$this->mergeWSDLImports($discoveryDOM);

		/* Cache the DOM in the current object */
		$this->discoveryDOM = $discoveryDOM;
		return $discoveryDOM;
	}

	/**
	 * Fetch and flatten the Organization Service WSDL as a DOM
	 * @ignore
	 */
	protected function getOrganizationDOM() {
		/* If it's already been fetched, use the one we have */
		if ($this->organizationDOM != NULL) return $this->organizationDOM;
		if ($this->organizationURI == NULL) {
			throw new Exception('Cannot get Organization DOM before determining Organization URI');
		}


		/* Fetch the WSDL for the Organization Service as a parseable DOM Document */
		if (self::$debugMode) echo 'Getting WSDL data from: '.$this->organizationURI.'?wsdl'.PHP_EOL;
		$organizationDOM = new DOMDocument();
		@$organizationDOM->load($this->organizationURI.'?wsdl');
		/* Flatten the WSDL and include all the Imports */
		$this->mergeWSDLImports($organizationDOM);

		/* Cache the DOM in the current object */
		$this->organizationDOM = $organizationDOM;
		return $organizationDOM;
	}

	/**
	 * Get the Trust Address for the Trust13UsernameMixed authentication method
	 * @ignore
	 */
	protected static function getTrust13UsernameAddress(DOMDocument $authenticationDOM) {
		return self::getTrustAddress($authenticationDOM, 'UserNameWSTrustBinding_IWSTrust13Async');
	}

	/**
	 * Search the WSDL from an ADFS server to find the correct end-point for a
	 * call to RequestSecurityToken with a given set of parmameters
	 * @ignore
	 */
	protected static function getTrustAddress(DOMDocument $authenticationDOM, $trustName) {
		/* Search the available Ports on the WSDL */
		$trustAuthNode = NULL;
		foreach ($authenticationDOM->getElementsByTagName('port') as $portNode) {
			if ($portNode->hasAttribute('name') && $portNode->getAttribute('name') == $trustName) {
				$trustAuthNode = $portNode;
				break;
			}
		}
		if ($trustAuthNode == NULL) {
			throw new Exception('Could not find Port for trust type <'.$trustName.'> in provided WSDL');
			return FALSE;
		}
		/* Get the Address from the Port */
		$authenticationURI = NULL;
		if ($trustAuthNode->getElementsByTagName('address')->length > 0) {
			$authenticationURI = $trustAuthNode->getElementsByTagName('address')->item(0)->getAttribute('location');
		}
		if ($authenticationURI == NULL) {
			throw new Exception('Could not find Address for trust type <'.$trustName.'> in provided WSDL');
			return FALSE;
		}
		/* Return the found URI */
		return $authenticationURI;
	}

	/**
	 * Search a WSDL XML DOM for "import" tags and import the files into
	 * one large DOM for the entire WSDL structure
	 * @ignore
	 */
	protected function mergeWSDLImports(DOMNode &$wsdlDOM, $continued = false, DOMDocument &$newRootDocument = NULL) {
		static $rootNode = NULL;
		static $rootDocument = NULL;
		/* If this is an external call, find the "root" defintions node */
		if ($continued == false) {
			$rootNode = $wsdlDOM->getElementsByTagName('definitions')->item(0);
			$rootDocument = $wsdlDOM;
		}
		if ($newRootDocument == NULL) $newRootDocument = $rootDocument;
		//if (self::$debugMode) echo "Processing Node: ".$wsdlDOM->nodeName." which has ".$wsdlDOM->childNodes->length." child nodes".PHP_EOL;
		$nodesToRemove = Array();
		/* Loop through the Child nodes of the provided DOM */
		foreach ($wsdlDOM->childNodes as $childNode) {
			//if (self::$debugMode) echo "\tProcessing Child Node: ".$childNode->nodeName." (".$childNode->localName.") which has ".$childNode->childNodes->length." child nodes".PHP_EOL;
			/* If this child is an IMPORT node, get the referenced WSDL, and remove the Import */
			if ($childNode->localName == 'import') {
				/* Get the location of the imported WSDL */
				if ($childNode->hasAttribute('location')) {
					$importURI = $childNode->getAttribute('location');
				} else if ($childNode->hasAttribute('schemaLocation')) {
					$importURI = $childNode->getAttribute('schemaLocation');
				} else {
					$importURI = NULL;
				}
				/* Only import if we found a URI - otherwise, don't change it! */
				if ($importURI != NULL) {
					if (self::$debugMode) echo "\tImporting data from: ".$importURI.PHP_EOL;
					$importDOM = new DOMDocument();
					@$importDOM->load($importURI);
					/* Find the "Definitions" on this imported node */
					$importDefinitions = $importDOM->getElementsByTagName('definitions')->item(0);
					/* If we have "Definitions", import them one by one - Otherwise, just import at this level */
					if ($importDefinitions != NULL) {
						/* Add all the attributes (namespace definitions) to the root definitions node */
						foreach ($importDefinitions->attributes as $attribute) {
							/* Don't copy the "TargetNamespace" attribute */
							if ($attribute->name != 'targetNamespace') {
								$rootNode->setAttributeNode($attribute);
							}
						}
						$this->mergeWSDLImports($importDefinitions, true, $importDOM);
						foreach ($importDefinitions->childNodes as $importNode) {
							//if (self::$debugMode) echo "\t\tInserting Child: ".$importNode->C14N(true).PHP_EOL;
							$importNode = $newRootDocument->importNode($importNode, true);
							$wsdlDOM->insertBefore($importNode, $childNode);
						}
					} else {
						//if (self::$debugMode) echo "\t\tInserting Child: ".$importNode->C14N(true).PHP_EOL;
						$importNode = $newRootDocument->importNode($importDOM->firstChild, true);
						$wsdlDOM->insertBefore($importNode, $childNode);
					}
					//if (self::$debugMode) echo "\t\tRemoving Child: ".$childNode->C14N(true).PHP_EOL;
					$nodesToRemove[] = $childNode;
				}
			} else {
				//if (self::$debugMode) echo 'Preserving node: '.$childNode->localName.PHP_EOL;
				if ($childNode->hasChildNodes()) {
					$this->mergeWSDLImports($childNode, true);
				}
			}
		}
		/* Actually remove the nodes (not done in the loop, as it messes up the ForEach pointer!) */
		foreach ($nodesToRemove as $node) {
			$wsdlDOM->removeChild($node);
		}
		return $wsdlDOM;
	}

	/**
	 * Search a Microsoft Dynamics CRM 2011 WSDL for the Security Policy for a given Service
	 * @ignore
	 */
	protected static function findSecurityPolicy(DOMDocument $wsdlDocument, $serviceName) {
		/* Find the selected Service definition from the WSDL */
		$selectedServiceNode = NULL;
		foreach ($wsdlDocument->getElementsByTagName('service') as $serviceNode) {
			if ($serviceNode->hasAttribute('name') && $serviceNode->getAttribute('name') == $serviceName) {
				$selectedServiceNode = $serviceNode;
				break;
			}
		}
		if ($selectedServiceNode == NULL) {
			throw new Exception('Could not find definition of Service <'.$serviceName.'> in provided WSDL');
			return FALSE;
		}
		/* Now find the Binding for the Service */
		$bindingName = NULL;
		foreach ($selectedServiceNode->getElementsByTagName('port') as $portNode) {
			if ($portNode->hasAttribute('name')) {
				$bindingName = $portNode->getAttribute('name');
				break;
			}
		}
		if ($bindingName == NULL) {
			throw new Exception('Could not find binding for Service <'.$serviceName.'> in provided WSDL');
			return FALSE;
		}
		/* Find the Binding definition from the WSDL */
		$bindingNode = NULL;
		foreach ($wsdlDocument->getElementsByTagName('binding') as $bindingNode) {
			if ($bindingNode->hasAttribute('name') && $bindingNode->getAttribute('name') == $bindingName) {
				break;
			}
		}
		if ($bindingNode == NULL) {
			throw new Exception('Could not find defintion of Binding <'.$bindingName.'> in provided WSDL');
			return FALSE;
		}
		/* Find the Policy Reference */
		$policyReferenceURI = NULL;
		foreach ($bindingNode->getElementsByTagName('PolicyReference') as $policyReferenceNode) {
			if ($policyReferenceNode->hasAttribute('URI')) {
				/* Strip the leading # from the PolicyReferenceURI to get the ID */
				$policyReferenceURI = substr($policyReferenceNode->getAttribute('URI'), 1);
				break;
			}
		}
		if ($policyReferenceURI == NULL) {
			throw new Exception('Could not find Policy Reference for Binding <'.$bindingName.'> in provided WSDL');
			return FALSE;
		}
		/* Find the Security Policy from the WSDL */
		$securityPolicyNode = NULL;
		foreach ($wsdlDocument->getElementsByTagName('Policy') as $policyNode) {
			if ($policyNode->hasAttribute('wsu:Id') && $policyNode->getAttribute('wsu:Id') == $policyReferenceURI) {
				$securityPolicyNode = $policyNode;
				break;
			}
		}
		if ($securityPolicyNode == NULL) {
			throw new Exception('Could not find Policy with ID <'.$policyReferenceURI.'> in provided WSDL');
			return FALSE;
		}
		/* Return the selected node */
		return $securityPolicyNode;
	}

	/**
	 * Search a Microsoft Dynamics CRM 2011 WSDL for all available Operations/SoapActions on a Service
	 * @ignore
	 */
	private static function getAllSoapActions(DOMDocument $wsdlDocument, $serviceName) {
		/* Find the selected Service definition from the WSDL */
		$selectedServiceNode = NULL;
		foreach ($wsdlDocument->getElementsByTagName('service') as $serviceNode) {
			if ($serviceNode->hasAttribute('name') && $serviceNode->getAttribute('name') == $serviceName) {
				$selectedServiceNode = $serviceNode;
				break;
			}
		}
		if ($selectedServiceNode == NULL) {
			throw new Exception('Could not find definition of Service <'.$serviceName.'> in provided WSDL');
			return FALSE;
		}
		/* Now find the Binding for the Service */
		$bindingName = NULL;
		foreach ($selectedServiceNode->getElementsByTagName('port') as $portNode) {
			if ($portNode->hasAttribute('name')) {
				$bindingName = $portNode->getAttribute('name');
				break;
			}
		}
		if ($bindingName == NULL) {
			throw new Exception('Could not find binding for Service <'.$serviceName.'> in provided WSDL');
			return FALSE;
		}
		/* Find the Binding definition from the WSDL */
		$bindingNode = NULL;
		foreach ($wsdlDocument->getElementsByTagName('binding') as $bindingNode) {
			if ($bindingNode->hasAttribute('name') && $bindingNode->getAttribute('name') == $bindingName) {
				break;
			}
		}
		if ($bindingNode == NULL) {
			throw new Exception('Could not find defintion of Binding <'.$bindingName.'> in provided WSDL');
			return FALSE;
		}
		/* Array to store the list of Operations and SoapActions */
		$operationArray = Array();
		/* Find the Operations */
		foreach ($bindingNode->getElementsByTagName('operation') as $operationNode) {
			if ($operationNode->hasAttribute('name')) {
				/* Record the Name of this Operation */
				$operationName = $operationNode->getAttribute('name');
				/* Find the Operation SoapAction from the WSDL */
				foreach ($operationNode->getElementsByTagName('operation') as $soap12OperationNode) {
					if ($soap12OperationNode->hasAttribute('soapAction')) {
						/* Record the SoapAction for this Operation */
						$soapAction = $soap12OperationNode->getAttribute('soapAction');
						/* Store the soapAction in the Array */
						$operationArray[$operationName] = $soapAction;
					}
				}
				unset($soap12OperationNode);
			}
		}

		/* Return the array of available actions */
		return $operationArray;
	}

	/**
	 * Get all the Operations & corresponding SoapActions for the DiscoveryService
	 */
	public function getAllDiscoverySoapActions() {
		/* If it is not cached, update the cache */
		if ($this->discoverySoapActions == NULL) {
			$this->discoverySoapActions = self::getAllSoapActions($this->getDiscoveryDOM(), 'DiscoveryService');
		}
		/* Return the cached value */
		return $this->discoverySoapActions;
	}

	/**
	 * Get all the Operations & corresponding SoapActions for the OrganizationService
	 */
	public function getAllOrganizationSoapActions() {
		/* If it is not cached, update the cache */
		if ($this->organizationSoapActions == NULL) {
			$this->organizationSoapActions = self::getAllSoapActions($this->getOrganizationDOM(), 'OrganizationService');
		}
		/* Return the cached value */
		return $this->organizationSoapActions;
	}

	/**
	 * Search a Microsoft Dynamics CRM 2011 WSDL for the SoapAction for a given Operation on a Service
	 * @ignore
	 * @deprecated No longer required, as we now use getAllSoapActions instead
	 */
	protected static function findSoapAction(DOMDocument $wsdlDocument, $serviceName, $operationName) {
		/* Find the selected Service definition from the WSDL */
		$selectedServiceNode = NULL;
		foreach ($wsdlDocument->getElementsByTagName('service') as $serviceNode) {
			if ($serviceNode->hasAttribute('name') && $serviceNode->getAttribute('name') == $serviceName) {
				$selectedServiceNode = $serviceNode;
				break;
			}
		}
		if ($selectedServiceNode == NULL) {
			throw new Exception('Could not find definition of Service <'.$serviceName.'> in provided WSDL');
			return FALSE;
		}
		/* Now find the Binding for the Service */
		$bindingName = NULL;
		foreach ($selectedServiceNode->getElementsByTagName('port') as $portNode) {
			if ($portNode->hasAttribute('name')) {
				$bindingName = $portNode->getAttribute('name');
				break;
			}
		}
		if ($bindingName == NULL) {
			throw new Exception('Could not find binding for Service <'.$serviceName.'> in provided WSDL');
			return FALSE;
		}
		/* Find the Binding definition from the WSDL */
		$bindingNode = NULL;
		foreach ($wsdlDocument->getElementsByTagName('binding') as $bindingNode) {
			if ($bindingNode->hasAttribute('name') && $bindingNode->getAttribute('name') == $bindingName) {
				break;
			}
		}
		if ($bindingNode == NULL) {
			throw new Exception('Could not find defintion of Binding <'.$bindingName.'> in provided WSDL');
			return FALSE;
		}
		/* Find the Operation Definition */
		$serviceOperationNode = NULL;
		foreach ($bindingNode->getElementsByTagName('operation') as $operationNode) {
			if ($operationNode->hasAttribute('name') && $operationNode->getAttribute('name') == $operationName) {
				$serviceOperationNode = $operationNode;
				break;
			}
		}
		if ($serviceOperationNode == NULL) {
			throw new Exception('Could not find Operation <'.$operationName.'> for Binding <'.$bindingName.'> in provided WSDL');
			return FALSE;
		}
		/* Find the Operation SoapAction from the WSDL */
		$soapAction = NULL;
		foreach ($serviceOperationNode->getElementsByTagName('operation') as $soap12OperationNode) {
			if ($soap12OperationNode->hasAttribute('soapAction')) {
				$soapAction = $soap12OperationNode->getAttribute('soapAction');
				break;
			}
		}
		if ($soapAction == NULL) {
			throw new Exception('Could not find SoapAction for Operation <'.$operationName.'> on Service <'.$serviceName.'> in provided WSDL');
			return FALSE;
		}
		/* Return the selected node */
		return $soapAction;
	}

	/**
	 * Search a Microsoft Dynamics CRM 2011 Security Policy for the Address for the Federated Security
	 * @ignore
	 */
	protected static function getFederatedSecurityAddress(DOMNode $securityPolicyNode) {
		$securityURL = NULL;
		/* Find the EndorsingSupportingTokens tag */
		if ($securityPolicyNode->getElementsByTagName('EndorsingSupportingTokens')->length == 0) {
			throw new Exception('Could not find EndorsingSupportingTokens tag in provided security policy XML');
			return FALSE;
		}
		$estNode = $securityPolicyNode->getElementsByTagName('EndorsingSupportingTokens')->item(0);
		/* Find the Policy tag */
		if ($estNode->getElementsByTagName('Policy')->length == 0) {
			throw new Exception('Could not find EndorsingSupportingTokens/Policy tag in provided security policy XML');
			return FALSE;
		}
		$estPolicyNode = $estNode->getElementsByTagName('Policy')->item(0);
		/* Find the IssuedToken tag */
		if ($estPolicyNode->getElementsByTagName('IssuedToken')->length == 0) {
			throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken tag in provided security policy XML');
			return FALSE;
		}
		$issuedTokenNode = $estPolicyNode->getElementsByTagName('IssuedToken')->item(0);
		/* Find the Issuer tag */
		if ($issuedTokenNode->getElementsByTagName('Issuer')->length == 0) {
			throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken/Issuer tag in provided security policy XML');
			return FALSE;
		}
		$issuerNode = $issuedTokenNode->getElementsByTagName('Issuer')->item(0);
		/* Find the Metadata tag */
		if ($issuerNode->getElementsByTagName('Metadata')->length == 0) {
			throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken/Issuer/Metadata tag in provided security policy XML');
			return FALSE;
		}
		$metadataNode = $issuerNode->getElementsByTagName('Metadata')->item(0);
		/* Find the Address tag */
		if ($metadataNode->getElementsByTagName('Address')->length == 0) {
			throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken/Issuer/Metadata/.../Address tag in provided security policy XML');
			return FALSE;
		}
		$addressNode = $metadataNode->getElementsByTagName('Address')->item(0);
		/* Get the URI */
		$securityURL = $addressNode->textContent;
		if ($securityURL == NULL) {
			throw new Exception('Could not find Security URL in provided security policy WSDL');
			return FALSE;
		}
		return $securityURL;
	}

	/**
	 * Request a Security Token from the ADFS server using Username & Password authentication
	 * @ignore
	 */
	protected function requestSecurityToken($securityServerURI, $loginEndpoint, $loginUsername, $loginPassword) {
		/* Generate the Security Token Request XML */
		$loginSoapRequest = self::getLoginXML($securityServerURI, $loginEndpoint, $loginUsername, $loginPassword);
		/* Send the Security Token request */
		$security_xml = self::getSoapResponse($securityServerURI, $loginSoapRequest);
		/* Convert the XML into a DOMDocument */
		$securityDOM = new DOMDocument();
		$securityDOM->loadXML($security_xml);
		/* Get the two CipherValue keys */
		$cipherValues = $securityDOM->getElementsbyTagName("CipherValue");
		$securityToken0 =  $cipherValues->item(0)->textContent;
		$securityToken1 =  $cipherValues->item(1)->textContent;
		/* Get the KeyIdentifier */
		$keyIdentifier = $securityDOM->getElementsbyTagName("KeyIdentifier")->item(0)->textContent;
		/* Get the BinarySecret */
		$binarySecret = $securityDOM->getElementsbyTagName("BinarySecret")->item(0)->textContent;
		/* Make life easier - get the entire RequestedSecurityToken section */
		$requestedSecurityToken = $securityDOM->saveXML($securityDOM->getElementsByTagName("RequestedSecurityToken")->item(0));
		preg_match('/<trust:RequestedSecurityToken>(.*)<\/trust:RequestedSecurityToken>/', $requestedSecurityToken, $matches);
		$requestedSecurityToken = $matches[1];
		/* Find the Expiry Time */
		$expiryTime = $securityDOM->getElementsByTagName("RequestSecurityTokenResponse")->item(0)->getElementsByTagName('Expires')->item(0)->textContent;
		/* Convert it to a PHP Timestamp */
		$expiryTime = self::parseTime(substr($expiryTime, 0, -5), '%Y-%m-%dT%H:%M:%S');

		/* Return an associative Array */
		$securityToken = Array(
				'securityToken' => $requestedSecurityToken,
				'securityToken0' => $securityToken0,
				'securityToken1' => $securityToken1,
				'binarySecret' => $binarySecret,
				'keyIdentifier' => $keyIdentifier,
				'expiryTime' => $expiryTime
			);
		/* DEBUG logging */
		if (self::$debugMode) {
			echo 'Got Security Token - Expires at: '.date('r', $securityToken['expiryTime']).PHP_EOL;
			echo "\tKey Identifier\t: ".$securityToken['keyIdentifier'].PHP_EOL;
			echo "\tSecurity Token 0\t: ".substr($securityToken['securityToken0'], 0, 25).'...'.substr($securityToken['securityToken0'], -25).' ('.strlen($securityToken['securityToken0']).')'.PHP_EOL;
			echo "\tSecurity Token 1\t: ".substr($securityToken['securityToken1'], 0, 25).'...'.substr($securityToken['securityToken1'], -25).' ('.strlen($securityToken['securityToken1']).')'.PHP_EOL;
			echo "\tBinary Secret\t: ".$securityToken['binarySecret'].PHP_EOL.PHP_EOL;
		}
		/* Return an associative Array */
		return $securityToken;
	}

	/**
	 * Get the XML needed to send a login request to the Username & Password Trust service
	 * @ignore
	 */
	protected static function getLoginXML($securityServerURI, $loginEndpoint, $loginUsername, $loginPassword) {
		$loginSoapRequest = new DOMDocument();
		$loginEnvelope = $loginSoapRequest->appendChild($loginSoapRequest->createElementNS('http://www.w3.org/2003/05/soap-envelope', 's:Envelope'));
		$loginEnvelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:a', 'http://www.w3.org/2005/08/addressing');
		$loginEnvelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:u', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd');
		$loginHeader = $loginEnvelope->appendChild($loginSoapRequest->createElement('s:Header'));
		$loginHeader->appendChild($loginSoapRequest->createElement('a:Action', 'http://docs.oasis-open.org/ws-sx/ws-trust/200512/RST/Issue'))->setAttribute('s:mustUnderstand', "1");
		$loginHeader->appendChild($loginSoapRequest->createElement('a:ReplyTo'))->appendChild($loginSoapRequest->createElement('a:Address', 'http://www.w3.org/2005/08/addressing/anonymous'));
		$loginHeader->appendChild($loginSoapRequest->createElement('a:To', $securityServerURI))->setAttribute('s:mustUnderstand', "1");
		$loginSecurity = $loginHeader->appendChild($loginSoapRequest->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'o:Security'));
		$loginSecurity->setAttribute('s:mustUnderstand', "1");
		$loginTimestamp = $loginSecurity->appendChild($loginSoapRequest->createElement('u:Timestamp'));
		$loginTimestamp->setAttribute('u:Id', '_0');
		$loginTimestamp->appendChild($loginSoapRequest->createElement('u:Created', self::getCurrentTime().'Z'));
		$loginTimestamp->appendChild($loginSoapRequest->createElement('u:Expires', self::getExpiryTime().'Z'));
		$loginUsernameToken = $loginSecurity->appendChild($loginSoapRequest->createElement('o:UsernameToken'));
		$loginUsernameToken->setAttribute('u:Id', 'user');
		$pass = $loginSoapRequest->createTextNode($loginPassword); // Force escaping of the password
		$pass_str = $loginSoapRequest->saveXML($pass);
		$loginUsernameToken->appendChild($loginSoapRequest->createElement('o:Username', $loginUsername));
		$loginUsernameToken->appendChild($loginSoapRequest->createElement('o:Password', $pass_str))->setAttribute('Type', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText');

		$loginBody = $loginEnvelope->appendChild($loginSoapRequest->createElementNS('http://www.w3.org/2003/05/soap-envelope', 's:Body'));
		$loginRST = $loginBody->appendChild($loginSoapRequest->createElementNS('http://docs.oasis-open.org/ws-sx/ws-trust/200512', 'trust:RequestSecurityToken'));
		$loginAppliesTo = $loginRST->appendChild($loginSoapRequest->createElementNS('http://schemas.xmlsoap.org/ws/2004/09/policy', 'wsp:AppliesTo'));
		$loginEndpointReference = $loginAppliesTo->appendChild($loginSoapRequest->createElement('a:EndpointReference'));
		$loginEndpointReference->appendChild($loginSoapRequest->createElement('a:Address', $loginEndpoint));
		$loginRST->appendChild($loginSoapRequest->createElement('trust:RequestType', 'http://docs.oasis-open.org/ws-sx/ws-trust/200512/Issue'));

		return $loginSoapRequest->saveXML($loginEnvelope);
	}

	/**
	 * Send the SOAP message, and get the response
	 * @ignore
	 */
	protected static function getSoapResponse($soapUrl, $content) {
		/* Separate the provided URI into Path & Hostname sections */
		$urlDetails = parse_url($soapUrl);

		// setup headers
		$headers = array(
				"POST ". $urlDetails['path'] ." HTTP/1.1",
				"Host: " . $urlDetails['host'],
				'Connection: Keep-Alive',
				"Content-type: application/soap+xml; charset=UTF-8",
				"Content-length: ".strlen($content),
		);

		$cURLHandle = curl_init();
		curl_setopt($cURLHandle, CURLOPT_URL, $soapUrl);
		curl_setopt($cURLHandle, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($cURLHandle, CURLOPT_TIMEOUT, self::$connectorTimeout);
		curl_setopt($cURLHandle, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($cURLHandle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($cURLHandle, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($cURLHandle, CURLOPT_POST, 1);
		curl_setopt($cURLHandle, CURLOPT_POSTFIELDS, $content);
		curl_setopt($cURLHandle, CURLOPT_HEADER, false);
		/* Execute the cURL request, get the XML response */
		$responseXML = curl_exec($cURLHandle);
		/* Check for cURL errors */
		if (curl_errno($cURLHandle) != CURLE_OK) {
			throw new Exception('cURL Error: '.curl_error($cURLHandle));
		}
		/* Check for HTTP errors */
		$httpResponse = curl_getinfo($cURLHandle, CURLINFO_HTTP_CODE);
		curl_close($cURLHandle);

		if (self::$debugMode) echo PHP_EOL.PHP_EOL.'SOAP Response:= '.PHP_EOL.$responseXML.PHP_EOL.PHP_EOL;

		/* Determine the Action in the SOAP Response */
		$responseDOM = new DOMDocument();
		$responseDOM->loadXML($responseXML);
		/* Check we have a SOAP Envelope */
		if ($responseDOM->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Envelope')->length < 1) {
			throw new Exception('Invalid SOAP Response: HTTP Response '.$httpResponse.PHP_EOL.$responseXML.PHP_EOL);
		}
		/* Check we have a SOAP Header */
		if ($responseDOM->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Envelope')->item(0)
				->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Header')->length < 1) {
			throw new Exception('Invalid SOAP Response: No SOAP Header! '.PHP_EOL.$responseXML.PHP_EOL);
		}
		/* Get the SOAP Action */
		$actionString = $responseDOM->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Envelope')->item(0)
				->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Header')->item(0)
				->getElementsByTagNameNS('http://www.w3.org/2005/08/addressing', 'Action')->item(0)->textContent;
		if (self::$debugMode) echo __FUNCTION__.': SOAP Action in returned XML is "'.$actionString.'"'.PHP_EOL;

		/* Handle known Error Actions */
		if (in_array($actionString, self::$SOAPFaultActions)) {
			// Get the Fault Code
			$faultCode = $responseDOM->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Envelope')->item(0)
				->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Body')->item(0)
				->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Fault')->item(0)
				->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Code')->item(0)
				->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Value')->item(0)->nodeValue;
			/* Strip any Namespace References from the fault code */
			$faultCode = self::stripNS($faultCode);
			// Get the Fault String
			$faultString = $responseDOM->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Envelope')->item(0)
				->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Body')->item(0)
				->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Fault')->item(0)
				->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Reason')->item(0)
				->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Text')->item(0)->nodeValue.PHP_EOL;
			throw new SoapFault($faultCode, $faultString);
		}

		return $responseXML;
	}

	/**
	 * Create the XML String for a Soap Request
	 * @ignore
	 */
	protected static function generateSoapRequest($serviceURI, $soapAction, $securityToken, DOMNode $bodyContentNode, $_callerId = NULL) {
		$soapRequestDOM = new DOMDocument();
		$soapEnvelope = $soapRequestDOM->appendChild($soapRequestDOM->createElementNS('http://www.w3.org/2003/05/soap-envelope', 's:Envelope'));
		$soapEnvelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:a', 'http://www.w3.org/2005/08/addressing');
		$soapEnvelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:u', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd');
		/* Get the SOAP Header */
		$soapHeaderNode = self::generateSoapHeader($serviceURI, $soapAction, $securityToken, $_callerId);
		$soapEnvelope->appendChild($soapRequestDOM->importNode($soapHeaderNode, true));
		/* Create the SOAP Body */
		$soapBodyNode = $soapEnvelope->appendChild($soapRequestDOM->createElement('s:Body'));
		$soapBodyNode->appendChild($soapRequestDOM->importNode($bodyContentNode, true));

		return $soapRequestDOM->saveXML($soapEnvelope);
	}

	/**
	 * Generate a Soap Header using the specified service URI and SoapAction
	 * Include the details from the Security Token for login
	 * @ignore
	 */
	protected static function generateSoapHeader($serviceURI, $soapAction, $securityToken, $_callerId = NULL) {
		$soapHeaderDOM = new DOMDocument();
		$headerNode = $soapHeaderDOM->appendChild($soapHeaderDOM->createElement('s:Header'));
		$headerNode->appendChild($soapHeaderDOM->createElement('a:Action', $soapAction))->setAttribute('s:mustUnderstand', '1');
		/* Handle Impersonation */
		if ($_callerId != NULL) {
			$callerNode = $headerNode->appendChild($soapHeaderDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts', 'CallerId', $_callerId->ID));
		}
		$headerNode->appendChild($soapHeaderDOM->createElement('a:ReplyTo'))->appendChild($soapHeaderDOM->createElement('a:Address', 'http://www.w3.org/2005/08/addressing/anonymous'));
		$headerNode->appendChild($soapHeaderDOM->createElement('a:To', $serviceURI))->setAttribute('s:mustUnderstand', '1');
		$securityHeaderNode = self::getSecurityHeaderNode($securityToken);
		$headerNode->appendChild($soapHeaderDOM->importNode($securityHeaderNode, true));

		return $headerNode;
	}

	/**
	 * Generate a DOMNode for the o:Security header required for SOAP requests
	 * @ignore
	 */
	protected static function getSecurityHeaderNode(Array $securityToken) {
		$securityDOM = new DOMDocument();

		$securityHeader = $securityDOM->appendChild($securityDOM->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'o:Security'));
		$securityHeader->setAttribute('s:mustUnderstand', '1');
		$headerTimestamp = $securityHeader->appendChild($securityDOM->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd', 'u:Timestamp'));
		$headerTimestamp->setAttribute('u:Id', '_0');
		$headerTimestamp->appendChild($securityDOM->createElement('u:Created', self::getCurrentTime().'Z'));
		$headerTimestamp->appendChild($securityDOM->createElement('u:Expires', self::getExpiryTime().'Z'));

		$requestedSecurityToken = $securityDOM->createDocumentFragment();
		$requestedSecurityToken->appendXML($securityToken['securityToken']);
		$securityHeader->appendChild($requestedSecurityToken);

		$signatureNode = $securityHeader->appendChild($securityDOM->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'Signature'));
		$signedInfoNode = $signatureNode->appendChild($securityDOM->createElement('SignedInfo'));
		$signedInfoNode->appendChild($securityDOM->createElement('CanonicalizationMethod'))->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
		$signedInfoNode->appendChild($securityDOM->createElement('SignatureMethod'))->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#hmac-sha1');
		$referenceNode = $signedInfoNode->appendChild($securityDOM->createElement('Reference'));
		$referenceNode->setAttribute('URI', '#_0');
		$referenceNode->appendChild($securityDOM->createElement('Transforms'))->appendChild($securityDOM->createElement('Transform'))->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
		$referenceNode->appendChild($securityDOM->createElement('DigestMethod'))->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
		$referenceNode->appendChild($securityDOM->createElement('DigestValue', base64_encode(sha1($headerTimestamp->C14N(true), true))));
		$signatureNode->appendChild($securityDOM->createElement('SignatureValue', base64_encode(hash_hmac('sha1', $signedInfoNode->C14N(true), base64_decode($securityToken['binarySecret']), true))));
		$keyInfoNode = $signatureNode->appendChild($securityDOM->createElement('KeyInfo'));
		$securityTokenReferenceNode = $keyInfoNode->appendChild($securityDOM->createElement('o:SecurityTokenReference'));
		$securityTokenReferenceNode->setAttributeNS('http://docs.oasis-open.org/wss/oasis-wss-wssecurity-secext-1.1.xsd', 'k:TokenType', 'http://docs.oasis-open.org/wss/oasis-wss-saml-token-profile-1.1#SAMLV1.1');
		$securityTokenReferenceNode->appendChild($securityDOM->createElement('o:KeyIdentifier', $securityToken['keyIdentifier']))->setAttribute('ValueType', 'http://docs.oasis-open.org/wss/oasis-wss-saml-token-profile-1.0#SAMLAssertionID');

		return $securityHeader;
	}

	/**
	 * Generate a Retrieve Multiple Request
	 * @ignore
	 */
	protected static function generateRetrieveMultipleRequest($queryXML, $pagingCookie = NULL, $limitCount = NULL) {
		if ($pagingCookie != NULL) {
			/* Turn the queryXML into a DOMDocument so we can manipulate it */
			$queryDOM = new DOMDocument(); $queryDOM->loadXML($queryXML);
			$newPage = self::getPageNo($pagingCookie) + 1;
			//echo 'Doing paging - Asking for page: '.$newPage.PHP_EOL;
			/* Modify the query that we send: Add the Page number */
			$queryDOM->documentElement->setAttribute('page', $newPage);
			/* Modify the query that we send: Add the Paging-Cookie (note - HTMLENTITIES automatically applied by DOMDocument!) */
			$queryDOM->documentElement->setAttribute('paging-cookie', $pagingCookie);
			/* Update the Query XML with the new structure */
			$queryXML = $queryDOM->saveXML($queryDOM->documentElement);
			//echo PHP_EOL.PHP_EOL.$queryXML.PHP_EOL.PHP_EOL;
		}
		/* Turn the queryXML into a DOMDocument so we can manipulate it */
		$queryDOM = new DOMDocument(); $queryDOM->loadXML($queryXML);
		/* Find the current limit, if there is one */
		$currentLimit = self::$maximumRecords+1;
		if ($queryDOM->documentElement->hasAttribute('count')) {
			$currentLimit = $queryDOM->documentElement->getAttribute('count');
		}
		/* Determine the preferred limit (passed by argument, or 5000 if not set) */
		$preferredLimit = ($limitCount == NULL) ? self::$maximumRecords : $limitCount;
		if ($preferredLimit > self::$maximumRecords) $preferredLimit = self::$maximumRecords;
		/* If the current limit is not set, or is greater than the preferred limit, over-ride it */
		if ($currentLimit > $preferredLimit) {
			/* Modify the query that we send: Change the Count */
			$queryDOM->documentElement->setAttribute('count', $preferredLimit);
			/* Update the Query XML with the new structure */
			$queryXML = $queryDOM->saveXML($queryDOM->documentElement);
			//echo PHP_EOL.PHP_EOL.$queryXML.PHP_EOL.PHP_EOL;
		}
		/* Generate the RetrieveMultipleRequest message */
		$retrieveMultipleRequestDOM = new DOMDocument();
		$retrieveMultipleNode = $retrieveMultipleRequestDOM->appendChild($retrieveMultipleRequestDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts/Services', 'RetrieveMultiple'));
		$queryNode = $retrieveMultipleNode->appendChild($retrieveMultipleRequestDOM->createElement('query'));
		$queryNode->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'i:type', 'b:FetchExpression');
		$queryNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:b', 'http://schemas.microsoft.com/xrm/2011/Contracts');
		$queryNode->appendChild($retrieveMultipleRequestDOM->createElement('b:Query', htmlentities($queryXML)));
		/* Return the DOMNode */
		return $retrieveMultipleNode;
	}

	/**
	 * Find the PageNumber in a PagingCookie
	 *
	 * @param String $pagingCookie
	 * @ignore
	 */
	private static function getPageNo($pagingCookie) {
		/* Turn the pagingCookie into a DOMDocument so we can read it */
		$pagingDOM = new DOMDocument(); $pagingDOM->loadXML($pagingCookie);
		/* Find the page number */
		$pageNo = $pagingDOM->documentElement->getAttribute('page');
		return (int)$pageNo;
	}

	/**
	 * Generate a Retrieve Request
	 * @ignore
	 */
	protected static function generateRetrieveRequest($entityType, $entityId, $columnSet) {
		/* Generate the RetrieveRequest message */
		$retrieveRequestDOM = new DOMDocument();
		$retrieveNode = $retrieveRequestDOM->appendChild($retrieveRequestDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts/Services', 'Retrieve'));
		$retrieveNode->appendChild($retrieveRequestDOM->createElement('entityName', $entityType));
		$retrieveNode->appendChild($retrieveRequestDOM->createElement('id', $entityId));
		$columnSetNode = $retrieveNode->appendChild($retrieveRequestDOM->createElement('columnSet'));
		$columnSetNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:b', 'http://schemas.microsoft.com/xrm/2011/Contracts');
		$columnSetNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:i', 'http://www.w3.org/2001/XMLSchema-instance');
		/* Add the columns requested, if specified */
		if ($columnSet != NULL && count($columnSet) > 0) {
			$columnSetNode->appendChild($retrieveRequestDOM->createElement('b:AllColumns', 'false'));
			$columnsNode = $columnSetNode->appendChild($retrieveRequestDOM->createElement('b:Columns'));
			$columnsNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.microsoft.com/2003/10/Serialization/Arrays');
			foreach ($columnSet as $columnName) {
				$columnsNode->appendChild($retrieveRequestDOM->createElement('c:string', strtolower($columnName)));
			}
		} else {
			/* No columns specified, request all of them */
			$columnSetNode->appendChild($retrieveRequestDOM->createElement('b:AllColumns', 'true'));
		}
		/* Return the DOMNode */
		return $retrieveNode;
	}

	/**
	 * Generate a Retrieve Record Change History Request
	 * @ignore
	 */
	protected static function generateRetrieveRecordChangeHistoryRequest($entityType, $entityId, $pagingCookie = NULL, $limitCount = NULL) {
		/* Generate the RetrieveRecordChangeHistoryRequest message */
		$retrieveRecordChangeHistoryRequestDOM = new DOMDocument();
		$executeNode = $retrieveRecordChangeHistoryRequestDOM->appendChild($retrieveRecordChangeHistoryRequestDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts/Services', 'Execute'));
		$requestNode = $executeNode->appendChild($retrieveRecordChangeHistoryRequestDOM->createElement('request'));
		$requestNode->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'i:type', 'c:RetrieveRecordChangeHistoryRequest');
		$requestNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:b', 'http://schemas.microsoft.com/xrm/2011/Contracts');
		$requestNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.microsoft.com/crm/2011/Contracts');
		$parametersNode = $requestNode->appendChild($retrieveRecordChangeHistoryRequestDOM->createElement('b:Parameters'));
		$parametersNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:d', 'http://schemas.datacontract.org/2004/07/System.Collections.Generic');
		$keyValuePairNode = $parametersNode->appendChild($retrieveRecordChangeHistoryRequestDOM->createElement('b:KeyValuePairOfstringanyType'));
		$keyValuePairNode->appendChild($retrieveRecordChangeHistoryRequestDOM->createElement('d:key', 'Target'));
		$valueNode = $keyValuePairNode->appendChild($retrieveRecordChangeHistoryRequestDOM->createElement('d:value'));
		$valueNode->setAttribute('i:type', 'b:EntityReference');
		$valueNode->appendChild($retrieveRecordChangeHistoryRequestDOM->createElement('b:Id', $entityId));
		$valueNode->appendChild($retrieveRecordChangeHistoryRequestDOM->createElement('b:LogicalName', $entityType));
		$valueNode->appendChild($retrieveRecordChangeHistoryRequestDOM->createElement('b:Name'))->setAttribute('i:nil', 'true');
		$requestNode->appendChild($retrieveRecordChangeHistoryRequestDOM->createElement('b:RequestId'))->setAttribute('i:nil', 'true');
		$requestNode->appendChild($retrieveRecordChangeHistoryRequestDOM->createElement('b:RequestName', 'RetrieveRecordChangeHistory'));
		/* Return the DOMNode */
		return $executeNode;
	}

	/**
	 * Generate a Retrieve Entity Request
	 * @ignore
	 */
	protected static function generateRetrieveEntityRequest($entityType, $entityId = NULL, $entityFilters = NULL, $showUnpublished = false) {
		/* We can use either the entityType (Logical Name), or the entityId, but not both. */
		/* Use ID by preference, if not set, default to 0s */
		if ($entityId != NULL) $entityType = NULL;
		else $entityId = self::EmptyGUID;

		/* If no entityFilters are supplied, assume "All" */
		if ($entityFilters == NULL) $entityFilters = 'Entity Attributes Privileges Relationships';

		/* Generate the RetrieveEntityRequest message */
		$retrieveEntityRequestDOM = new DOMDocument();
		$executeNode = $retrieveEntityRequestDOM->appendChild($retrieveEntityRequestDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts/Services', 'Execute'));
		$requestNode = $executeNode->appendChild($retrieveEntityRequestDOM->createElement('request'));
		$requestNode->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'i:type', 'b:RetrieveEntityRequest');
		$requestNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:b', 'http://schemas.microsoft.com/xrm/2011/Contracts');
		$parametersNode = $requestNode->appendChild($retrieveEntityRequestDOM->createElement('b:Parameters'));
		$parametersNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.datacontract.org/2004/07/System.Collections.Generic');
		/* EntityFilters */
		$keyValuePairNode1 = $parametersNode->appendChild($retrieveEntityRequestDOM->createElement('b:KeyValuePairOfstringanyType'));
		$keyValuePairNode1->appendChild($retrieveEntityRequestDOM->createElement('c:key', 'EntityFilters'));
		$valueNode1 = $keyValuePairNode1->appendChild($retrieveEntityRequestDOM->createElement('c:value', $entityFilters));
		$valueNode1->setAttribute('i:type', 'd:EntityFilters');
		$valueNode1->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:d', 'http://schemas.microsoft.com/xrm/2011/Metadata');
		/* MetadataId */
		$keyValuePairNode2 = $parametersNode->appendChild($retrieveEntityRequestDOM->createElement('b:KeyValuePairOfstringanyType'));
		$keyValuePairNode2->appendChild($retrieveEntityRequestDOM->createElement('c:key', 'MetadataId'));
		$valueNode2 = $keyValuePairNode2->appendChild($retrieveEntityRequestDOM->createElement('c:value', $entityId));
		$valueNode2->setAttribute('i:type', 'd:guid');
		$valueNode2->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:d', 'http://schemas.microsoft.com/2003/10/Serialization/');
		/* RetrieveAsIfPublished */
		$keyValuePairNode3 = $parametersNode->appendChild($retrieveEntityRequestDOM->createElement('b:KeyValuePairOfstringanyType'));
		$keyValuePairNode3->appendChild($retrieveEntityRequestDOM->createElement('c:key', 'RetrieveAsIfPublished'));
		$valueNode3 = $keyValuePairNode3->appendChild($retrieveEntityRequestDOM->createElement('c:value', ($showUnpublished?'true':'false')));
		$valueNode3->setAttribute('i:type', 'd:boolean');
		$valueNode3->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:d', 'http://www.w3.org/2001/XMLSchema');
		/* LogicalName */
		$keyValuePairNode4 = $parametersNode->appendChild($retrieveEntityRequestDOM->createElement('b:KeyValuePairOfstringanyType'));
		$keyValuePairNode4->appendChild($retrieveEntityRequestDOM->createElement('c:key', 'LogicalName'));
		$valueNode4 = $keyValuePairNode4->appendChild($retrieveEntityRequestDOM->createElement('c:value', $entityType));
		$valueNode4->setAttribute('i:type', 'd:string');
		$valueNode4->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:d', 'http://www.w3.org/2001/XMLSchema');
		/* Request ID and Name */
		$requestNode->appendChild($retrieveEntityRequestDOM->createElement('b:RequestId'))->setAttribute('i:nil', 'true');
		$requestNode->appendChild($retrieveEntityRequestDOM->createElement('b:RequestName', 'RetrieveEntity'));
		/* Return the DOMNode */
		return $executeNode;
	}

	/**
	 * Add a list of Attributes to an Array of Attributes, using appropriate handling
	 * of the Attribute type, and avoiding over-writing existing attributes
	 * already in the array
	 *
	 * Optionally specify an Array of sub-keys, and a particular sub-key
	 * - If provided, each sub-key in the Array will be created as an Object attribute,
	 *   and the value will be set on the specified sub-key only (e.g. (New, Old) / New)
	 *
	 * @ignore
	 */
	protected static function addAttributes(Array &$targetArray, DOMNodeList $keyValueNodes, Array $keys = NULL, $key1 = NULL) {
		foreach ($keyValueNodes as $keyValueNode) {
			/* Get the Attribute name (key) */
			$attributeKey = $keyValueNode->getElementsByTagName('key')->item(0)->textContent;
			/* Check the Value Type */
			$attributeValueType = $keyValueNode->getElementsByTagName('value')->item(0)->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type');
			/* Strip any Namespace References from the Type */
			$attributeValueType = self::stripNS($attributeValueType);
			switch ($attributeValueType) {
				case 'AliasedValue':
					/* For an AliasedValue, the Key is Alias.Field, so just get the Alias */
					list($attributeKey, ) = explode('.', $attributeKey, 2);
					/* Entity Logical Name => the Object Type */
					$entityLogicalName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('EntityLogicalName')->item(0)->textContent;
					/* Attribute Logical Name => the actual Attribute of the Aliased Object */
					$attributeLogicalName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('AttributeLogicalName')->item(0)->textContent;
					$entityAttributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Value')->item(0)->textContent;
					/* See if this Alias is already in the Array */
					if (array_key_exists($attributeKey, $targetArray)) {
						/* It already exists, so grab the existing Object and set the new Attribute */
						$attributeValue = $targetArray[$attributeKey];
						$attributeValue->$attributeLogicalName = $entityAttributeValue;
						/* Pull it from the array, so we don't set a duplicate */
						unset($targetArray[$attributeKey]);
					} else {
						/* Create a new Object with the Logical Name, and this Attribute */
						$attributeValue = (Object)Array('LogicalName' => $entityLogicalName, $attributeLogicalName => $entityAttributeValue);
					}
					break;
				case 'EntityReference':
					$attributeLogicalName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('LogicalName')->item(0)->textContent;
					$attributeId = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Id')->item(0)->textContent;
					$attributeName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Name')->item(0)->textContent;
					$attributeValue = (Object)Array('LogicalName' => $attributeLogicalName,
								'Id' => $attributeId,
								'Name' => $attributeName);
					break;
				case 'OptionSetValue':
					$attributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Value')->item(0)->textContent;
					break;
				case 'dateTime':
					$attributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->textContent;
					$attributeValue = self::parseTime($attributeValue, '%Y-%m-%dT%H:%M:%SZ');
					break;
				default:
					$attributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->textContent;
			}
			/* If we are working normally, just store the data in the array */
			if ($keys == NULL) {
				/* Assume that if there is a duplicate, it's a formatted version of this */
				if (array_key_exists($attributeKey, $targetArray)) {
					$responseDataArray[$attributeKey] = (Object)Array('Value' => $attributeValue,
							'FormattedValue' => $targetArray[$attributeKey]);
				} else {
					$targetArray[$attributeKey] = $attributeValue;
				}
			} else {
				/* Store the data in the array for this AuditRecord's properties */
				if (array_key_exists($attributeKey, $targetArray)) {
					/* We assume it's already a "good" Object, and just set this key */
					if (isset($targetArray[$attributeKey]->$key1)) {
						/* It's already set, so add the Un-formatted version */
						$targetArray[$attributeKey]->$key1 = (Object)Array(
								'Value' => $attributeValue,
								'FormattedValue' => $targetArray[$attributeKey]->$key1);
					} else {
						/* It's not already set, so just set this as a value */
						$targetArray[$attributeKey]->$key1 = $attributeValue;
					}
				} else {
					/* We need to create the Object */
					$obj = (Object)Array();
					foreach ($keys as $k) { $obj->$k = NULL; }
					/* And set the particular property */
					$obj->$key1 = $attributeValue;
					/* And store the Object in the target Array */
					$targetArray[$attributeKey] = $obj;
				}
			}
		}
	}

	/**
	 * Parse the results of a RetrieveMultipleRequest into a useable PHP object
	 * @param DynamicsCRM2011_Connector $conn
	 * @param String $soapResponse
	 * @param Boolean $simpleMode
	 * @ignore
	 */
	protected static function parseRetrieveMultipleResponse(DynamicsCRM2011_Connector $conn, $soapResponse, $simpleMode) {
		/* Load the XML into a DOMDocument */
		$soapResponseDOM = new DOMDocument();
		$soapResponseDOM->loadXML($soapResponse);
		/* Find the RetrieveMultipleResponse */
		$retrieveMultipleResponseNode = NULL;
		foreach ($soapResponseDOM->getElementsByTagName('RetrieveMultipleResponse') as $node) {
			$retrieveMultipleResponseNode = $node;
			break;
		}
		unset($node);
		if ($retrieveMultipleResponseNode == NULL) {
			throw new Exception('Could not find RetrieveMultipleResponse node in XML provided');
			return FALSE;
		}
		/* Find the RetrieveMultipleResult node */
		$retrieveMultipleResultNode = NULL;
		foreach ($retrieveMultipleResponseNode->getElementsByTagName('RetrieveMultipleResult') as $node) {
			$retrieveMultipleResultNode = $node;
			break;
		}
		unset($node);
		if ($retrieveMultipleResultNode == NULL) {
			throw new Exception('Could not find RetrieveMultipleResult node in XML provided');
			return FALSE;
		}
		/* Assemble an associative array for the details to return */
		$responseDataArray = Array();
		$responseDataArray['EntityName'] = $retrieveMultipleResultNode->getElementsByTagName('EntityName')->length == 0 ? NULL : $retrieveMultipleResultNode->getElementsByTagName('EntityName')->item(0)->textContent;
		$responseDataArray['MoreRecords'] = ($retrieveMultipleResultNode->getElementsByTagName('MoreRecords')->item(0)->textContent == 'true');
		$responseDataArray['PagingCookie'] = $retrieveMultipleResultNode->getElementsByTagName('PagingCookie')->length == 0 ? NULL : $retrieveMultipleResultNode->getElementsByTagName('PagingCookie')->item(0)->textContent;
		$responseDataArray['Entities'] = Array();
		/* Loop through the Entities returned */
		foreach ($retrieveMultipleResultNode->getElementsByTagName('Entities')->item(0)->getElementsByTagName('Entity') as $entityNode) {
			/* If we are in "SimpleMode", just create the Attributes as a stdClass */
			if ($simpleMode) {
				/* Create an Array to hold the Entity properties */
				$entityArray = Array();
				/* Identify the Attributes */
				$keyValueNodes = $entityNode->getElementsByTagName('Attributes')->item(0)->getElementsByTagName('KeyValuePairOfstringanyType');
				/* Add the Attributes in the Key/Value Pairs of String/AnyType to the Array */
				self::addAttributes($entityArray, $keyValueNodes);
				/* Identify the FormattedValues */
				$keyValueNodes = $entityNode->getElementsByTagName('FormattedValues')->item(0)->getElementsByTagName('KeyValuePairOfstringstring');
				/* Add the Formatted Values in the Key/Value Pairs of String/String to the Array */
				self::addFormattedValues($entityArray, $keyValueNodes);
				/* Add the Entity to the Entities Array as a stdClass Object */
				$responseDataArray['Entities'][] = (Object)$entityArray;
			} else {
				/* Generate a new Entity from the DOMNode */
				$entity = DynamicsCRM2011_Entity::fromDOM($conn, $responseDataArray['EntityName'], $entityNode);
				/* Add the Entity to the Entities Array as a DynamicsCRM2011_Entity Object */
				$responseDataArray['Entities'][] = $entity;
			}
		}
		/* Record the number of Entities */
		$responseDataArray['Count'] = count($responseDataArray['Entities']);

		/* Convert the Array to a stdClass Object */
		$responseData = (Object)$responseDataArray;
		return $responseData;
	}

	/**
	 * Parse the results of a RetrieveRequest into a useable PHP object
	 * @param DynamicsCRM2011_Connector $conn
	 * @param String $entityLogicalName
	 * @param String $soapResponse
	 * @ignore
	 */
	protected static function parseRetrieveResponse(DynamicsCRM2011_Connector $conn, $entityLogicalName, $soapResponse) {
		/* Load the XML into a DOMDocument */
		$soapResponseDOM = new DOMDocument();
		$soapResponseDOM->loadXML($soapResponse);
		/* Find the RetrieveResponse */
		$retrieveResponseNode = NULL;
		foreach ($soapResponseDOM->getElementsByTagName('RetrieveResponse') as $node) {
			$retrieveResponseNode = $node;
			break;
		}
		unset($node);
		if ($retrieveResponseNode == NULL) {
			throw new Exception('Could not find RetrieveResponse node in XML provided');
			return FALSE;
		}
		/* Find the RetrieveResult node */
		$retrieveResultNode = NULL;
		foreach ($retrieveResponseNode->getElementsByTagName('RetrieveResult') as $node) {
			$retrieveResultNode = $node;
			break;
		}
		unset($node);
		if ($retrieveResultNode == NULL) {
			throw new Exception('Could not find RetrieveResult node in XML provided');
			return FALSE;
		}

		/* Generate a new Entity from the DOMNode */
		$entity = DynamicsCRM2011_Entity::fromDOM($conn, $entityLogicalName, $retrieveResultNode);
		return $entity;
	}

	/**
	 * Parse the results of a RetrieveRecordChangeHistory into a useable PHP object
	 * @ignore
	 */
	protected static function parseRetrieveRecordChangeHistoryResponse($soapResponse) {
		/* Load the XML into a DOMDocument */
		$soapResponseDOM = new DOMDocument();
		$soapResponseDOM->loadXML($soapResponse);
		/* Find the ExecuteResult node with Type c:RetrieveRecordChangeHistoryResponse */
		$executeResultNode = NULL;
		foreach ($soapResponseDOM->getElementsByTagName('ExecuteResult') as $node) {
			if ($node->hasAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type') && self::stripNS($node->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type')) == 'RetrieveRecordChangeHistoryResponse') {
				$executeResultNode = $node;
				break;
			}
		}
		unset($node);
		if ($executeResultNode == NULL) {
			throw new Exception('Could not find ExecuteResult for RetrieveRecordChangeHistoryResponse in XML provided');
			return FALSE;
		}
		/* Find the Value node with Type c:AuditDetailCollection */
		$auditDetailCollectionNode = NULL;
		foreach ($executeResultNode->getElementsByTagName('value') as $node) {
			if ($node->hasAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type') && self::stripNS($node->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type')) == 'AuditDetailCollection') {
				$auditDetailCollectionNode = $node;
				break;
			}
		}
		unset($node);
		if ($auditDetailCollectionNode == NULL) {
			throw new Exception('Could not find returned AuditDetailCollection in XML provided');
			return FALSE;
		}
		/* Assemble an associative array for the details to return */
		$responseDataArray = Array();
		$responseDataArray['MoreRecords'] = ($auditDetailCollectionNode->getElementsByTagName('MoreRecords')->item(0)->textContent == 'true');
		$responseDataArray['PagingCookie'] = $auditDetailCollectionNode->getElementsByTagName('PagingCookie')->length == 0 ? NULL : $auditDetailCollectionNode->getElementsByTagName('PagingCookie')->item(0)->textContent;
		$responseDataArray['AuditDetails'] = Array();
		/* Loop through the AuditDetails returned */
		foreach ($auditDetailCollectionNode->getElementsByTagName('AuditDetails')->item(0)->getElementsByTagName('AuditDetail') as $auditDetailNode) {
			/* Create an Array to hold the AuditDetail properties */
			$auditDetailArray = Array();

			/* Create an Array to hold the AuditRecord properties */
			$auditRecordArray = Array();
			/* Get the Attributes */
			$keyValueNodes = $auditDetailNode->getElementsByTagName('AuditRecord')->item(0)->getElementsByTagName('Attributes')->item(0)->getElementsByTagName('KeyValuePairOfstringanyType');
			/* Add the Attributes in the Key/Value Pairs of String/AnyType to the Array */
			self::addAttributes($auditRecordArray, $keyValueNodes);
			/* Get the FormattedValues */
			$keyValueNodes = $auditDetailNode->getElementsByTagName('AuditRecord')->item(0)->getElementsByTagName('FormattedValues')->item(0)->getElementsByTagName('KeyValuePairOfstringstring');
			/* Add the Formatted Values in the Key/Value Pairs of String/String to the Array */
			self::addFormattedValues($auditRecordArray, $keyValueNodes);
			/* Convert the Audit Details to an Object */
			$auditDetailArray['AuditRecord'] = (Object)$auditRecordArray;

			//$auditDetailArray['InvalidNewValueAttributes'] = $auditDetailNode->getElementsByTagName('InvalidNewValueAttributes')->item(0)->C14N();

			/* Create an Array to hold the New & Old Value properties */
			$valueArray = Array();
			/* Get the New Attributes */
			if ($auditDetailNode->getElementsByTagName('NewValue')->length > 0 &&
					$auditDetailNode->getElementsByTagName('NewValue')->item(0)->getElementsByTagName('Attributes')->length > 0) {
				/* Get the Attributes */
				$keyValueNodes = $auditDetailNode->getElementsByTagName('NewValue')->item(0)
						->getElementsByTagName('Attributes')->item(0)
						->getElementsByTagName('KeyValuePairOfstringanyType');
				/* Add the Attributes in the Key/Value Pairs of String/AnyType to the Array */
				self::addAttributes($valueArray, $keyValueNodes, Array('NewValue', 'OldValue'), 'NewValue');
				/* Get the New FormattedValues */
				$keyValueNodes = $auditDetailNode->getElementsByTagName('NewValue')->item(0)
						->getElementsByTagName('FormattedValues')->item(0)
						->getElementsByTagName('KeyValuePairOfstringstring');
				/* Add the attribute in the Key/Value Pair of String/String to the array */
				self::addFormattedValues($valueArray, $keyValueNodes, Array('NewValue', 'OldValue'), 'NewValue');
			}
			/* Get the Old Attributes */
			if ($auditDetailNode->getElementsByTagName('OldValue')->length > 0 &&
					$auditDetailNode->getElementsByTagName('OldValue')->item(0)->getElementsByTagName('Attributes')->length > 0) {
				/* Get the Attributes */
				$keyValueNodes = $auditDetailNode->getElementsByTagName('OldValue')->item(0)
						->getElementsByTagName('Attributes')->item(0)
						->getElementsByTagName('KeyValuePairOfstringanyType');
				/* Add the Attributes in the Key/Value Pairs of String/AnyType to the Array */
				self::addAttributes($valueArray, $keyValueNodes, Array('NewValue', 'OldValue'), 'OldValue');
				/* Get the Old FormattedValues */
				$keyValueNodes = $auditDetailNode->getElementsByTagName('OldValue')->item(0)
						->getElementsByTagName('FormattedValues')->item(0)
						->getElementsByTagName('KeyValuePairOfstringstring');
				/* Add the attribute in the Key/Value Pair of String/String to the array */
				self::addFormattedValues($valueArray, $keyValueNodes, Array('NewValue', 'OldValue'), 'OldValue');
			}
			$auditDetailArray['Values'] = (Object)$valueArray;
			/* Add the AuditDetail to the AuditDetails Array as a stdClass Object */
			$responseDataArray['AuditDetails'][] = (Object)$auditDetailArray;
		}

		/* Convert the Array to a stdClass Object */
		$responseData = (Object)$responseDataArray;

		/* Sort the AuditDetails by CreatedOn in Ascending order */
		$sortFunction = create_function('$a,$b', 'return ($a->AuditRecord->createdon->Value - $b->AuditRecord->createdon->Value);');
		usort($responseData->AuditDetails, $sortFunction);

		return $responseData;
	}

	/**
	 * Parse the results of a RetrieveEntity into a useable PHP object
	 * @ignore
	 */
	protected static function parseRetrieveEntityResponse($soapResponse) {
		/* Load the XML into a DOMDocument */
		$soapResponseDOM = new DOMDocument();
		$soapResponseDOM->loadXML($soapResponse);
		/* Find the ExecuteResult node with Type b:RetrieveRecordChangeHistoryResponse */
		$executeResultNode = NULL;
		foreach ($soapResponseDOM->getElementsByTagName('ExecuteResult') as $node) {
			if ($node->hasAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type') && self::stripNS($node->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type')) == 'RetrieveEntityResponse') {
				$executeResultNode = $node;
				break;
			}
		}
		unset($node);
		if ($executeResultNode == NULL) {
			throw new Exception('Could not find ExecuteResult for RetrieveEntityResponse in XML provided');
			return FALSE;
		}
		/* Find the Value node with Type d:EntityMetadata */
		$entityMetadataNode = NULL;
		foreach ($executeResultNode->getElementsByTagName('value') as $node) {
			if ($node->hasAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type') && self::stripNS($node->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type')) == 'EntityMetadata') {
				$entityMetadataNode = $node;
				break;
			}
		}
		unset($node);
		if ($entityMetadataNode == NULL) {
			throw new Exception('Could not find returned EntityMetadata in XML provided');
			return FALSE;
		}
		/* Assemble a simpleXML class for the details to return */
		$responseData = simplexml_import_dom($entityMetadataNode);

		/* Return the SimpleXML object */
		return $responseData;
	}

	/**
	 * Get the current Organization Service security token, or get a new one if necessary
	 * @ignore
	 */
	private function getOrganizationSecurityToken() {
		/* Check if there is an existing token */
		if ($this->organizationSecurityToken != NULL) {
			/* Check if the Security Token is still valid */
			if ($this->organizationSecurityToken['expiryTime'] > time()) {
				/* Use the existing token */
				return $this->organizationSecurityToken;
			}
		}
		/* Request a new Security Token for the Organization Service */
		$this->organizationSecurityToken = $this->requestSecurityToken($this->security['organization_authendpoint'], $this->organizationURI, $this->security['username'], $this->security['password']);
		/* Save the token, and return it */
		return $this->organizationSecurityToken;
	}

	/**
	 * Send a RetrieveMultiple request to the Dynamics CRM 2011 server
	 * and return the results as raw XML
	 *
	 * This is particularly useful when debugging the responses from the server
	 *
	 * @param string $queryXML the Fetch XML string (as generated by the Advanced Find tool on Microsoft Dynamics CRM 2011)
	 * @param string $pagingCookie if multiple pages are returned, send the paging cookie to get pages 2 and onwards.  Use NULL to get the first page
	 * @param integer $limitCount maximum number of records to be returned per page
	 * @return string the raw XML returned by the server, including all SOAP Envelope, Header and Body data.
	 */
	public function retrieveMultipleRaw($queryXML, $pagingCookie = NULL, $limitCount = NULL) {
		/* Send the sequrity request and get a security token */
		$securityToken = $this->getOrganizationSecurityToken();
		/* Generate the XML for the Body of a RetrieveMulitple request */
		$executeNode = self::generateRetrieveMultipleRequest($queryXML, $pagingCookie, $limitCount);
		/* Turn this into a SOAP request, and send it */
		$retrieveMultipleSoapRequest = self::generateSoapRequest($this->organizationURI, $this->getOrganizationRetrieveMultipleAction(), $securityToken, $executeNode, $this->callerId);
		$soapResponse = self::getSoapResponse($this->organizationURI, $retrieveMultipleSoapRequest);

		return $soapResponse;
	}

	/**
	 * Send a RetrieveMultiple request to the Dynamics CRM 2011 server
	 * and return the results as a structured Object
	 * Each Entity returned is processed into an appropriate DynamicsCRM2011_Entity object
	 *
	 * @param string $queryXML the Fetch XML string (as generated by the Advanced Find tool on Microsoft Dynamics CRM 2011)
	 * @param boolean $allPages indicates if the query should be resent until all possible data is retrieved
	 * @param string $pagingCookie if multiple pages are returned, send the paging cookie to get pages 2 and onwards.  Use NULL to get the first page.  Ignored if $allPages is specified.
	 * @param integer $limitCount maximum number of records to be returned per page
	 * @param boolean $simpleMode indicates if we should just use stdClass, instead of creating Entities
	 * @return stdClass a PHP Object containing all the data retrieved.
	 */
	public function retrieveMultiple($queryXML, $allPages = TRUE, $pagingCookie = NULL, $limitCount = NULL, $simpleMode = FALSE) {
		/* Prepare an Object to hold the returned data */
		$soapData = NULL;
		/* If we need all pages, ignore any supplied paging cookie */
		if ($allPages) $pagingCookie = NULL;
		do {
			/* Get the raw XML data */
			$rawSoapResponse = $this->retrieveMultipleRaw($queryXML, $pagingCookie, $limitCount);
			/* Parse the raw XML data into an Object */
			$tmpSoapData = self::parseRetrieveMultipleResponse($this, $rawSoapResponse, $simpleMode);
			/* If we already had some data, add the old Entities */
			if ($soapData != NULL) {
				$tmpSoapData->Entities = array_merge($soapData->Entities, $tmpSoapData->Entities);
				$tmpSoapData->Count += $soapData->Count;
			}
			/* Save the new Soap Data */
			$soapData = $tmpSoapData;

			/* Check if the PagingCookie is present & needed */
			if ($soapData->MoreRecords && $soapData->PagingCookie == NULL) {
				/* Paging Cookie is not present in returned data, but is expected! */
				/* Check if a Paging Cookie was supplied */
				if ($pagingCookie == NULL) {
					/* This was the first page */
					$pageNo = 1;
				} else {
					/* This is the page from the last PagingCookie, plus 1 */
					$pageNo = self::getPageNo($pagingCookie) + 1;
				}
				/* Create a new paging cookie for this page */
				$pagingCookie = '<cookie page="'.$pageNo.'"></cookie>';
				$soapData->PagingCookie = $pagingCookie;
			} else {
				/* PagingCookie exists, or is not needed */
				$pagingCookie = $soapData->PagingCookie;
			}

			/* Loop while there are more records, and we want all pages */
		} while ($soapData->MoreRecords && $allPages);

		/* Return the compiled structure */
		return $soapData;
	}

	/**
	 * Send a RetrieveMultiple request to the Dynamics CRM 2011 server
	 * and return the results as a structured Object
	 * Each Entity returned is processed into a simple stdClass
	 *
	 * Note that this function is faster than using Entities, but not as strong
	 * at handling complicated return types.
	 *
	 * @param string $queryXML the Fetch XML string (as generated by the Advanced Find tool on Microsoft Dynamics CRM 2011)
	 * @param boolean $allPages indicates if the query should be resent until all possible data is retrieved
	 * @param string $pagingCookie if multiple pages are returned, send the paging cookie to get pages 2 and onwards.  Use NULL to get the first page.  Ignored if $allPages is specified.
	 * @param integer $limitCount maximum number of records to be returned per page
	 * @return stdClass a PHP Object containing all the data retrieved.
	 */
	public function retrieveMultipleSimple($queryXML, $allPages = TRUE, $pagingCookie = NULL, $limitCount = NULL) {
		return $this->retrieveMultiple($queryXML, $allPages, $pagingCookie, $limitCount, true);
	}

	/**
	 * Send a RetrieveRecordChangeHistory request to the Dynamics CRM 2011 server
	 * and return the results as raw XML
	 *
	 * This is particularly useful when debugging the responses from the server
	 *
	 * @param string $entityType the LogicalName of the Entity to be retrieved (Incident, Account etc.)
	 * @param string $entityId the internal Id of the Entity to be retrieved (without enclosing brackets)
	 * @param string $pagingCookie if multiple pages are returned, send the paging cookie to get pages 2 and onwards.  Use NULL to get the first page
	 * @return string the raw XML returned by the server, including all SOAP Envelope, Header and Body data.
	 */
	public function retrieveRecordChangeHistoryRaw($entityType, $entityId, $pagingCookie = NULL) {
		/* Send the sequrity request and get a security token */
		$securityToken = $this->getOrganizationSecurityToken();
		/* Generate the XML for the Body of a RetrieveRecordChangeHistory request */
		$executeNode = self::generateRetrieveRecordChangeHistoryRequest($entityType, $entityId, $pagingCookie);
		/* Turn this into a SOAP request, and send it */
		$retrieveRecordChangeHistorySoapRequest = self::generateSoapRequest($this->organizationURI, $this->getOrganizationExecuteAction(), $securityToken, $executeNode, $this->callerId);
		$soapResponse = self::getSoapResponse($this->organizationURI, $retrieveRecordChangeHistorySoapRequest);

		return $soapResponse;
	}

	/**
	 * Send a RetrieveRecordChangeHistory request to the Dynamics CRM 2011 server
	 * and return the results as a structured Object
	 *
	 * @param string $entityType the LogicalName of the Entity to be retrieved (Incident, Account etc.)
	 * @param string $entityId the internal Id of the Entity to be retrieved (without enclosing brackets)
	 * @param boolean $allPages indicates if the query should be resent until all possible data is retrieved
	 * @param string $pagingCookie if multiple pages are returned, send the paging cookie to get pages 2 and onwards.  Use NULL to get the first page.  Ignored if $allPages is specified.
	 * @return stdClass a PHP Object containing all the data retrieved.
	 */
	public function retrieveRecordChangeHistory($entityType, $entityId, $allPages = FALSE, $pagingCookie = NULL) {
		/* Prepare an Object to hold the returned data */
		$soapData = NULL;
		/* If we need all pages, ignore any supplied paging cookie */
		if ($allPages) $pagingCookie = NULL;
		$page = 0;
		do {
			/* Get the raw XML data */
			$rawSoapResponse = $this->retrieveRecordChangeHistoryRaw($entityType, $entityId, $pagingCookie);
			/* Parse the raw XML data into an Object */
			$tmpSoapData = self::parseRetrieveRecordChangeHistoryResponse($rawSoapResponse);
			/* If we already had some data, add the old Entities */
			if ($soapData != NULL) {
				$tmpSoapData->AuditDetails = array_merge($soapData->AuditDetails, $tmpSoapData->AuditDetails);
			}
			/* Save the new Soap Data */
			$soapData = $tmpSoapData;
			/* Grab the Paging Cookie */
			$pagingCookie = $soapData->PagingCookie;
		} while ($soapData->MoreRecords && $allPages);

		return $soapData;
	}

	/**
	 * Send a Retrieve request to the Dynamics CRM 2011 server and return the results as raw XML
	 * This function is typically used just after creating something (where you get the ID back
	 * as the return value), as it is more efficient to use RetrieveMultiple to search directly if
	 * you don't already have the ID.
	 *
	 * This is particularly useful when debugging the responses from the server
	 *
	 * @param DynamicsCRM2011_Entity $entity the Entity to retrieve - must have an ID specified
	 * @param array $fieldSet array listing all fields to be fetched, or null to get all fields
	 * @return string the raw XML returned by the server, including all SOAP Envelope, Header and Body data.
	 */
	public function retrieveRaw(DynamicsCRM2011_Entity $entity, $fieldSet = NULL) {
		/* Determine the Type & ID of the Entity */
		$entityType = $entity->LogicalName;
		$entityId = $entity->ID;
		/* Send the sequrity request and get a security token */
		$securityToken = $this->getOrganizationSecurityToken();
		/* Generate the XML for the Body of a RetrieveRecordChangeHistory request */
		$executeNode = self::generateRetrieveRequest($entityType, $entityId, $fieldSet);
		/* Turn this into a SOAP request, and send it */
		$retrieveRequest = self::generateSoapRequest($this->organizationURI, $this->getOrganizationRetrieveAction(), $securityToken, $executeNode, $this->callerId);
		$soapResponse = self::getSoapResponse($this->organizationURI, $retrieveRequest);

		return $soapResponse;
	}

	/**
	 * Send a Retrieve request to the Dynamics CRM 2011 server and return the results as a structured Object
	 * This function is typically used just after creating something (where you get the ID back
	 * as the return value), as it is more efficient to use RetrieveMultiple to search directly if
	 * you don't already have the ID.
	 *
	 * @param DynamicsCRM2011_Entity $entity the Entity to retrieve - must have an ID specified
	 * @param array $fieldSet array listing all fields to be fetched, or null to get all fields
	 * @return DynamicsCRM2011_Entity (subclass) a Strongly-Typed Entity containing all the data retrieved.
	 */
	public function retrieve(DynamicsCRM2011_Entity $entity, $fieldSet = NULL) {
		/* Only allow "Retrieve" for an Entity with an ID */
		if ($entity->ID == self::EmptyGUID) {
			throw new Exception('Cannot Retrieve an Entity without an ID.');
			return FALSE;
		}

		/* Get the raw XML data */
		$rawSoapResponse = $this->retrieveRaw($entity, $fieldSet);
		/* Parse the raw XML data into an Object */
		$newEntity = self::parseRetrieveResponse($this, $entity->LogicalName, $rawSoapResponse);
		/* Return the structured object */
		return $newEntity;
	}

	/**
	 * Send a RetrieveEntity request to the Dynamics CRM 2011 server and return the results as raw XML
	 *
	 * This is particularly useful when debugging the responses from the server
	 *
	 * @param string $entityType the LogicalName of the Entity to be retrieved (Incident, Account etc.)
	 * @return string the raw XML returned by the server, including all SOAP Envelope, Header and Body data.
	 */
	public function retrieveEntityRaw($entityType, $entityId = NULL, $entityFilters = NULL, $showUnpublished = false) {
		/* Send the sequrity request and get a security token */
		$securityToken = $this->getOrganizationSecurityToken();
		/* Generate the XML for the Body of a RetrieveEntity request */
		$executeNode = self::generateRetrieveEntityRequest($entityType, $entityId, $entityFilters, $showUnpublished);
		/* Turn this into a SOAP request, and send it */
		$retrieveEntityRequest = self::generateSoapRequest($this->organizationURI, $this->getOrganizationExecuteAction(), $securityToken, $executeNode, $this->callerId);
		$soapResponse = self::getSoapResponse($this->organizationURI, $retrieveEntityRequest);

		return $soapResponse;
	}

	/**
	 * Send a RetrieveEntity request to the Dynamics CRM 2011 server and return the results as a structured Object
	 *
	 * @param string $entityType the LogicalName of the Entity to be retrieved (Incident, Account etc.)
	 * @param string $entityId the internal Id of the Entity to be retrieved (without enclosing brackets)
	 * @param array $columnSet array listing all fields to be fetched, or null to get all columns
	 * @return stdClass a PHP Object containing all the data retrieved.
	 */
	public function retrieveEntity($entityType, $entityId = NULL, $entityFilters = NULL, $showUnpublished = false) {
		/* Get the raw XML data */
		$rawSoapResponse = $this->retrieveEntityRaw($entityType, $entityId, $entityFilters, $showUnpublished);
		/* Parse the raw XML data into an Object */
		$soapData = self::parseRetrieveEntityResponse($rawSoapResponse);
		/* Return the structured object */
		return $soapData;
	}

	/**
	 * Send a Create request to the Dynamics CRM 2011 server, and return the ID of the newly created Entity
	 *
	 * @param DynamicsCRM2011_Entity $entity the Entity to create
	 */
	public function create(DynamicsCRM2011_Entity &$entity) {
		/* Only allow "Create" for an Entity with no ID */
		if ($entity->ID != self::EmptyGUID) {
			throw new Exception('Cannot Create an Entity that already exists.');
			return FALSE;
		}

		/* Send the sequrity request and get a security token */
		$securityToken = $this->getOrganizationSecurityToken();
		/* Generate the XML for the Body of a Create request */
		$createNode = self::generateCreateRequest($entity);

		if (self::$debugMode) echo PHP_EOL.'Create Request: '.PHP_EOL.$createNode->C14N().PHP_EOL.PHP_EOL;

		/* Turn this into a SOAP request, and send it */
		$createRequest = self::generateSoapRequest($this->organizationURI, $this->getOrganizationCreateAction(), $securityToken, $createNode, $this->callerId);
		$soapResponse = self::getSoapResponse($this->organizationURI, $createRequest);

		if (self::$debugMode) echo PHP_EOL.'Create Response: '.PHP_EOL.$soapResponse.PHP_EOL.PHP_EOL;

		/* Load the XML into a DOMDocument */
		$soapResponseDOM = new DOMDocument();
		$soapResponseDOM->loadXML($soapResponse);

		/* Find the CreateResponse */
		$createResponseNode = NULL;
		foreach ($soapResponseDOM->getElementsByTagName('CreateResponse') as $node) {
			$createResponseNode = $node;
			break;
		}
		unset($node);
		if ($createResponseNode == NULL) {
			throw new Exception('Could not find CreateResponse node in XML returned from Server');
			return FALSE;
		}

		/* Get the EntityID from the CreateResult tag */
		$entityID = $createResponseNode->getElementsByTagName('CreateResult')->item(0)->textContent;
		$entity->ID = $entityID;
		$entity->reset();
		return $entityID;
	}

	/**
	 * Generate a Create Request
	 * @ignore
	 */
	protected static function generateCreateRequest(DynamicsCRM2011_Entity $entity) {
		/* Generate the CreateRequest message */
		$createRequestDOM = new DOMDocument();
		$createNode = $createRequestDOM->appendChild($createRequestDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts/Services', 'Create'));
		$createNode->appendChild($createRequestDOM->importNode($entity->getEntityDOM(), true));
		/* Return the DOMNode */
		return $createNode;
	}

	/**
	 * Check if an Entity Definition has been cached
	 *
	 * @param String $entityLogicalName Logical Name of the entity to check for in the Cache
	 * @return boolean true if this Entity has been cached
	 */
	public function isEntityDefinitionCached($entityLogicalName) {
		/* Check if this entityLogicalName is in the Cache */
		if (array_key_exists($entityLogicalName, $this->cachedEntityDefintions)) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Cache the definition of an Entity
	 *
	 * @param String $entityLogicalName
	 * @param SimpleXMLElement $entityData
	 * @param Array $propertiesArray
	 * @param Array $propertyValuesArray
	 * @param Array $mandatoriesArray
	 * @param Array $optionSetsArray
	 * @param String $entityDisplayName
	 */
	public function setCachedEntityDefinition($entityLogicalName,
			SimpleXMLElement $entityData, Array $propertiesArray, Array $propertyValuesArray,
			Array $mandatoriesArray, Array $optionSetsArray, $entityDisplayName) {
		/* Store the details of the Entity Definition in the Cache */
		$this->cachedEntityDefintions[$entityLogicalName] = Array(
				$entityData, $propertiesArray, $propertyValuesArray,
				$mandatoriesArray, $optionSetsArray, $entityDisplayName);
	}

	/**
	 * Get the Definition of an Entity from the Cache
	 *
	 * @param String $entityLogicalName
	 * @param SimpleXMLElement $entityData
	 * @param Array $propertiesArray
	 * @param Array $propertyValuesArray
	 * @param Array $mandatoriesArray
	 * @param Array $optionSetsArray
	 * @param String $entityDisplayName
	 * @return boolean true if the Cache was retrieved
	 */
	public function getCachedEntityDefinition($entityLogicalName,
			&$entityData, Array &$propertiesArray, Array &$propertyValuesArray, Array &$mandatoriesArray,
			Array &$optionSetsArray, &$entityDisplayName) {
		/* Check that this Entity Definition has been Cached */
		if ($this->isEntityDefinitionCached($entityLogicalName)) {
			/* Populate the containers and return true
			 * Note that we rely on PHP's "Copy on Write" functionality to prevent massive memory use:
			 * the only array that is ever updated inside an Entity is the propertyValues array (and the
			 * localProperties array) - the other data therefore becomes a single reference during
			 * execution.
			 */
			$entityData = $this->cachedEntityDefintions[$entityLogicalName][0];
			$propertiesArray = $this->cachedEntityDefintions[$entityLogicalName][1];
			$propertyValuesArray = $this->cachedEntityDefintions[$entityLogicalName][2];
			$mandatoriesArray = $this->cachedEntityDefintions[$entityLogicalName][3];
			$optionSetsArray = $this->cachedEntityDefintions[$entityLogicalName][4];
			$entityDisplayName = $this->cachedEntityDefintions[$entityLogicalName][5];
			return true;
		} else {
			/* Not found - clear passed containers and return false */
			$entityData = NULL;
			$propertiesArray = NULL;
			$propertyValuesArray = NULL;
			$mandatoriesArray = NULL;
			$optionSetsArray = NULL;
			$entityDisplayName = NULL;
			return false;
		}
	}

	/**
	 * Send a Delete request to the Dynamics CRM 2011 server, and return ...
	 *
	 * @param DynamicsCRM2011_Entity $entity the Entity to delete
	 */
	public function delete(DynamicsCRM2011_Entity &$entity) {
		/* Only allow "Delete" for an Entity with an ID */
		if ($entity->ID == self::EmptyGUID) {
			throw new Exception('Cannot Delete an Entity without an ID.');
			return FALSE;
		}

		/* Send the sequrity request and get a security token */
		$securityToken = $this->getOrganizationSecurityToken();
		/* Generate the XML for the Body of a Delete request */
		$deleteNode = self::generateDeleteRequest($entity);

		if (self::$debugMode) echo PHP_EOL.'Delete Request: '.PHP_EOL.$deleteNode->C14N().PHP_EOL.PHP_EOL;

		/* Turn this into a SOAP request, and send it */
		$deleteRequest = self::generateSoapRequest($this->organizationURI, $this->getOrganizationDeleteAction(), $securityToken, $deleteNode, $this->callerId);
		$soapResponse = self::getSoapResponse($this->organizationURI, $deleteRequest);

		if (self::$debugMode) echo PHP_EOL.'Delete Response: '.PHP_EOL.$soapResponse.PHP_EOL.PHP_EOL;

		/* Load the XML into a DOMDocument */
		$soapResponseDOM = new DOMDocument();
		$soapResponseDOM->loadXML($soapResponse);

 		/* Find the DeleteResponse */
 		$deleteResponseNode = NULL;
 		foreach ($soapResponseDOM->getElementsByTagName('DeleteResponse') as $node) {
 			$deleteResponseNode = $node;
 			break;
 		}
 		unset($node);
 		if ($deleteResponseNode == NULL) {
 			throw new Exception('Could not find DeleteResponse node in XML returned from Server');
 			return FALSE;
 		}
 		/* Delete occurred successfully */
		return TRUE;
	}

	/**
	 * Generate a Delete Request
	 * @ignore
	 */
	protected static function generateDeleteRequest(DynamicsCRM2011_Entity $entity) {
		/* Generate the DeleteRequest message */
		$deleteRequestDOM = new DOMDocument();
		$deleteNode = $deleteRequestDOM->appendChild($deleteRequestDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts/Services', 'Delete'));
		$deleteNode->appendChild($deleteRequestDOM->createElement('entityName', $entity->logicalName));
		$deleteNode->appendChild($deleteRequestDOM->createElement('id', $entity->ID));
		/* Return the DOMNode */
		return $deleteNode;
	}

	/**
	 * Send an Update request to the Dynamics CRM 2011 server, and return ...
	 *
	 * @param DynamicsCRM2011_Entity $entity the Entity to update
	 */
	public function update(DynamicsCRM2011_Entity &$entity) {
		/* Only allow "Update" for an Entity with an ID */
		if ($entity->ID == self::EmptyGUID) {
			throw new Exception('Cannot Update an Entity without an ID.');
			return FALSE;
		}

		/* Send the sequrity request and get a security token */
		$securityToken = $this->getOrganizationSecurityToken();
		/* Generate the XML for the Body of an Update request */
		$updateNode = self::generateUpdateRequest($entity);

		if (self::$debugMode) echo PHP_EOL.'Update Request: '.PHP_EOL.$updateNode->C14N().PHP_EOL.PHP_EOL;

		/* Turn this into a SOAP request, and send it */
		$updateRequest = self::generateSoapRequest($this->organizationURI, $this->getOrganizationUpdateAction(), $securityToken, $updateNode, $this->callerId);
		$soapResponse = self::getSoapResponse($this->organizationURI, $updateRequest);

		if (self::$debugMode) echo PHP_EOL.'Update Response: '.PHP_EOL.$soapResponse.PHP_EOL.PHP_EOL;

		/* Load the XML into a DOMDocument */
		$soapResponseDOM = new DOMDocument();
		$soapResponseDOM->loadXML($soapResponse);

		/* Find the UpdateResponse */
		$updateResponseNode = NULL;
		foreach ($soapResponseDOM->getElementsByTagName('UpdateResponse') as $node) {
			$updateResponseNode = $node;
			break;
		}
		unset($node);
		if ($updateResponseNode == NULL) {
			throw new Exception('Could not find UpdateResponse node in XML returned from Server');
			return FALSE;
		}
		/* Update occurred successfully */
		return $updateResponseNode->C14N();
	}

	/**
	 * Generate an Update Request
	 * @ignore
	 */
	protected static function generateUpdateRequest(DynamicsCRM2011_Entity $entity) {
		/* Generate the UpdateRequest message */
		$updateRequestDOM = new DOMDocument();
		$updateNode = $updateRequestDOM->appendChild($updateRequestDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts/Services', 'Update'));
		$updateNode->appendChild($updateRequestDOM->importNode($entity->getEntityDOM(), true));
		/* Return the DOMNode */
		return $updateNode;
	}

	/**
	 * Send a SetStateRequest request to the Dynamics CRM 2011 server and return...
	 *
	 * @param DynamicsCRM2011_Entity $entity Entity that is to be updated
	 * @param int $state StateCode to set
	 * @param int $status StatusCode to set (or NULL to use StateCode)
	 * @return boolean indicator of success
	 */
	public function setState(DynamicsCRM2011_Entity $entity, $state, $status = NULL) {
		/* If there is no Status, use the State */
		if ($status == NULL) $status = $state;
		/* Send the sequrity request and get a security token */
		$securityToken = $this->getOrganizationSecurityToken();
		/* Generate the XML for the Body of a RetrieveRecordChangeHistory request */
		$executeNode = self::generateSetStateRequest($entity, $state, $status);

		if (self::$debugMode) echo PHP_EOL.'SetState Request: '.PHP_EOL.$executeNode->C14N().PHP_EOL.PHP_EOL;

		/* Turn this into a SOAP request, and send it */
		$setStateSoapRequest = self::generateSoapRequest($this->organizationURI, $this->getOrganizationExecuteAction(), $securityToken, $executeNode, $this->callerId);
		$soapResponse = self::getSoapResponse($this->organizationURI, $setStateSoapRequest);

		if (self::$debugMode) echo PHP_EOL.'SetState Response: '.PHP_EOL.$soapResponse.PHP_EOL.PHP_EOL;

		/* Load the XML into a DOMDocument */
		$soapResponseDOM = new DOMDocument();
		$soapResponseDOM->loadXML($soapResponse);

		/* Find the ExecuteResponse */
		$executeResponseNode = NULL;
		foreach ($soapResponseDOM->getElementsByTagName('ExecuteResponse') as $node) {
			$executeResponseNode = $node;
			break;
		}
		unset($node);
		if ($executeResponseNode == NULL) {
			throw new Exception('Could not find ExecuteResponse node in XML returned from Server');
			return FALSE;
		}

		/* Find the ExecuteResult */
		$executeResultNode = NULL;
		foreach ($executeResponseNode->getElementsByTagName('ExecuteResult') as $node) {
			$executeResultNode = $node;
			break;
		}
		unset($node);
		if ($executeResultNode == NULL) {
			throw new Exception('Could not find ExecuteResult node in XML returned from Server');
			return FALSE;
		}

		/* Update occurred successfully */
		return true;
	}

	/**
	 * Generate a SetState Request
	 * @ignore
	 */
	protected static function generateSetStateRequest(DynamicsCRM2011_Entity $entity, $state, $status) {
		/* Generate the SetStateRequest message */
		$setStateRequestDOM = new DOMDocument();
		$executeNode = $setStateRequestDOM->appendChild($setStateRequestDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts/Services', 'Execute'));
		$requestNode = $executeNode->appendChild($setStateRequestDOM->createElement('request'));
		$requestNode->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'i:type', 'c:SetStateRequest');
		$requestNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:b', 'http://schemas.microsoft.com/xrm/2011/Contracts');
		$requestNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.microsoft.com/crm/2011/Contracts');
		$parametersNode = $requestNode->appendChild($setStateRequestDOM->createElement('b:Parameters'));
		$parametersNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:d', 'http://schemas.datacontract.org/2004/07/System.Collections.Generic');

		$keyValuePairNode1 = $parametersNode->appendChild($setStateRequestDOM->createElement('b:KeyValuePairOfstringanyType'));
		$keyValuePairNode1->appendChild($setStateRequestDOM->createElement('d:key', 'EntityMoniker'));
		$valueNode1 = $keyValuePairNode1->appendChild($setStateRequestDOM->createElement('d:value'));
		$valueNode1->setAttribute('i:type', 'b:EntityReference');
		$valueNode1->appendChild($setStateRequestDOM->createElement('b:Id', $entity->ID));
		$valueNode1->appendChild($setStateRequestDOM->createElement('b:LogicalName', $entity->LogicalName));
		$valueNode1->appendChild($setStateRequestDOM->createElement('b:Name'))->setAttribute('i:nil', 'true');

		$keyValuePairNode2 = $parametersNode->appendChild($setStateRequestDOM->createElement('b:KeyValuePairOfstringanyType'));
		$keyValuePairNode2->appendChild($setStateRequestDOM->createElement('d:key', 'State'));
		$valueNode2 = $keyValuePairNode2->appendChild($setStateRequestDOM->createElement('d:value'));
		$valueNode2->setAttribute('i:type', 'b:OptionSetValue');
		$valueNode2->appendChild($setStateRequestDOM->createElement('b:Value', $state));

		$keyValuePairNode3 = $parametersNode->appendChild($setStateRequestDOM->createElement('b:KeyValuePairOfstringanyType'));
		$keyValuePairNode3->appendChild($setStateRequestDOM->createElement('d:key', 'Status'));
		$valueNode3 = $keyValuePairNode3->appendChild($setStateRequestDOM->createElement('d:value'));
		$valueNode3->setAttribute('i:type', 'b:OptionSetValue');
		$valueNode3->appendChild($setStateRequestDOM->createElement('b:Value', $status));

		$requestNode->appendChild($setStateRequestDOM->createElement('b:RequestId'))->setAttribute('i:nil', 'true');
		$requestNode->appendChild($setStateRequestDOM->createElement('b:RequestName', 'SetState'));
		/* Return the DOMNode */
		return $executeNode;
	}

	/**
	 * Get all the details of the Connector that would be needed to
	 * bypass the normal login process next time...
	 * Note that the Entity definition cache, the DOMs and the security
	 * policies are excluded from the Cache.
	 * @return Array
	 */
	public function getLoginCache() {
		return Array(
				$this->discoveryURI,
				$this->organizationUniqueName,
				$this->organizationURI,
				$this->security,
				NULL,
				$this->discoverySoapActions,
				$this->discoveryExecuteAction,
				NULL,
				NULL,
				$this->organizationSoapActions,
				$this->organizationCreateAction,
				$this->organizationDeleteAction,
				$this->organizationExecuteAction,
				$this->organizationRetrieveAction,
				$this->organizationRetrieveMultipleAction,
				$this->organizationUpdateAction,
				NULL,
				$this->organizationSecurityToken,
				Array(),
				self::$connectorTimeout,
				self::$maximumRecords,);
	}

	/**
	 * Restore the cached details
	 * @param Array $loginCache
	 */
	private function loadLoginCache(Array $loginCache) {
		list(
				$this->discoveryURI,
				$this->organizationUniqueName,
				$this->organizationURI,
				$this->security,
				$this->discoveryDOM,
				$this->discoverySoapActions,
				$this->discoveryExecuteAction,
				$this->discoverySecurityPolicy,
				$this->organizationDOM,
				$this->organizationSoapActions,
				$this->organizationCreateAction,
				$this->organizationDeleteAction,
				$this->organizationExecuteAction,
				$this->organizationRetrieveAction,
				$this->organizationRetrieveMultipleAction,
				$this->organizationUpdateAction,
				$this->organizationSecurityPolicy,
				$this->organizationSecurityToken,
				$this->cachedEntityDefintions,
				self::$connectorTimeout,
				self::$maximumRecords) = $loginCache;
	}

	/**
	 * Search for a particular Entity using the entity type and name only
	 *
	 * @param String $entityLogicalName - Logical name of the entity to be found
	 * @param String $searchField - Field to search in
	 * @param String $searchValue - Text to search for
	 * @return DynamicsCRM2011_Entity (subclass) a Strongly-Typed Entity minimal data (name and ID) for the Entity
	 */
	public function retrieveByName($entityLogicalName, $searchField, $searchValue) {
		/* Build a query for the particular item we're searching for */
		$queryXML = <<<END
<fetch version="1.0" output-format="xml-platform" mapping="logical" distinct="false">
  <entity name="${entityLogicalName}">
    <filter type="and">
      <condition attribute="${searchField}" operator="eq" value="${searchValue}" />
    </filter>
  </entity>
</fetch>
END;
		/* Launch the query */
		$data = $this->retrieveMultiple($queryXML);

		/* Check how many results were found */
		if ($data->Count == 1) {
			/* Just one result - return it as is */
			return $data->Entities[0];
		} elseif ($data->Count == 0) {
			/* No results - return NULL */
			return NULL;
		} else {
			/* Multiple results - return the whole array */
			return $data->Entities;
		}
	}

	/**
	 * Set the CRM User that will be responsible for CRM updates from now on
	 *
	 * @param DynamicsCRM2011_SystemUser $_crmUser
	 */
	public function setUserOverride(DynamicsCRM2011_SystemUser $_crmUser) {
		$this->callerId = $_crmUser;
	}

	/**
	 * Reset the user for Creates and Updates back to the default
	 */
	public function clearUserOverride() {
		$this->callerId = NULL;
	}

	/**
	 * Send a CloseIncidentRequest request to the Dynamics CRM 2011 server and return...
	 *
	 * @param DynamicsCRM2011_Entity $entity Entity that is to be updated
	 * @param int $state StateCode to set
	 * @param int $status StatusCode to set (or NULL to use StateCode)
	 * @return boolean indicator of success
	 */
	public function closeIncident(DynamicsCRM2011_IncidentResolution $_resolution, $status) {
		/* Send the sequrity request and get a security token */
		$securityToken = $this->getOrganizationSecurityToken();
		/* Generate the XML for the Body of a CloseIncidentRequest request */
		$executeNode = self::generateCloseIncidentRequest($_resolution, $status);

		if (self::$debugMode) echo PHP_EOL.'CloseIncident Request: '.PHP_EOL.$executeNode->C14N().PHP_EOL.PHP_EOL;

		/* Turn this into a SOAP request, and send it */
		$setStateSoapRequest = self::generateSoapRequest($this->organizationURI, $this->getOrganizationExecuteAction(), $securityToken, $executeNode, $this->callerId);
		$soapResponse = self::getSoapResponse($this->organizationURI, $setStateSoapRequest);

		if (self::$debugMode) echo PHP_EOL.'CloseIncident Response: '.PHP_EOL.$soapResponse.PHP_EOL.PHP_EOL;

		/* Load the XML into a DOMDocument */
		$soapResponseDOM = new DOMDocument();
		$soapResponseDOM->loadXML($soapResponse);

		/* Find the ExecuteResponse */
		$executeResponseNode = NULL;
		foreach ($soapResponseDOM->getElementsByTagName('ExecuteResponse') as $node) {
			$executeResponseNode = $node;
			break;
		}
		unset($node);
		if ($executeResponseNode == NULL) {
			throw new Exception('Could not find ExecuteResponse node in XML returned from Server');
			return FALSE;
		}

		/* Find the ExecuteResult */
		$executeResultNode = NULL;
		foreach ($executeResponseNode->getElementsByTagName('ExecuteResult') as $node) {
			$executeResultNode = $node;
			break;
		}
		unset($node);
		if ($executeResultNode == NULL) {
			throw new Exception('Could not find ExecuteResult node in XML returned from Server');
			return FALSE;
		}

		/* Update occurred successfully */
		return true;
	}

	/**
	 * Generate a CloseIncidentRequest
	 * @ignore
	 */
	protected static function generateCloseIncidentRequest(DynamicsCRM2011_IncidentResolution $_resolution, $status) {
		/* Generate the CloseIncidentRequest message */
		$closeIncidentRequestDOM = new DOMDocument();
		$executeNode = $closeIncidentRequestDOM->appendChild($closeIncidentRequestDOM->createElementNS('http://schemas.microsoft.com/xrm/2011/Contracts/Services', 'Execute'));
		$requestNode = $executeNode->appendChild($closeIncidentRequestDOM->createElement('request'));
		$requestNode->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'i:type', 'e:CloseIncidentRequest');
		$requestNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:b', 'http://schemas.microsoft.com/xrm/2011/Contracts');
		$requestNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:e', 'http://schemas.microsoft.com/crm/2011/Contracts');
		$parametersNode = $requestNode->appendChild($closeIncidentRequestDOM->createElement('b:Parameters'));
		$parametersNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:c', 'http://schemas.datacontract.org/2004/07/System.Collections.Generic');

		$keyValuePairNode1 = $parametersNode->appendChild($closeIncidentRequestDOM->createElement('b:KeyValuePairOfstringanyType'));
		$keyValuePairNode1->appendChild($closeIncidentRequestDOM->createElement('c:key', 'IncidentResolution'));
		$valueNode1 = $keyValuePairNode1->appendChild($closeIncidentRequestDOM->createElement('c:value'));
		$valueNode1->setAttribute('i:type', 'b:Entity');
		$valueNode1->appendChild($closeIncidentRequestDOM->importNode($_resolution->getEntityDOM()->firstChild, true));
		$valueNode1->appendChild($closeIncidentRequestDOM->createElement('b:EntityState'))->setAttribute('i:nil', 'true');
		$valueNode1->appendChild($closeIncidentRequestDOM->createElement('b:FormattedValues'));
		$valueNode1->appendChild($closeIncidentRequestDOM->createElement('b:Id', self::EmptyGUID));
		$valueNode1->appendChild($closeIncidentRequestDOM->createElement('b:LogicalName', $_resolution->LogicalName));
		$valueNode1->appendChild($closeIncidentRequestDOM->createElement('b:RelatedEntities'));

		$keyValuePairNode2 = $parametersNode->appendChild($closeIncidentRequestDOM->createElement('b:KeyValuePairOfstringanyType'));
		$keyValuePairNode2->appendChild($closeIncidentRequestDOM->createElement('c:key', 'Status'));
		$valueNode2 = $keyValuePairNode2->appendChild($closeIncidentRequestDOM->createElement('c:value'));
		$valueNode2->setAttribute('i:type', 'b:OptionSetValue');
		$valueNode2->appendChild($closeIncidentRequestDOM->createElement('b:Value', $status));

		$requestNode->appendChild($closeIncidentRequestDOM->createElement('b:RequestId'))->setAttribute('i:nil', 'true');
		$requestNode->appendChild($closeIncidentRequestDOM->createElement('b:RequestName', 'CloseIncident'));
		/* Return the DOMNode */
		return $executeNode;
	}
}

?>
