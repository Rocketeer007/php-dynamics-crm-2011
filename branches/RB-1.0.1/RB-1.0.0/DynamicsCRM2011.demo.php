<?php

/* Include the class library for the Dynamics CRM 2011 Connector */
require_once 'DynamicsCRM2011.php';

/* Make life easier for developers: Make a copy of the Config file locally, and
 * name it DynamicsCRM2011.config.local.php - if found, it will be used in preference
 * to the normal configuration details in the DynamicsCRM2011.config.php file 
 */
if (file_exists('DynamicsCRM2011.config.local.php')) include 'DynamicsCRM2011.config.local.php';
else include 'DynamicsCRM2011.config.php';

/* Choose which demos to execute by commenting out values here */
define('SKIP_DEMO1', TRUE);
define('SKIP_DEMO2', TRUE);
define('SKIP_DEMO3', TRUE);
define('SKIP_DEMO4', TRUE);
define('SKIP_DEMO5', TRUE);
define('SKIP_DEMO6', TRUE);
define('SKIP_DEMO7', TRUE);
define('SKIP_DEMO8', TRUE);
define('SKIP_DEMO9', TRUE);
//define('SKIP_DEMO10', TRUE);

/****************************************************************************
There are two ways to connect to the Microsoft Dynamics 2011 CRM server.
The first option is ideal for scripts and cron jobs, where the username & 
password are known and probably hard-coded in the script.
Call the DynamicsCRM2011Connector constructor and provide all four parameters
 - the Discovery Service URI
 - the Organization Unique Name
 - the Username
 - the Password
The Object will automatically connect to the Discovery Service using the 
login details provided, and find the correct URI to use for the Organization 
service for the Organization you have specified.
This can take around 10 seconds, as large amounts of XML must be fetched and 
then parsed to determine the correct way to login, and the addresses to use
*****************************************************************************/
/* Connect to the Dynamics CRM 2011 server */
echo date('Y-m-d H:i:s')."\tConnecting to the CRM... ";
$crmConnector = new DynamicsCRM2011_Connector($discoveryServiceURI, $organizationUniqueName, $loginUsername, $loginPassword);
echo 'Done'.PHP_EOL;

/****************************************************************************
The second option is more focussed on interactive systems, i.e. web-based 
reporting systems etc. where response times are critical, and the username
and password might not be known in advance.
This requires slightly more code, but splits the work into two separate stages
and provides an opportunity to verify the login details.

In the first stage, call the DynamicsCRM2011Connector constructor with just
 - the Discovery Service URI
 - the Organization Unique Name
The Object will query the Discovery Service to determine the login method
to use, but will not be able to progress any further, as the login details
are not know.

Then, call the setDiscoveryFederationSecurity method with the appropriate
login details.  The Object will then use these details to find the correct 
URI to use for the Organization service for the Organization you have 
specified in the constructor.

Each step takes around 5 seconds - so you could call the Constructor before
displaying the Login page, and then use the setDiscoveryFederationSecurity
after receiving Login details (assuming the Object is kept for the entire
session)
*****************************************************************************/
if (!defined('SKIP_DEMO1')) {
	/* Connect to the Dynamics CRM 2011 server */
	echo date('Y-m-d H:i:s')."\tConnecting to the CRM... ";
	$crmConnector = new DynamicsCRM2011_Connector($discoveryServiceURI, $organizationUniqueName);
	echo 'Done'.PHP_EOL;
	/* Here, you could ask the user for the login details... */
	echo date('Y-m-d H:i:s')."\tVerifying Security Details... ";
	$loginOkay = $crmConnector->setDiscoveryFederationSecurity($loginUsername, $loginPassword);
	if ($loginOkay) echo 'Login Okay!'.PHP_EOL;
	else echo 'Login Failed!'.PHP_EOL;
}

