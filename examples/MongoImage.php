<?php
/**
 * EMongoGridFS.php
 *
 * PHP version 5.2+
 *
 * @author		Jose Martinez <jmartinez@ibitux.com>
 * @author		Philippe Gaultier <pgaultier@ibitux.com>
 * @copyright	2010 Ibitux
 * @license		http://www.yiiframework.com/license/ BSD license
 * @version		SVN: $Revision: $
 * @category	ext
 * @package		ext.YiiMongoDbSuite
 */

/**
 * EMongoGridFS
 *
 * Authorization management, dispatches actions and views on the system
 *
 * @author		Jose Martinez <jmartinez@ibitux.com>
 * @author		Philippe Gaultier <pgaultier@ibitux.com>
 * @copyright	2010 Ibitux
 * @license		http://www.yiiframework.com/license/ BSD license
 * @version		SVN: $Revision: $
 * @category	ext
 * @package		ext.YiiMongoDbSuite
 *
 */
class MongoImage extends \YiiMongoDbSuite\EMongoGridFS
{
	public $metadata;

	/**
	 * this is similar to the get tableName() method. this returns tha name of the
	 * document for this class. this should be in all lowercase.
	 */
	public function getCollectionName()
	{
		return 'images';
	}

	public function rules()
	{
		return array(
			array('filename, metadata','safe'),
			array('filename','required'),
		);
	}
}
