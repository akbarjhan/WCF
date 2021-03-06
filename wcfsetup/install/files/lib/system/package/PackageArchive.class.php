<?php
namespace wcf\system\package;
use wcf\data\package\Package;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\exception\SystemException;
use wcf\system\io\Tar;
use wcf\system\WCF;
use wcf\util\DateUtil;
use wcf\util\FileUtil;
use wcf\util\XML;

/**
 * Represents the archive of a package.
 * 
 * @author	Marcel Werk
 * @copyright	2001-2013 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf
 * @subpackage	system.package
 * @category	Community Framework
 */
class PackageArchive {
	/**
	 * path to package archive
	 * @var	string
	 */
	protected $archive = null;
	
	/**
	 * package object of an existing package
	 * @var	\wcf\data\package\Package
	 */
	protected $package = null;
	
	/**
	 * tar archive object
	 * @var	\wcf\system\io\Tar
	 */
	protected $tar = null;
	
	/**
	 * general package information
	 * @var	array
	 */
	protected $packageInfo = array();
	
	/**
	 * author information
	 * @var	array
	 */
	protected $authorInfo = array();
	
	/**
	 * list of requirements
	 * @var	array
	 */
	protected $requirements = array();
	
	/**
	 * list of optional packages
	 * @var	array
	 */
	protected $optionals = array();
	
	/**
	 * list of excluded packages
	 * @var	array
	 */
	protected $excludedPackages = array();
	
	/**
	 * list of instructions
	 * @var	array<array>
	 */
	protected $instructions = array(
		'install' => array(),
		'update' => array()
	);
	
	/**
	 * list of php requirements
	 * @var	array<array>
	 */
	protected $phpRequirements = array();
	
	/**
	 * default name of the package.xml file
	 * @var	string
	 */
	const INFO_FILE = 'package.xml';
	
	/**
	 * Creates a new PackageArchive object.
	 * 
	 * @param	string		$archive
	 * @param	Package		$package
	 */
	public function __construct($archive, Package $package = null) {
		$this->archive = $archive;	// be careful: this is a string within this class, 
						// but an object in the packageStartInstallForm.class!
		$this->package = $package;
	}
	
	/**
	 * Sets associated package object.
	 * 
	 * @param	\wcf\data\package\Package	$package
	 */
	public function setPackage(Package $package) {
		$this->package = $package;
	}
	
	/**
	 * Returns the name of the package archive.
	 * 
	 * @return	string
	 */
	public function getArchive() {
		return $this->archive;
	}
	
	/**
	 * Returns the object of the package archive.
	 * 
	 * @return	\wcf\system\io\Tar
	 */
	public function getTar() {
		return $this->tar;
	}
	
	/**
	 * Opens the package archive and reads package information.
	 */
	public function openArchive() {
		// check whether archive exists and is a TAR archive
		if (!file_exists($this->archive)) {
			throw new SystemException("unable to find package file '".$this->archive."'");
		}
		
		// open archive and read package information
		$this->tar = new Tar($this->archive);
		$this->readPackageInfo();
	}
	
