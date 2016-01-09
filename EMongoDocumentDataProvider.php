<?php
/**
 * EMongoDocumentDataProvider.php
 *
 * PHP version 5.3+
 *
 * @author      Dariusz Górecki <darek.krk@gmail.com>
 * @author      Invenzzia Group, open-source division of CleverIT company http://www.invenzzia.org
 * @copyright   2011 CleverIT http://www.cleverit.com.pl
 * @license     http://www.yiiframework.com/license/ BSD license
 * @version     1.3
 * @category    ext
 * @package     ext.YiiMongoDbSuite
 * @since       v1.0
 */

namespace YiiMongoDbSuite;

use \CException;
use \CHtml;

/**
 * EMongoRecordDataProvider implements a data provider based on EMongoRecord.
 *
 * EMongoRecordDataProvider provides data in terms of MongoRecord objects which are
 * of class {@link modelClass}. It uses the AR {@link CActiveRecord::findAll} method
 * to retrieve the data from database. The {@link query} property can be used to
 * specify various query options, such as conditions, sorting, pagination, etc.
 *
 * @author canni
 * @since v1.0
 */
class EMongoDocumentDataProvider extends \CDataProvider
{
    /**
     * @var string the name of key field. Defaults to '_id', as a mongo default document primary key.
     * @since v1.0
     */
    public $keyField;

    /**
     * @var string the primary ActiveRecord class name. The {@link getData()} method
     * will return a list of objects of this class.
     * @since v1.0
     */
    public $modelClass;

    /**
     * @var EMongoRecord the AR finder instance (e.g. <code>Post::model()</code>).
     * This property can be set by passing the finder instance as the first parameter
     * to the constructor.
     * @since v1.0
     */
    public $model;

    private $_criteria;

    /**
     * Constructor.
     * @param mixed $modelClass the model class (e.g. 'Post') or the model finder instance
     * (e.g. <code>Post::model()</code>, <code>Post::model()->published()</code>).
     * @param array $config configuration (name=>value) to be applied as the initial property values of this class.
     * @since v1.0
     */
    public function __construct($modelClass, $config = array())
    {
        if (is_string($modelClass)) {
            $this->modelClass = $modelClass;
            $this->model = EMongoDocument::model($modelClass);
        } elseif ($modelClass instanceof EMongoDocument) {
            $this->modelClass = get_class($modelClass);
            $this->model = $modelClass;
        }

        $this->_criteria = $this->model->getDbCriteria();
        if (isset($config['criteria'])) {
            $this->_criteria->mergeWith($config['criteria']);
            unset($config['criteria']);
        }

        $this->setId(CHtml::modelName($this->model));
        foreach ($config as $key => $value) {
            $this->$key = $value;
        }

        if ($this->keyField !== null) {
            if (is_array($this->keyField)) {
                throw new CException('This DataProvider cannot handle multi-field primary key!');
            }
        } else {
            $this->keyField='_id';
        }
    }

    /**
     * Returns the criteria.
     *
     * @return EMongoCriteria|null the query criteria
     * @since v1.0
     */
    public function getCriteria()
    {
        return $this->_criteria;
    }

    /**
     * Sets the query criteria.
     *
     * @param array|EMongoCriteria $criteria The query criteria. If an array, it will
     *                                       be passed to the constructor of
     *                                       EMongoCriteria
     *
     * @see EMongoCriteria::__construct()
     * @since v1.0
     */
    public function setCriteria($criteria)
    {
        if (is_array($criteria)) {
            $this->_criteria = new EMongoCriteria($criteria);
        } elseif ($criteria instanceof EMongoCriteria) {
            $this->_criteria = $criteria;
        }
    }

    /**
     * Fetches the data from the persistent data storage.
     *
     * @return EMongoDocument[]|EMongoCursor list of data items
     * @since v1.0
     */
    protected function fetchData()
    {
        $pagination = $this->getPagination();
        if (false !== $pagination) {
            $pagination->setItemCount($this->getTotalItemCount());

            $this->_criteria->setLimit($pagination->getLimit());
            $this->_criteria->setOffset($pagination->getOffset());
        }

        $sort = $this->getSort();
        if (false !== $sort && '' != ($order = $sort->getOrderBy())) {
            $sort = array();
            foreach ($this->getSortDirections($order) as $name => $descending) {
                $sort[$name] = $descending
                    ? EMongoCriteria::SORT_DESC : EMongoCriteria::SORT_ASC;
            }
            $this->_criteria->setSort($sort);
        }

        return $this->model->resetScope()->findAll($this->_criteria);
    }

    /**
     * Fetches the data item keys from the persistent data storage.
     * @return array list of data item keys.
     * @since v1.0
     */
    protected function fetchKeys()
    {
        $keys = array();
        foreach ($this->getData() as $i => $data) {
            $keys[$i] = $data->{$this->keyField};
        }
        return $keys;
    }

    /**
     * Calculates the total number of data items.
     * @return integer the total number of data items.
     * @since v1.0
     */
    public function calculateTotalItemCount()
    {
        // Calling count() causes the scope to be reset so we need to get the
        // count here, reset the scope to what it was originally then return the
        // count
        $criteria = clone $this->getCriteria();
        $count = $this->model->resetScope()->count($this->_criteria);
        $this->_criteria = $criteria;

        return $count;
    }

    /**
     * Converts the "ORDER BY" clause into an array representing the sorting directions.
     * @param string $order the "ORDER BY" clause.
     * @return array the sorting directions (field name => whether it is descending sort)
     * @since v1.0
     */
    protected function getSortDirections($order)
    {
        $segs = explode(',', $order);
        $directions = array();
        foreach ($segs as $seg) {
            if (preg_match('/(.*?)(\s+(desc|asc))?$/i', trim($seg), $matches)) {
                $directions[$matches[1]] = isset($matches[3]) && !strcasecmp($matches[3], 'desc');
            } else {
                $directions[trim($seg)] = false;
            }
        }
        return $directions;
    }

    /**
     * Returns the sorting object. Overriden to ensure model attributes are set on
     * the CSort object.
     *
     * @return CSort|false The sorting object or false if sorting is disabled
     * @since v1.4.1
     */
    public function getSort()
    {
        $sort = parent::getSort();
        if (false !== $sort) {
            $sort->attributes = $this->model->attributeNames();
            if ($this->model->hasEmbeddedDocuments()) {
                foreach ($this->model->embeddedDocuments() as $attribute => $class) {
                    $doc = $this->model->$attribute;
                    if ($doc && method_exists($doc, 'attributeNames')) {
                        foreach ($doc->attributeNames() as $innerAttr) {
                            // Use dot separator as supported by CHtml::value()
                            $sort->attributes[] = $attribute . '.' . $innerAttr;
                        }
                    }
                }
            }
        }

        return $sort;
    }
}