/****************************************************************************
To fetch data from Microsoft Dynamics CRM 2011, we use a simpe query language
known as "Fetch XML".  This is a Microsoft design, which uses XML to specify 
what data is required.  There are several references available for this on 
the internet, but the simplest option is to use the "Advanced Find" tool on 
the Dynamics CRM itself, and click the "Download Fetch XML" button to get 
the exact XML used to run the query from the Advanced Find window.
The DynamicsCRM2011Connector has been designed to accept this data directly.
*****************************************************************************/
/* Example 1: Fetch all active Accounts which have at least one Ticket */
$accountQueryXML = <<<END
<fetch version="1.0" output-format="xml-platform" mapping="logical" distinct="true">
  <entity name="account">
    <attribute name="name" />
    <attribute name="primarycontactid" />
    <attribute name="telephone1" />
    <attribute name="accountid" />
    <attribute name="ownerid" />
    <order attribute="name" descending="false" />
    <filter type="and">
      <condition attribute="statecode" operator="eq" value="0" />
    </filter>
    <link-entity name="incident" from="customerid" to="accountid" alias="ab">
      <filter type="and">
        <condition attribute="ticketnumber" operator="not-null" />
      </filter>
    </link-entity>
  </entity>
</fetch>
END;

/****************************************************************************
The simplest way to fetch data from the server is using the retrieveMultiple
method with the allPages option (set by default to True).  This sends the 
specified Fetch XML to the server, parses it into a useable PHP stdClass 
Object, and checks if more than one page of data is returned (the CRM has 
a limit of returning 5000 records at one time)
If there are multiple pages of data, the Object automatically fetches all 
the pages until no more data remains to be fetched, and assembles it all
into the one stdClass Object for ease of access.
*****************************************************************************/
if (!defined('SKIP_DEMO2')) {
	echo date('Y-m-d H:i:s')."\tFetching Account data... ";
	$accountData = $crmConnector->retrieveMultiple($accountQueryXML);
	echo 'Done'.PHP_EOL;
	foreach ($accountData->Entities as $account) {
		/* Fetch fields individually */
		echo "\t".'Account <'.$account->name.'> has ID {'.$account->accountid.'}'.PHP_EOL;
		/* Use specific toString of Contact */
		echo "\t\t".'Primary Contact is: '.$account->PrimaryContactId.PHP_EOL;
		/* Use automatic toString of SystemUser */
		echo "\t\t".'Owner is: '.$account->OwnerId.PHP_EOL;
	}
}

/* Example 2: Fetch all open Cases, together with the Account name */
/* Note the "count" attribute - this is optional, and allows you to limit the 
 * number of records that are returned at a time (records per page).
 * The maximum value is 5000 records per page (default if no count is specified).
 * Whatever the value is set to, it is always possible to return the full 
 * dataset, it will just take multiple requests to get each page.
 * This can be useful though, for example to get the Top 10 records etc.
 *
 * In this example, we use it purely to ensure multiple pages are returned.
 */
$caseQueryXML = <<<END
<fetch version="1.0" count="5" output-format="xml-platform" mapping="logical" distinct="false">
  <entity name="incident">
    <attribute name="title" />
    <attribute name="ticketnumber" />
    <attribute name="createdon" />
    <attribute name="incidentid" />
    <order attribute="ticketnumber" descending="false" />
    <filter type="and">
      <condition attribute="statecode" operator="neq" value="0" />
    </filter>
    <link-entity name="account" from="accountid" to="customerid" visible="false" link-type="outer" alias="case_account">
      <attribute name="name" />
      <attribute name="telephone1" />
      <attribute name="createdon" />
    </link-entity>
  </entity>
</fetch>
END;