	/**
	 * Extracts information about this package (parses package.xml).
	 */
	protected function readPackageInfo() {
		// search package.xml in package archive
		// throw error message if not found
		if ($this->tar->getIndexByFilename(self::INFO_FILE) === false) {
			throw new SystemException("package information file '".(self::INFO_FILE)."' not found in '".$this->archive."'");
		}
		
		// extract package.xml, parse XML
		// and compile an array with XML::getElementTree()
		$xml = new XML();
		try {
			$xml->loadXML(self::INFO_FILE, $this->tar->extractToString(self::INFO_FILE));
		}
		catch (\Exception $e) { // bugfix to avoid file caching problems
			$xml->loadXML(self::INFO_FILE, $this->tar->extractToString(self::INFO_FILE));
		}
		
		// parse xml
		$xpath = $xml->xpath();
		$package = $xpath->query('/ns:package')->item(0);
		
		// package name
		$packageName = $package->getAttribute('name');
		if (!Package::isValidPackageName($packageName)) {
			// package name is not a valid package identifier
			throw new SystemException("'".$packageName."' is not a valid package name.");
		}
		
		$this->packageInfo['name'] = $packageName;
		
		// get package information
		$packageInformation = $xpath->query('./ns:packageinformation', $package)->item(0);
		$elements = $xpath->query('child::*', $packageInformation);
		foreach ($elements as $element) {
			switch ($element->tagName) {
				case 'packagename':
				case 'packagedescription':
				case 'readme':
				case 'license':
					if (!isset($this->packageInfo[$element->tagName])) $this->packageInfo[$element->tagName] = array();
					
					$languageCode = 'default';
					if ($element->hasAttribute('language')) {
						$languageCode = $element->getAttribute('language');
					}
					
					// fix case-sensitive names
					$name = $element->tagName;
					if ($name == 'packagename') $name = 'packageName';
					else if ($name == 'packagedescription') $name = 'packageDescription';
					
					$this->packageInfo[$name][$languageCode] = $element->nodeValue;
				break;
				
				case 'isapplication':
					$this->packageInfo['isApplication'] = intval($element->nodeValue);
				break;
				
				case 'packageurl':
					$this->packageInfo['packageURL'] = $element->nodeValue;
				break;
				
				case 'version':
					if (!Package::isValidVersion($element->nodeValue)) {
						throw new SystemException("package version '".$element->nodeValue."' is invalid");
					}
					
					$this->packageInfo['version'] = $element->nodeValue;
				break;
				
				case 'date':
					DateUtil::validateDate($element->nodeValue);
					
					$this->packageInfo['date'] = @strtotime($element->nodeValue);
				break;
			}
		}
		
		// get author information
		$authorInformation = $xpath->query('./ns:authorinformation', $package)->item(0);
		$elements = $xpath->query('child::*', $authorInformation);
		foreach ($elements as $element) {
			$tagName = ($element->tagName == 'authorurl') ? 'authorURL' : $element->tagName;
			$this->authorInfo[$tagName] = $element->nodeValue;
		}
		
		// get required packages
		$elements = $xpath->query('child::ns:requiredpackages/ns:requiredpackage', $package);
		foreach ($elements as $element) {
			if (!Package::isValidPackageName($element->nodeValue)) {
				throw new SystemException("'".$element->nodeValue."' is not a valid package name.");
			}
			
			// read attributes
			$data = array('name' => $element->nodeValue);
			$attributes = $xpath->query('attribute::*', $element);
			foreach ($attributes as $attribute) {
				$data[$attribute->name] = $attribute->value;
			}
			
			$this->requirements[$element->nodeValue] = $data;
		}
		
		// get optional packages
		$elements = $xpath->query('child::ns:optionalpackages/ns:optionalpackage', $package);
		foreach ($elements as $element) {
			if (!Package::isValidPackageName($element->nodeValue)) {
				throw new SystemException("'".$element->nodeValue."' is not a valid package name.");
			}
			
			// read attributes
			$data = array('name' => $element->nodeValue);
			$attributes = $xpath->query('attribute::*', $element);
			foreach ($attributes as $attribute) {
				$data[$attribute->name] = $attribute->value;
			}
			
			$this->optionals[] = $data;
		}
		
		// get excluded packages
		$elements = $xpath->query('child::ns:excludedpackages/ns:excludedpackage', $package);
		foreach ($elements as $element) {
			if (!Package::isValidPackageName($element->nodeValue)) {
				throw new SystemException("'".$element->nodeValue."' is not a valid package name.");
			}
			
			// read attributes
			$data = array('name' => $element->nodeValue);
			$attributes = $xpath->query('attribute::*', $element);
			foreach ($attributes as $attribute) {
				$data[$attribute->name] = $attribute->value;
			}
			
			$this->excludedPackages[] = $data;
		}
		
		// get instructions
		$elements = $xpath->query('./ns:instructions', $package);
		foreach ($elements as $element) {
			$instructionData = array();
			$instructions = $xpath->query('./ns:instruction', $element);
			foreach ($instructions as $instruction) {
				$data = array();
				$attributes = $xpath->query('attribute::*', $instruction);
				foreach ($attributes as $attribute) {
					$data[$attribute->name] = $attribute->value;
				}
				
				$instructionData[] = array(
					'attributes' => $data,
					'pip' => $instruction->getAttribute('type'),
					'value' => $instruction->nodeValue
				);
			}
			
			$fromVersion = $element->getAttribute('fromversion');
			$type = $element->getAttribute('type');
			
			if ($type == 'install') {
				$this->instructions['install'] = $instructionData;
			}
			else {
				$this->instructions['update'][$fromVersion] = $instructionData;
			}
		}
		
		// get php requirements
		/*$requirements = $xpath->query('./ns:phprequirements', $package);
		foreach ($requirements as $requirement) {
			$elements = $xpath->query('child::*', $requirement);
			foreach ($elements as $element) {
				switch ($element->tagName) {
					case 'version':
						$this->phpRequirements['version'] = $element->nodeValue;
					break;
					
					case 'setting':
						$this->phpRequirements['settings'][$element->getAttribute('name')] = $element->nodeValue;
					break;
					
					case 'extension':
						$this->phpRequirements['extensions'][] = $element->nodeValue;
					break;
					
					case 'function':
						$this->phpRequirements['functions'][] = $element->nodeValue;
					break;
					
					case 'class':
						$this->phpRequirements['classes'][] = $element->nodeValue;
					break;
				}
			}
		}*/
		
		// add com.woltlab.wcf to package requirements
		if (!isset($this->requirements['com.woltlab.wcf']) && $this->packageInfo['name'] != 'com.woltlab.wcf') {
			$this->requirements['com.woltlab.wcf'] = array('name' => 'com.woltlab.wcf');
		}
		
		if ($this->package != null) {
			$this->filterUpdateInstructions();
		}
		
		// set default values
		if (!isset($this->packageInfo['isApplication'])) $this->packageInfo['isApplication'] = 0;
		if (!isset($this->packageInfo['packageURL'])) $this->packageInfo['packageURL'] = '';
	}
	
