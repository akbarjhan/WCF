<?php
namespace wcf\acp\form;
use wcf\data\page\menu\item\PageMenuItem;
use wcf\data\page\menu\item\PageMenuItemAction;
use wcf\system\exception\IllegalLinkException;
use wcf\system\language\I18nHandler;
use wcf\system\WCF;

/**
 * Shows the page menu item edit form.
 * 
 * @author	Alexander Ebert
 * @copyright	2001-2012 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf
 * @subpackage	acp.form
 * @category	Community Framework
 */
class PageMenuItemEditForm extends PageMenuItemAddForm {
	/**
	 * @see	wcf\acp\form\ACPForm::$activeMenuItem
	 */
	public $activeMenuItem = 'wcf.acp.menu.link.pageMenu';
	
	/**
	 * page menu item object
	 * @var	wcf\data\page\menu\item\PageMenuItem
	 */
	public $menuItem = null;
	
	/**
	 * menu item id
	 * @var	integer
	 */
	public $menuItemID = 0;
	
	/**
	 * @see	wcf\page\IPage::readParameters()
	 */
	public function readParameters() {
		if (isset($_REQUEST['id'])) $this->menuItemID = intval($_REQUEST['id']);
		$this->menuItem = new PageMenuItem($this->menuItemID);
		if (!$this->menuItem->menuItemID) {
			throw new IllegalLinkException();
		}
		
		parent::readParameters();
	}
	
	/**
	 * @see	wcf\acp\form\PageMenuItemAddForm::initAvailableParentMenuItems()
	 */
	protected function initAvailableParentMenuItems() {
		parent::initAvailableParentMenuItems();
		
		// remove current item as valid parent menu item
		$this->availableParentMenuItems->getConditionBuilder()->add("page_menu_item.menuItem <> ?", array($this->menuItem->menuItem));
	}
	
	/**
	 * @see	wcf\page\IPage::readData()
	 */
	public function readData() {
		parent::readData();
		
		I18nHandler::getInstance()->setOptions('menuItemLink', PACKAGE_ID, $this->menuItem->menuItemLink, 'wcf.page.menuItemLink\d+');
		I18nHandler::getInstance()->setOptions('pageMenuItem', PACKAGE_ID, $this->menuItem->menuItem, 'wcf.page.menuItem\d+');
		
		if (empty($_POST)) {
			$this->isDisabled = ($this->menuItem->isDisabled) ? true : false;
			$this->isLandingPage = ($this->menuItem->isLandingPage) ? true : false;
			$this->menuPosition = $this->menuItem->menuPosition;
			$this->newWindow = ($this->menuItem->newWindow) ? true : false;
			$this->pageMenuItem = $this->menuItem->menuItem;
			$this->parentMenuItem = $this->menuItem->parentMenuItem;
			$this->showOrder = $this->menuItem->showOrder;
		}
	}
	
	/**
	 * @see	wcf\form\IForm::save()
	 */
	public function save() {
		ACPForm::save();
		
		// save menu item
		I18nHandler::getInstance()->save('pageMenuItem', $this->menuItem->menuItem, 'wcf.page');
		
		// save menu item link
		$this->menuItemLink = 'wcf.page.menuItemLink'.$this->menuItem->menuItemID;
		if (I18nHandler::getInstance()->isPlainValue('menuItemLink')) {
			I18nHandler::getInstance()->remove($this->menuItemLink);
			$this->menuItemLink= I18nHandler::getInstance()->getValue('menuItemLink');
		}
		else {
			I18nHandler::getInstance()->save('menuItemLink', $this->menuItemLink, 'wcf.page');
		}
		
		// save menu item
		$this->objectAction = new PageMenuItemAction(array($this->menuItem), 'update', array('data' => array(
			'isDisabled' => ($this->isDisabled) ? 1 : 0,
			'isLandingPage' => ($this->isLandingPage) ? 1 : 0,
			'menuItemLink' => $this->menuItemLink,
			'newWindow' => ($this->newWindow) ? 1 : 0,
			'parentMenuItem' => ($this->menuItem->menuPosition == 'header' ? $this->parentMenuItem : ''),
			'showOrder' => $this->showOrder
		)));
		$this->objectAction->executeAction();
		
		$this->saved();
		
		WCF::getTPL()->assign('success', true);
	}
	
	/**
	 * @see	wcf\page\IPage::assignVariables()
	 */
	public function assignVariables() {
		parent::assignVariables();
		
		I18nHandler::getInstance()->assignVariables(!empty($_POST));
		
		WCF::getTPL()->assign(array(
			'action' => 'edit',
			'menuItem' => $this->menuItem,
			'menuItemID' => $this->menuItemID
		));
	}
}