/****************************************************************************
A more advanced method is to fetch the data one page at a time.  This might
be useful if you are only interested in the first page (e.g Top N results),
or if you want to provide the data page at a time to the user in an 
interactive display.
Here, we specifically set "allPages" false, and have to examine the returned
stdClass Object to determine if there is more data remaining or not.
If more data remains, we call the retrieveMultiple function again, this time
with the details from the "PagingCookie" to show the CRM which page we
are after.
Note that there are many more elegant ways to implement this code - this 
example is structured to make it as clear as possible!
*****************************************************************************/
if (!defined('SKIP_DEMO3')) {
	echo date('Y-m-d H:i:s')."\tFetching First Page of Case data... ";
	$caseData = $crmConnector->retrieveMultiple($caseQueryXML, FALSE);
	echo 'Done'.PHP_EOL;
	/* Loop through the cases we found */
	foreach ($caseData->Entities as $caseItem) {
		echo 'Case '.$caseItem->ticketnumber
				.' is from Account '.$caseItem->case_account->name
				.' ('.$caseItem->case_account->telephone1.')'.PHP_EOL;
	}
	/* Check if there are any more Cases to return */
	while ($caseData->MoreRecords == TRUE) {
		/* Fetch the next set of data */
		echo date('Y-m-d H:i:s')."\tFetching Next Page of Case data... ";
		$caseData = $crmConnector->retrieveMultiple($caseQueryXML, FALSE, $caseData->PagingCookie);
		echo 'Done'.PHP_EOL;
		/* Loop through the cases we found */
		foreach ($caseData->Entities as $caseItem) {
			echo 'Case '.$caseItem->ticketnumber
					.' is from Account '.$caseItem->case_account->name
					.' ('.$caseItem->case_account->telephone1.')'.PHP_EOL;
		}
	}
}

/****************************************************************************
The retrieveMultiple method is designed to return the data in as useable way
as possible, however, it is possible that the function will not be able to 
parse the returned data correctly, and in that case, it might be necessary 
to examine the data returned by the CRM directly.
The retrieveMultipleRaw method allows you to get the XML returned directly,
without any attempts at parsing.
This function does not allow an automatic fetch of all pages, only the 
manual selection of each page using the PagingCookie information - and you 
will need to parse the XML to determine if there are More Records, and what 
the Paging Cookie is actually set to.

If you encounter a problem using the normal method, please send Nick Price
a copy of your Fetch XML query, the Raw data returned, and the parsed data
returned - e.g. output from var_dump($accountData)
*****************************************************************************/
if (!defined('SKIP_DEMO4')) {
	echo date('Y-m-d H:i:s')."\tFetching Raw Account data... ";
	$rawAccountData = $crmConnector->retrieveMultipleRaw($accountQueryXML);
	echo 'Done'.PHP_EOL;
	echo PHP_EOL.'Start of Raw XML data...'.PHP_EOL;
	echo $rawAccountData;
	echo PHP_EOL.'End of Raw XML data...'.PHP_EOL;
	
	/* One step back from raw XML is to get a simplified stdClass containing the data
	 * This is slightly faster than getting the full Entity structure, but doesn't
	 * have quite as advanced parsing capabilities
	 */
	
	echo date('Y-m-d H:i:s')."\tFetching stdClass Case data... ";
	$stdClassCaseData = $crmConnector->retrieveMultipleSimple($caseQueryXML, FALSE, NULL, 2);
	echo 'Done'.PHP_EOL;
	echo PHP_EOL.'Start of stdClass data...'.PHP_EOL;
	print_r($stdClassCaseData);
	echo PHP_EOL.'End of stdClass data...'.PHP_EOL;
}