	/**
	 * Filters update instructions.
	 */
	protected function filterUpdateInstructions() {
		$validFromVersion = null;
		foreach ($this->instructions['update'] as $fromVersion => $update) {
			if (Package::checkFromversion($this->package->packageVersion, $fromVersion)) {
				$validFromVersion = $fromVersion;
				break;
			}
		}
		
		if ($validFromVersion === null) {
			$this->instructions['update'] = array();
		}
		else {
			$this->instructions['update'] = $this->instructions['update'][$validFromVersion];
		}
	}
	
	/**
	 * Downloads the package archive.
	 * 
	 * @return	string		path to the dowloaded file
	 */
	public function downloadArchive() {
		$prefix = 'package';
		
		// file transfer via hypertext transfer protocol.
		$this->archive = FileUtil::downloadFileFromHttp($this->archive, $prefix);
		
		// unzip tar
		$this->archive = self::unzipPackageArchive($this->archive);
		
		return $this->archive;
	}
	
	/**
	 * Closes and deletes the tar archive of this package. 
	 */
	public function deleteArchive() {
		if ($this->tar instanceof Tar) {
			$this->tar->close();
		}
		
		@unlink($this->archive);
	}
	
	/**
	 * Returns true if the package archive supports a new installation.
	 * 
	 * @return	boolean
	 */
	public function isValidInstall() {
		return !empty($this->instructions['install']);
	}
	
