<?php

require_once 'DynamicsCRM2011.php';

class DynamicsCRM2011_KBArticle extends DynamicsCRM2011_Entity {
	protected $entityLogicalName = 'kbarticle';
	protected $entityDisplayName = 'title';
	
	const STATE_DRAFT = 1;
	const STATE_UNAPPROVED = 2;
	const STATE_PUBLISHED = 3;
	
	const STATUS_DRAFT = 1;
	const STATUS_UNAPPROVED = 2;
	const STATUS_PUBLISHED = 3;

	public function __toString() {
		$description = $this->Number.': '.$this->Title.' <'.$this->ID.'>';
		return $description;
	}
	
	/**
	 * Update the KB Article status from Draft to Unapproved
	 * 
	 * @param DynamicsCRM2011_Connector $crmConnector
	 */
	public function submit(DynamicsCRM2011_Connector $crmConnector) {
		return $crmConnector->setState($this, DynamicsCRM2011_KBArticle::STATE_UNAPPROVED, DynamicsCRM2011_KBArticle::STATUS_UNAPPROVED);
	}
	
	/**
	 * Update the KB Article status from Unapproved to Published
	 * 
	 * @param DynamicsCRM2011_Connector $crmConnector
	 */
	public function approve(DynamicsCRM2011_Connector $crmConnector) {
		return $crmConnector->setState($this, DynamicsCRM2011_KBArticle::STATE_PUBLISHED, DynamicsCRM2011_KBArticle::STATUS_PUBLISHED);
	}
	
	/**
	 * Update the KB Article status from Unapproved to Draft
	 * 
	 * @param DynamicsCRM2011_Connector $crmConnector
	 */
	public function reject(DynamicsCRM2011_Connector $crmConnector) {
		return $crmConnector->setState($this, DynamicsCRM2011_KBArticle::STATE_DRAFT, DynamicsCRM2011_KBArticle::STATUS_DRAFT);
	}
	
	/**
	 * Update the KB Article status from Published to Unapproved
	 * 
	 * @param DynamicsCRM2011_Connector $crmConnector
	 */
	public function unpublish(DynamicsCRM2011_Connector $crmConnector) {
		return $crmConnector->setState($this, DynamicsCRM2011_KBArticle::STATE_UNAPPROVED, DynamicsCRM2011_KBArticle::STATUS_UNAPPROVED);
	}
}

?>