/* Example 3: Getting RecordChangeHistory details */
if (!defined('SKIP_DEMO5')) {
	/* First, we run a query to get the ID of a particular Case */
	$caseIdQueryXML = <<<END
<fetch version="1.0" output-format="xml-platform" mapping="logical" distinct="false">
  <entity name="incident">
    <attribute name="ticketnumber" />
    <attribute name="incidentid" />
    <order attribute="ticketnumber" descending="false" />
    <filter type="and">
      <condition attribute="ticketnumber" operator="eq" value="${demoCaseNumber}" />
    </filter>
  </entity>
</fetch>
END;
	echo date('Y-m-d H:i:s')."\tFetching Case ID for Case ${demoCaseNumber}... ";
	echo 'Done'.PHP_EOL;
	$caseIdData = $crmConnector->retrieveMultiple($caseIdQueryXML);
	/* Get the term used internally to define a "Case" */
	$caseType = $caseIdData->EntityName;
	/* Get the internal ID of the first case returned (should just be one anyway) */
	$caseId = $caseIdData->Entities[0]->incidentid;
	/* Now, get the full history of the Case */
	echo date('Y-m-d H:i:s')."\tFetching Case History data... ";
	$caseHistoryData = $crmConnector->retrieveRecordChangeHistory($caseType, $caseId);
	echo 'Done'.PHP_EOL;

	//print_r($caseHistoryData);
	//echo PHP_EOL.PHP_EOL.$caseHistoryData.PHP_EOL.PHP_EOL;

	foreach ($caseHistoryData->AuditDetails as $changeDetail) {
		if (isset($changeDetail->Values->statuscode)) {
			echo 'At '.date('Y-m-d H:i:s', $changeDetail->AuditRecord->createdon->Value).
					' the Status was changed from <'.$changeDetail->Values->statuscode->OldValue->FormattedValue.'>'.
					' to <'.$changeDetail->Values->statuscode->NewValue->FormattedValue.'>'.
					' by User <'.$changeDetail->AuditRecord->userid->Name.'>'.PHP_EOL;
		} else {
			echo 'At '.date('Y-m-d H:i:s', $changeDetail->AuditRecord->createdon->Value).
					' a change was made by User <'.$changeDetail->AuditRecord->userid->Name.'>'.
					' but the Status was not changed.'.PHP_EOL;
		}
	}
}

/* Example 4: Display the functionality available to use */
if (!defined('SKIP_DEMO6')) {
	echo 'Discovery Service actions:'.PHP_EOL;
	print_r($crmConnector->getAllDiscoverySoapActions());
	echo PHP_EOL.'Organization Service actions:'.PHP_EOL;
	print_r($crmConnector->getAllOrganizationSoapActions());
}

/* Example 5: Using the Retrieve method to get an entity by ID */
if (!defined('SKIP_DEMO7')) {
	/* First, we run a query to get the ID of a particular Case */
	$caseIdQueryXML = <<<END
<fetch version="1.0" output-format="xml-platform" mapping="logical" distinct="false">
  <entity name="incident">
    <attribute name="ticketnumber" />
    <attribute name="incidentid" />
    <order attribute="ticketnumber" descending="false" />
    <filter type="and">
      <condition attribute="ticketnumber" operator="eq" value="${demoCaseNumber}" />
    </filter>
  </entity>
</fetch>
END;
	echo date('Y-m-d H:i:s')."\tFetching Case ID for Case ${demoCaseNumber}... ";
	echo 'Done'.PHP_EOL;
	$caseIdData = $crmConnector->retrieveMultiple($caseIdQueryXML);
	/* Create a Case Entity which can be used to fetch the details */
	$case = new DynamicsCRM2011_Incident($crmConnector);
	/* Get the internal ID of the first case returned (should just be one anyway) */
	$case->ID = $caseIdData->Entities[0]->incidentid;
	/* Now, get the full details of the Case */
	echo date('Y-m-d H:i:s')."\tFetching Case data... ";
	$caseData = $crmConnector->retrieve($case, Array('incidentid', 'ticketnumber', 'createdon', 'statecode', 'responsiblecontactid', 'title'));
	echo 'Done'.PHP_EOL;
	
	echo PHP_EOL.$case.PHP_EOL;
	echo "\tTitle:       \t".$caseData->Title.PHP_EOL;
	echo "\tState:       \t".$caseData->StateCode->Label.PHP_EOL;
	echo "\tCreated On:  \t".date('Y-m-d H:i:s', $caseData->CreatedOn).PHP_EOL;
	echo "\tContact Name:\t".$caseData->ResponsibleContactIdName.PHP_EOL;
	echo "\tContact:     \t".$caseData->ResponsibleContactId.PHP_EOL;
}

