<?php
/**
 * EMongoDbTestCase
 *
 * base class for unit testing
 *
 * @author        Philippe Gaultier <pgaultier@ibitux.com>
 * @copyright    2010-2011 Ibitux
 * @license        http://www.yiiframework.com/license/ BSD license
 * @category    tests
 * @package        ext.YiiMongoDbSuite.tests
 * @since        v1.3.6
 */

namespace YiiMongoDbSuite\test;

use \Yii;

Yii::import('system.test.CDbTestCase');

/**
 * EMongoDbTestCase extends base CDbTestCase.
 *
 * Simplifies specifying the component id of the DB fixture manager
 *
 * @author      Philippe Gaultier <pgaultier@ibitux.com>
 * @copyright   2010-2011 Ibitux
 * @license     http://www.yiiframework.com/license/ BSD license
 * @category    tests
 * @package     ext.YiiMongoDbSuite.tests
 * @since       v1.3.6
 */
abstract class EMongoDbTestCase extends \CDbTestCase
{
    /**
     * Yii application component ID for the EMongoDbFixtureManager
     * @var string
     * @since v1.4.0
     */
    protected $fixtureComponentId = 'fixture';

    /**
     * @return EMongoDbFixtureManager the database fixture manager
     * @since v1.4.0
     */
    public function getFixtureManager()
    {
        return Yii::app()->getComponent($this->fixtureComponentId);
    }
}