	/**
	 * Checks if the new package is compatible with
	 * the package that is about to be updated.
	 * 
	 * @param	\wcf\data\package\Package	$package
	 * @return	boolean				isValidUpdate
	 */
	public function isValidUpdate(Package $package = null) {
		if ($this->package === null && $package !== null) {
			$this->setPackage($package);
			
			// re-evaluate update data
			$this->filterUpdateInstructions();
		}
		
		// Check name of the installed package against the name of the update. Both must be identical.
		if ($this->packageInfo['name'] != $this->package->package) {
			return false;
		}
		
		// Check if the version number of the installed package is lower than the version number to which
		// it's about to be updated.
		if (Package::compareVersion($this->packageInfo['version'], $this->package->packageVersion) != 1) {
			return false;
		}
		
		// Check if the package provides an instructions block for the update from the installed package version
		if (empty($this->instructions['update'])) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Checks if the current package is already installed, as it is not
	 * possible to install non-applications multiple times within the
	 * same environment.
	 * 
	 * @return	boolean
	 */
	public function isAlreadyInstalled() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	wcf".WCF_N."_package
			WHERE	package = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array($this->packageInfo['name']));
		$row = $statement->fetchArray();
		
		return ($row['count'] > 0) ? true : false;
	}
	
	/**
	 * Returns true if the package is an application and has an unique abbrevation.
	 * 
	 * @return	boolean
	 */
	public function hasUniqueAbbreviation() {
		if (!$this->packageInfo['isApplication']) {
			return true;
		}
		
		$sql = "SELECT	COUNT(*)
			FROM	wcf".WCF_N."_package
			WHERE	isApplication = ?
				AND package LIKE ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array(
			1,
			'%.'.Package::getAbbreviation($this->packageInfo['name'])
		));
		
		return $statement->fetchColumn();
	}
	
	/**
	 * Returns information about the author of this package archive.
	 * 
	 * @param	string		$name		name of the requested information
	 * @return	string
	 */
	public function getAuthorInfo($name) {
		if (isset($this->authorInfo[$name])) return $this->authorInfo[$name];
		return null;
	}
	
	/**
	 * Returns information about this package.
	 * 
	 * @param	string		$name		name of the requested information
	 * @return	mixed
	 */
	public function getPackageInfo($name) {
		if (isset($this->packageInfo[$name])) return $this->packageInfo[$name];
		return null;
	}
	
	/**
	 * Returns a localized information about this package.
	 * 
	 * @param	string		$name
	 * @return	string
	 */
	public function getLocalizedPackageInfo($name) {
		if (isset($this->packageInfo[$name][WCF::getLanguage()->getFixedLanguageCode()])) {
			return $this->packageInfo[$name][WCF::getLanguage()->getFixedLanguageCode()];
		}
		else if (isset($this->packageInfo[$name]['default'])) {
			return $this->packageInfo[$name]['default'];
		}
		
		if (!empty($this->packageInfo[$name])) {
			return reset($this->packageInfo[$name]);
		}
		
		return '';
	}
	
	/**
	 * Returns a list of all requirements of this package.
	 * 
	 * @return	array
	 */
	public function getRequirements() {
		return $this->requirements;
	}
	
	/**
	 * Returns a list of all delivered optional packages of this package.
	 * 
	 * @return	array
	 */
	public function getOptionals() {
		return $this->optionals;
	}
	
	/**
	 * Returns a list of excluded packages.
	 * 
	 * @return	array
	 */
	public function getExcludedPackages() {
		return $this->excludedPackages;
	}
	
	/**
	 * Returns the package installation instructions.
	 * 
	 * @return	array
	 */
	public function getInstallInstructions() {
		return $this->instructions['install'];
	}
	
	/**
	 * Returns the package update instructions.
	 * 
	 * @return	array
	 */
	public function getUpdateInstructions() {
		return $this->instructions['update'];
	}
	
	/**
	 * Checks which package requirements do already exist in right version.
	 * Returns a list with all existing requirements.
	 * 
	 * @return	array
	 */
	public function getAllExistingRequirements() {
		$existingRequirements = array();
		$existingPackages = array();
		if ($this->package !== null) {
			$sql = "SELECT		package.*
				FROM		wcf".WCF_N."_package_requirement requirement
				LEFT JOIN	wcf".WCF_N."_package package
				ON		(package.packageID = requirement.requirement)
				WHERE		requirement.packageID = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute(array($this->package->packageID));
			while ($row = $statement->fetchArray()) {
				$existingRequirements[$row['package']] = $row;
			}
		}
		
		// build sql
		$packageNames = array();
		$requirements = $this->getRequirements();
		foreach ($requirements as $requirement) {
			if (isset($existingRequirements[$requirement['name']])) {
				$existingPackages[$requirement['name']] = array();
				$existingPackages[$requirement['name']][$existingRequirements[$requirement['name']]['packageID']] = $existingRequirements[$requirement['name']];
			}
			else {
				$packageNames[] = $requirement['name'];
			}
		}
		
		// check whether the required packages do already exist
		if (!empty($packageNames)) {
			$conditions = new PreparedStatementConditionBuilder();
			$conditions->add("package.package IN (?)", array($packageNames));
			
			$sql = "SELECT	package.*
				FROM	wcf".WCF_N."_package package
				".$conditions;
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($conditions->getParameters());
			while ($row = $statement->fetchArray()) {
				// check required package version
				if (isset($requirements[$row['package']]['minversion']) && Package::compareVersion($row['packageVersion'], $requirements[$row['package']]['minversion']) == -1) {
					continue;
				}
				
				if (!isset($existingPackages[$row['package']])) {
					$existingPackages[$row['package']] = array();
				}
				
				$existingPackages[$row['package']][$row['packageID']] = $row;
			}
		}
		
		return $existingPackages;
	}
	
	/**
	 * Checks which package requirements do already exist in database.
	 * Returns a list with the existing requirements.
	 * 
	 * @return	array
	 */
	public function getExistingRequirements() {
		// build sql
		$packageNames = array();
		foreach ($this->requirements as $requirement) {
			$packageNames[] = $requirement['name'];
		}
		
		// check whether the required packages do already exist
		$existingPackages = array();
		if (!empty($packageNames)) {
			$conditions = new PreparedStatementConditionBuilder();
			$conditions->add("package IN (?)", array($packageNames));
			
			$sql = "SELECT	*
				FROM	wcf".WCF_N."_package
				".$conditions;
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($conditions->getParameters());
			while ($row = $statement->fetchArray()) {
				if (!isset($existingPackages[$row['package']])) {
					$existingPackages[$row['package']] = array();
				}
				
				$existingPackages[$row['package']][$row['packageVersion']] = $row;
			}
			
			// sort multiple packages by version number
			foreach ($existingPackages as $packageName => $instances) {
				uksort($instances, array('wcf\data\package\Package', 'compareVersion'));
				
				// get package with highest version number (get last package)
				$existingPackages[$packageName] = array_pop($instances);	
			}
		}
		
		return $existingPackages;
	}
	
	/**
	 * Returns a list of all open requirements of this package.
	 * 
	 * @return	array
	 */
	public function getOpenRequirements() {
		// get all existing requirements
		$existingPackages = $this->getExistingRequirements();
		
		// check for open requirements
		$openRequirements = array();
		foreach ($this->requirements as $requirement) {
			if (isset($existingPackages[$requirement['name']])) {
				// package does already exist
				// maybe an update is necessary
				if (isset($requirement['minversion'])) {
					if (Package::compareVersion($existingPackages[$requirement['name']]['packageVersion'], $requirement['minversion']) >= 0) {
						// package does already exist in needed version
						// skip installation of requirement
						continue;
					}
					else {
						$requirement['existingVersion'] = $existingPackages[$requirement['name']]['packageVersion'];
					}
				}
				else {
					continue;
				}
				
				$requirement['packageID'] = $existingPackages[$requirement['name']]['packageID'];
				$requirement['action'] = 'update';
			}
			else {
				// package does not exist
				// new installation is necessary
				$requirement['packageID'] = 0;
				$requirement['action'] = 'install';
			}
			
			$openRequirements[$requirement['name']] = $requirement;
		}
		
		return $openRequirements;
	}
	
	/**
	 * Extracts the requested file in the package archive to the temp folder
	 * and returns the path to the extracted file.
	 * 
	 * @param	string		$filename
	 * @param	string		$tempPrefix
	 * @return	string
	 */
	public function extractTar($filename, $tempPrefix = 'package_') {
		// search the requested tar archive in our package archive.
		// throw error message if not found.
		if (($fileIndex = $this->tar->getIndexByFilename($filename)) === false) {
			throw new SystemException("tar archive '".$filename."' not found in '".$this->archive."'.");
		}
		
		// requested tar archive was found
		$fileInfo = $this->tar->getFileInfo($fileIndex);
		$filename = FileUtil::getTemporaryFilename($tempPrefix, preg_replace('!^.*?(\.(?:tar\.gz|tgz|tar))$!i', '\\1', $fileInfo['filename']));
		$this->tar->extract($fileIndex, $filename);
		
		return $filename;
	}
	
	/**
	 * Unzips compressed package archives and returns the temporary file name.
	 * 
	 * @param	string		$archive	filename
	 * @return	string
	 */
	public static function unzipPackageArchive($archive) {
		if (!FileUtil::isURL($archive)) {
			$tar = new Tar($archive);
			$tar->close();
			if ($tar->isZipped()) {
				$tmpName = FileUtil::getTemporaryFilename('package_');
				if (FileUtil::uncompressFile($archive, $tmpName)) {
					return $tmpName;
				}
			}
		}
		
		return $archive;
	}
	
	/**
	 * Returns a list of packages which exclude this package.
	 * 
	 * @return	array<\wcf\data\package\Package>
	 */
	public function getConflictedExcludingPackages() {
		$conflictedPackages = array();
		$sql = "SELECT		package.*, package_exclusion.*
			FROM		wcf".WCF_N."_package_exclusion package_exclusion
			LEFT JOIN	wcf".WCF_N."_package package
			ON		(package.packageID = package_exclusion.packageID)
			WHERE		excludedPackage = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array($this->packageInfo['name']));
		while ($row = $statement->fetchArray()) {
			if (!empty($row['excludedPackageVersion'])) {
				if (Package::compareVersion($this->packageInfo['version'], $row['excludedPackageVersion'], '<')) {
					continue;
				}
			}
			
			$conflictedPackages[$row['packageID']] = new Package(null, $row);
		}
		
		return $conflictedPackages;
	}
	
	/**
	 * Returns a list of packages which are excluded by this package.
	 * 
	 * @return	array<\wcf\data\package\Package>
	 */
	public function getConflictedExcludedPackages() {
		$conflictedPackages = array();
		if (!empty($this->excludedPackages)) {
			$excludedPackages = array();
			foreach ($this->excludedPackages as $excludedPackageData) {
				$excludedPackages[$excludedPackageData['name']] = $excludedPackageData['version'];
			}
			
			$conditions = new PreparedStatementConditionBuilder();
			$conditions->add("package IN (?)", array(array_keys($excludedPackages)));
			
			$sql = "SELECT	*
				FROM	wcf".WCF_N."_package
				".$conditions;
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($conditions->getParameters());
			while ($row = $statement->fetchArray()) {
				if (!empty($excludedPackages[$row['package']])) {
					if (Package::compareVersion($row['packageVersion'], $excludedPackages[$row['package']], '<')) {
						continue;
					}
					$row['excludedPackageVersion'] = $excludedPackages[$row['package']];
				}
				
				$conflictedPackages[$row['packageID']] = new Package(null, $row);
			}
		}
		
		return $conflictedPackages;
	}
	
	/**
	 * Returns a list of instructions for installation or update.
	 * 
	 * @param	string		$type
	 * @return	array
	 */
	public function getInstructions($type) {
		if (isset($this->instructions[$type])) {
			return $this->instructions[$type];
		}
		
		return null;
	}
	
	/**
	 * Returns a list of php requirements for current package.
	 * 
	 * @return	array<array>
	 */
	public function getPhpRequirements() {
		return $this->phpRequirements;
	}
}