/* Example 6: Get the full description of the Incident Entity */
if (!defined('SKIP_DEMO8')) {
	echo date('Y-m-d H:i:s')."\tFetching details of Cases data... ";
	$caseEntityData = $crmConnector->retrieveEntity('incident', NULL, 'Entity Attributes');
	echo 'Done'.PHP_EOL;
	//echo PHP_EOL.'Start of XML Object data...'.PHP_EOL;
	//echo $caseEntityData->asXML();
	//echo PHP_EOL.'End of XML Object data...'.PHP_EOL;
	foreach ($caseEntityData->children('http://schemas.microsoft.com/xrm/2011/Metadata')->Attributes[0]->AttributeMetadata as $attribute) {
		echo 'Attribute '.(String)$attribute->SchemaName.' ('.(String)$attribute->DisplayName->children('http://schemas.microsoft.com/xrm/2011/Contracts')->UserLocalizedLabel->Label.') '
			.'is of Type '.(String)$attribute->AttributeType.PHP_EOL;
		$attributeTypes[(String)$attribute->AttributeType][] = (String)$attribute->SchemaName;
	}
}

/* Example 7: Creating a new Case */
if (!defined('SKIP_DEMO9')) {
	/* Find the ID for a Contact & Account */
	$contactQueryXML = <<<END
<fetch version="1.0" output-format="xml-platform" mapping="logical" distinct="false">
  <entity name="contact">
    <attribute name="fullname" />
    <attribute name="emailaddress1" />
    <attribute name="contactid" />
    <order attribute="fullname" descending="false" />
    <filter type="and">
      <condition attribute="emailaddress1" operator="eq" value="${demoContactEmail}" />
    </filter>
    <link-entity name="account" from="accountid" to="parentcustomerid" visible="false" link-type="outer" alias="parentcustomer">
      <attribute name="accountid" />
      <attribute name="name" />
    </link-entity>
  </entity>
</fetch>
END;
	echo date('Y-m-d H:i:s')."\tFetching Contact & Account ID for Contact ${demoContactEmail}... ";
	$contactIdData = $crmConnector->retrieveMultiple($contactQueryXML);
	echo 'Done'.PHP_EOL;
	$contactId = $contactIdData->Entities[0]->contactid;
	$accountId = $contactIdData->Entities[0]->parentcustomer->accountid;
	
	/* Create a template Account, using this ID */
	$account = new DynamicsCRM2011_Account($crmConnector);
	$account->ID = $accountId;
	
	/* Create a template Contact, using the Contact ID */
	$contact = DynamicsCRM2011_Entity::fromLogicalName($crmConnector, 'contact');
	$contact->ID = $contactId;
	
	/* Create a new Case, linked to this Account */
	$case = new DynamicsCRM2011_Incident($crmConnector);
	$case->Title = 'Test Case - Using DynamicsCRM2011 from PHP';
	$case->CustomerID = $account;
	$case->ResponsibleContactId = $contact;
	$case->Description = 'This is the case Description, it\'s supposedly a "Memo" field, but actually just treated as a string!';
	/* Before Creating the Case, check if any Mandatory fields are missing */
 	$missingFields = Array();
 	if (!$case->checkMandatories($missingFields)) {
 		echo 'Missing Mandatory Fields: '.PHP_EOL;
 		print_r($missingFields);
 		echo PHP_EOL.PHP_EOL;
 	} 
	/* Note that Dynamics CRM 2011 often recovers from missing Mandatory fields, so
	 * we can continue and try and create the case anyway - in fact, the only 
	 * truly required fields seem to be Title and CustomerId
	 */
	echo date('Y-m-d H:i:s')."\tCreating a new Case... ";
	$caseId = $crmConnector->create($case);
	echo 'Done'.PHP_EOL;
	echo date('Y-m-d H:i:s')."\t\tCase is now: ".$case.PHP_EOL;
	
	echo date('Y-m-d H:i:s')."\tUpdating the Case... ";
	$case->Title = 'Test Case - Using DynamicsCRM2011 from PHP - Updated';
	$case->StatusCode = 1;
	$case->PriorityCode = 'Low';
	$updated = $crmConnector->update($case);
	echo 'Done'.PHP_EOL;
	
	/* Fetch the case using the logicalName & ID */
	echo date('Y-m-d H:i:s')."\tRetrieving the updated Case... ";
	$case = $crmConnector->retrieve($case);
	echo 'Done'.PHP_EOL;
	
	echo PHP_EOL.'Case Details: '.$case.PHP_EOL;
	echo "\tTitle:       \t".$case->Title.PHP_EOL;
	echo "\tStatus:       \t".$case->StatusCode->Label.PHP_EOL;
	echo "\tPriority:       \t".$case->PriorityCode->Label.PHP_EOL;
	echo "\tCreated On:  \t".date('Y-m-d H:i:s P', $case->CreatedOn).PHP_EOL;
	echo "\tContact Name:\t".$case->ResponsibleContactIdName.PHP_EOL;
	echo "\tContact:     \t".$case->ResponsibleContactId.PHP_EOL;
	echo PHP_EOL;
	$case->printDetails(true);
	echo PHP_EOL.PHP_EOL;
	
	/* Delete the case from the CRM */
	echo date('Y-m-d H:i:s')."\tDeleting the test Case... ";
	$deleted = $crmConnector->delete($case);
	echo 'Done'.PHP_EOL;
	print_r($deleted);
	
}

/* Example 10: Outer Join in FetchXML */
if (!defined('SKIP_DEMO10')) {
	/* This query fetches all the Accounts, and any Incidents linked to those accounts (if they exist).
	 * If there is no Incident linked to the Account, the Account details are still returned
	 */
	$accountCaseQuery = <<<END
<fetch version="1.0" output-format="xml-platform" mapping="logical" distinct="true" count="50">
  <entity name="account">
    <attribute name="name" />
    <attribute name="primarycontactid" />
    <attribute name="telephone1" />
    <attribute name="accountid" />
    <attribute name="ownerid" />
    <order attribute="name" descending="false" />
    <filter type="and">
      <condition attribute="statecode" operator="eq" value="0" />
      <condition attribute="customertypecodename" operator="eq" value="Customer" />
      <condition attribute="name" operator="eq" value="Midlands Co-operative Society" />
    </filter>
    <link-entity name="incident" from="customerid" to="accountid" alias="incidents" link-type="outer">
      <attribute name="incidentid" />
      <attribute name="ticketnumber" />
      <order attribute="createdon" descending="false" />
      <filter type="and">
        <condition attribute="ticketnumber" operator="not-null" />
      </filter>
    </link-entity>
  </entity>
</fetch>
END;
	
	/* Start at the first page, with no Paging Cookie */
	$pagingCookie = NULL;
	$pageNo = 0;
	do {
		/* Increment the page number */
		$pageNo++;
		/* Fetch a page of data */
		echo date('Y-m-d H:i:s')."\tFetching Page ".$pageNo." of Account & Case data... ";
		$accountCaseData = $crmConnector->retrieveMultiple($accountCaseQuery, FALSE, $pagingCookie);
		echo 'Done ('.$accountCaseData->Count.' records)'.PHP_EOL;
		
		/* Loop through the Accounts & Cases returned */
		foreach ($accountCaseData->Entities as $accountItem) {
			/* Check if this Account has an Incident */
			if (isset($accountItem->Incidents)) {
				echo "\t\tAccount ".$accountItem->DisplayName." has Incident: ".$accountItem->Incidents->TicketNumber.PHP_EOL;
			} else {
				echo "\t\tAccount ".$accountItem->DisplayName." has no Incidents".PHP_EOL;
			}
		}
	
		/* Get the PagingCookie */
		$pagingCookie = $accountCaseData->PagingCookie;
	} while ($accountCaseData->MoreRecords);
	
}

?>