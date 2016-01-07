<?php
/**
 * EMongoDocument.php
 *
 * PHP version 5.3+
 * Mongo version 1.5+
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

use \CDbException;
use \CEvent;
use \CLogger;
use \CModelEvent;
use \MongoCollection;
use \MongoCursorException;
use \MongoException;
use \MongoRegex;
use \Yii;

/**
 * EMongoDocument
 *
 * @property-read MongoDB $db
 * @since v1.0
 */
abstract class EMongoDocument extends EMongoEmbeddedDocument
{
    /**
     * whether this instance is new or not
     * @var boolean
     */
    private $_new      = false;
    /**
     * query criteria (used by finder only)
     * @var EMongoCriteria
     */
    private $_criteria = null;

    /**
     * Static array that holds mongo collection object instances,
     * protected access since v1.3
     *
     * @var MongoCollection[] $_collections static array of loaded collection objects
     * @since v1.3
     */
    protected static $_collections = array();

    private static $_models        = array();

    /**
     * Hold collection indexes array
     * @var array
     */
    private static $_indexes       = array();

    /**
     * Object level Journaling flag
     * @var boolean|null
     */
    private $_journalFlag = null;

    /**
     * Object level write concern flag
     * @var integer|string
     */
    private $_writeConcern    = null;

    /**
     * Whatever to return cursor instead on raw array
     * @var boolean|null
     */
    protected $useCursor = null;

    /**
     * @var boolean $ensureIndexes whatever to check and create non existing indexes of collection
     * @since v1.1
     */
    protected $ensureIndexes = false;

    /**
     * Whether to generate profiler log messages. If not set, uses EMongoDB setting.
     * @var boolean
     * @since v1.4.0
     */
    protected $enableProfiler;

    /**
     * Yii application component used to retrieve/identify the EMongoDB instance to
     * use for this model.
     * @see setMongoDbComponent()
     * @var string
     * @since v1.4.0
     */
    protected $mongoComponentId = 'mongodb';

    /**
     * EMongoDB component static instances
     * @var EMongoDB[] $_emongoDb
     * @since v1.0
     */
    protected static $_emongoDb;

    /**
     * MongoDB special field, every document has to have this
     *
     * @var mixed $_id
     * @since v1.0
     */
    public $_id;

    /**
     * Add scopes functionality
     * @see CComponent::__call()
     * @since v1.0
     */
    public function __call($name, $parameters)
    {
        $scopes=$this->scopes();
        if (isset($scopes[$name])) {
            $this->getDbCriteria()->mergeWith($scopes[$name]);
            return $this;
        }

        return parent::__call($name, $parameters);
    }

    /**
     * Constructor {@see setScenario()}
     *
     * @param string $scenario
     * @since v1.0
     */
    public function __construct($scenario = 'insert')
    {
        // internally used by populateRecord() and model()
        if ($scenario==null) {
            return;
        }

        $this->setScenario($scenario);
        $this->setIsNewRecord(true);

        $this->init();

        $this->attachBehaviors($this->behaviors());
        $this->afterConstruct();

        $this->initEmbeddedDocuments();
    }

    /**
     * Return the primary key field for this collection, defaults to '_id'
     * @return string|array field name, or array of fields for composite primary key
     * @since v1.2.2
     */
    public function primaryKey()
    {
        return '_id';
    }


    /**
     * @since v1.2.2
     */
    public function getPrimaryKey()
    {
        $pk = $this->primaryKey();
        if (is_string($pk)) {
            return $this->{$pk};
        } else {
            $return = array();
            foreach ($pk as $pkFiled) {
                $return[] = $this->{$pkFiled};
            }

            return $return;
        }
    }

    /**
     * Get EMongoDB component instance
     * By default it is mongodb application component
     *
     * @return EMongoDB
     * @since v1.0
     */
    public function getMongoDBComponent()
    {
        if (!isset(self::$_emongoDb[$this->mongoComponentId])) {
            self::$_emongoDb[$this->mongoComponentId]
                = Yii::app()->getComponent($this->mongoComponentId);
        }

        return self::$_emongoDb[$this->mongoComponentId];
    }

    /**
     * Set EMongoDB component instance to use.
     * To support multiple Mongo instances, the componentId is used to distinguish
     * between multiple instances. If the component ID parameter is not specified,
     * this will use the existing componentID (defaults to 'mongodb'). If a
     * componentId is specified, then the local component ID will also be set to the
     * provided value.
     *
     * @param EMongoDB $component   EMongoDB instance for this model to use
     * @param string   $componentId Yii application component ID for this instance
     *
     * @since v1.0
     */
    public function setMongoDBComponent(EMongoDB $component, $componentId = null)
    {
        if (! $componentId) {
            $componentId = $this->mongoComponentId;
        } else {
            $this->mongoComponentId = $componentId;
        }

        self::$_emongoDb[$componentId] = $component;
    }

    /**
     * Get raw MongoDB instance
     * @return MongoDB
     * @since v1.0
     */
    public function getDb()
    {
        return $this->getMongoDBComponent()->getDbInstance();
    }

    /**
     * This method must return collection name for use with this model
     * this must be implemented in child classes
     *
     * this is read-only defined only at class define
     * if you whant to set different colection during run-time
     * use {@see setCollection()}
     *
     * @return string collection name
     * @since v1.0
     */
    abstract public function getCollectionName();

    /**
     * Returns current MongoCollection object
     * By default this method use {@see getCollectionName()}
     * @return MongoCollection
     * @since v1.0
     */
    public function getCollection()
    {
        if (!isset(self::$_collections[$this->getCollectionName()])) {
            self::$_collections[$this->getCollectionName()]
                = $this->getDb()->selectCollection($this->getCollectionName());
        }

        return self::$_collections[$this->getCollectionName()];
    }

    /**
     * Set current MongoCollection object
     * @param MongoCollection $collection
     * @since v1.0
     */
    public function setCollection(MongoCollection $collection)
    {
        self::$_collections[$this->getCollectionName()] = $collection;
    }

    /**
     * Returns if the current record is new.
     * @return boolean whether the record is new and should be inserted when calling {@link save}.
     * This property is automatically set in constructor and {@link populateRecord}.
     * Defaults to false, but it will be set to true if the instance is created using
     * the new operator.
     * @since v1.0
     */
    public function getIsNewRecord()
    {
        return $this->_new;
    }

    /**
     * Sets if the record is new.
     * @param boolean $value whether the record is new and should be inserted when calling {@link save}.
     * @see getIsNewRecord
     * @since v1.0
     */
    public function setIsNewRecord($value)
    {
        $this->_new=$value;
    }

    /**
     * Returns the mongo criteria associated with this model.
     * @param boolean $createIfNull whether to create a criteria instance if it does not exist. Defaults to true.
     * @return EMongoCriteria the query criteria that is associated with this model.
     * This criteria is mainly used by {@link scopes named scope} feature to accumulate
     * different criteria specifications.
     * @since v1.0
     */
    public function getDbCriteria($createIfNull = true)
    {
        if ($this->_criteria === null) {
            if (($c = $this->defaultScope()) !== array() || $createIfNull) {
                $this->_criteria = new EMongoCriteria($c);
            }
        }
        return $this->_criteria;
    }

    /**
     * Set girrent object, this will override proevious criteria
     *
     * @param EMongoCriteria $criteria
     * @since v1.0
     */
    public function setDbCriteria($criteria)
    {
        if (is_array($criteria)) {
            $this->_criteria = new EMongoCriteria($criteria);
        } elseif ($criteria instanceof EMongoCriteria) {
            $this->_criteria = $criteria;
        } else {
            $this->_criteria = new EMongoCriteria();
        }
    }

    /**
     * Get journaling flag
     *
     * It will return the nearest not null value in order:
     * - Object level
     * - Model level
     * - Global level (always set)
     *
     * @return boolean
     */
    public function getFsyncFlag()
    {
        if (null !== $this->_journalFlag) {
            return $this->_journalFlag; // We have flag set, return it
        }
        if (isset(self::$_models[get_class($this)])
            && (null !== self::$_models[get_class($this)]->_journalFlag)
        ) {
            // Model have flag set, return it
            return self::$_models[get_class($this)]->_journalFlag;
        }

        return $this->getMongoDBComponent()->fsyncFlag;
    }

    /**
     * Set object level journaling flag
     *
     * @param boolean $flag true|false value for journaling flag
     */
    public function setFsyncFlag($flag)
    {
        $this->_journalFlag = ($flag == true);
        if ($this->_journalFlag && ! $this->_writeConcern) {
            // Setting Journaling flag to true will implicitly set write concern to 1
            $this->setSafeFlag(1);
        }
    }

    /**
     * Get Safe flag (write concern)
     *
     * It will return the nearest not null value in order:
     * - Object level
     * - Model level
     * - Global level (always set)
     *
     * @return integer|string Write concern value
     */
    public function getSafeFlag()
    {
        if (null !== $this->_writeConcern) {
            return $this->_writeConcern; // We have flag set, return it
        }

        if (isset(self::$_models[get_class($this)])
            && (null !== self::$_models[get_class($this)]->_writeConcern)
        ) {
            // Model have flag set, return it
            return self::$_models[get_class($this)]->_writeConcern;
        }

        return $this->getMongoDBComponent()->safeFlag;
    }

    /**
     * Set object level Safe flag (write concern)
     *
     * @param integer|string $flag Write concern value
     */
    public function setSafeFlag($flag)
    {
        $this->_writeConcern = $flag;
    }

    /**
     * Get value of use cursor flag
     *
     * It will return the nearest not null value in order:
     * - Criteria level
     * - Object level
     * - Model level
     * - Glopal level (always set)
     * @return boolean
     */
    public function getUseCursor($criteria = null)
    {
        if ($criteria !== null && $criteria->getUseCursor() !== null) {
            return $criteria->getUseCursor();
        }
        if ($this->useCursor !== null) {
            return $this->useCursor; // We have flag set, return it
        }
        if ((isset(self::$_models[get_class($this)]) === true)
            && (self::$_models[get_class($this)]->useCursor !== null)
        ) {
            return self::$_models[get_class($this)]->useCursor; // Model have flag set, return it
        }
        return $this->getMongoDBComponent()->useCursor;
    }

    /**
     * Set object level value of use cursor flag
     * @param boolean $useCursor true|false value for use cursor flag
     */
    public function setUseCursor($useCursor)
    {
        $this->useCursor = ($useCursor == true);
    }

    /**
     * Determine whether ensureIndexes() should be called on intialization
     * @return boolean
     */
    public function getEnsureIndexes()
    {
        return $this->ensureIndexes;
    }

    /**
     * Set whether ensureIndexes() should be called on init.
     *
     * @param boolean $ensure Whether ensureIndexes() should be called on init()
     */
    public function setEnsureIndexes($ensure)
    {
        $this->ensureIndexes = (boolean) $ensure;
    }

    /**
     * Determine whether query profiling is enabled
     * @return boolean
     */
    public function getEnableProfiler()
    {
        if (null === $this->enableProfiler) {
            return $this->getMongoDBComponent()->enableProfiler;
        }

        return $this->enableProfiler;
    }

    /**
     * Set whether query profiling should be enabled.
     *
     * @param boolean $profiler Whether profiling should be set
     */
    public function setEnableProfiler($profiler)
    {
        $this->enableProfiler = (boolean) $profiler;
    }

    /**
     * Sets the attribute values in a massive way.
     *
     * @param array   $values   Attribute values (name=>value) to be set.
     * @param boolean $safeOnly Whether the assignments should only be done to the
     *                          safe attributes. A safe attribute is one that is
     *                          associated with a validation rule in the current
     *                          {@link scenario}.
     *
     * @see getSafeAttributeNames
     * @see attributeNames
     * @since v1.3.1
     */
    public function setAttributes($values, $safeOnly = true)
    {
        if (!is_array($values)) {
            return;
        }

        if ($this->hasEmbeddedDocuments()) {
            $attributes = array_flip(
                $safeOnly ? $this->getSafeAttributeNames() : $this->attributeNames()
            );

            foreach ($this->embeddedDocuments() as $fieldName => $className) {
                if (isset($values[$fieldName]) && isset($attributes[$fieldName])) {
                    if (is_array($className)
                        && isset($values[$fieldName][$className['classField']])
                    ) {
                        // Ensure the embedded document is sufficiently setup
                        $this->__set(
                            $fieldName,
                            array(
                                $className['classField']
                                    => $values[$fieldName][$className['classField']]
                            )
                        );
                    }
                    $this->$fieldName->setAttributes(
                        $values[$fieldName],
                        $safeOnly
                    );
                    unset($values[$fieldName]);
                }
            }
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * This function check indexes and applies them to the collection if needed
     * see CModel::init()
     *
     * @see EMongoEmbeddedDocument::init()
     * @since v1.1
     */
    public function init()
    {
        parent::init();

        if ($this->ensureIndexes
            && !isset(self::$_indexes[$this->getCollectionName()])
        ) {
            $profile = $this->getEnableProfiler();
            if ($profile) {
                $profile = EMongoCriteria::commandToString(
                    'getIndexes',
                    $this->getCollectionName()
                );
                Yii::beginProfile($profile, 'system.db.EMongoDocument');
            }

            try {
                $indexInfo = $this->getCollection()->getIndexInfo();
            } catch (MongoException $ex) {
                Yii::log(
                    'Failed to retreive index info; retrying. ' . PHP_EOL
                    . 'Error: ' . $ex->getMessage(),
                    CLogger::LEVEL_WARNING
                );
                $indexInfo = $this->getCollection()->getIndexInfo();
            }
            if ($profile) {
                Yii::endProfile($profile, 'system.db.EMongoDocument');
            }

            array_shift($indexInfo); // strip out default _id index

            $indexes = array();
            foreach ($indexInfo as $index) {
                $indexes[$index['name']] = $index;
            }
            self::$_indexes[$this->getCollectionName()] = $indexes;

            $this->ensureIndexes();
        }
    }

    /**
     * This function may return array of indexes for this collection
     * Note, that unless otherwise specified, the index parameter 'background' will
     * be set to true.
     *
     * @example return array(
     *     'index_name' => array(
     *         'key' => array(
     *             'fieldName1' => EMongoCriteria::SORT_ASC,
     *             'fieldName2' => EMongoCriteria::SORT_DESC,
     *         ),
     *         'sparse' => true,
     *     ),
     *     'index2_name' => array(
     *         'key'    => array('fieldName3' => EMongoCriteria::SORT_ASC),
     *         'unique' => true,
     *     ),
     * );
     *
     * @link http://php.net/manual/en/mongocollection.ensureindex.php
     * @return array list of indexes for this collection
     * @since v1.1
     */
    public function indexes()
    {
        return array();
    }

    /**
     * @since v1.1
     */
    private function ensureIndexes()
    {
        $indexNames = array_keys(self::$_indexes[$this->getCollectionName()]);
        foreach ($this->indexes() as $name => $index) {
            if (!in_array($name, $indexNames)) {
                $indexParams = array_merge(
                    array('background' => true, 'name' => $name),
                    $index
                );
                unset($indexParams['key']);

                $profile = $this->getEnableProfiler();
                if ($profile) {
                    $profile = EMongoCriteria::commandToString(
                        'ensureIndex',
                        $this->getCollectionName(),
                        $index['key'],
                        $indexParams
                    );
                    Yii::beginProfile($profile, 'system.db.EMongoDocument');
                }
                try {
                    $this->getCollection()->createIndex(
                        $index['key'],
                        $indexParams
                    );
                } catch (MongoCursorException $ex) {
                    Yii::log(
                        'Failed to ensureIndex(); retrying. ' . PHP_EOL
                        . 'Error: ' . $ex->getMessage(),
                        CLogger::LEVEL_WARNING
                    );

                    $this->getCollection()->createIndex(
                        $index['key'],
                        $indexParams
                    );
                }

                if ($profile) {
                    Yii::endProfile($profile, 'system.db.EMongoDocument');
                }

                self::$_indexes[$this->getCollectionName()][$name] = $index;
            }
        }
    }

    /**
     * Returns the declaration of named scopes.
     * A named scope represents a query criteria that can be chained together with
     * other named scopes and applied to a query. This method should be overridden
     * by child classes to declare named scopes for the particular document classes.
     * For example, the following code declares two named scopes: 'recently' and
     * 'published'.
     * <pre>
     * return array(
     *     'published'=>array(
     *           'conditions'=>array(
     *                 'status'=>array('==', 1),
     *           ),
     *     ),
     *     'recently'=>array(
     *           'sort'=>array('create_time'=>EMongoCriteria::SORT_DESC),
     *           'limit'=>5,
     *     ),
     * );
     * </pre>
     * If the above scopes are declared in a 'Post' model, we can perform the following
     * queries:
     * <pre>
     * $posts=Post::model()->published()->findAll();
     * $posts=Post::model()->published()->recently()->findAll();
     * $posts=Post::model()->published()->published()->recently()->find();
     * </pre>
     *
     * @return array the scope definition. The array keys are scope names; the array
     * values are the corresponding scope definitions. Each scope definition is represented
     * as an array whose keys must be properties of {@link EMongoCriteria}.
     * @since v1.0
     */
    public function scopes()
    {
        return array();
    }

    /**
     * Returns the default named scope that should be implicitly applied to all queries for this model.
     * Note, default scope only applies to SELECT queries. It is ignored for INSERT, UPDATE and DELETE queries.
     * The default implementation simply returns an empty array. You may override this method
     * if the model needs to be queried with some default criteria (e.g. only active records should be returned).
     * @return array the mongo criteria. This will be used as the parameter to the constructor
     * of {@link EMongoCriteria}.
     * @since v1.2.2
     */
    public function defaultScope()
    {
        return array();
    }

    /**
     * Resets all scopes and criterias applied including default scope.
     *
     * @return EMongoDocument
     * @since v1.0
     */
    public function resetScope()
    {
        $this->_criteria = new EMongoCriteria();
        return $this;
    }

    /**
     * Applies the query scopes to the given criteria.
     * This method merges {@link dbCriteria} with the given criteria parameter.
     * It then resets {@link dbCriteria} to be null.
     * @param EMongoCriteria|array $criteria the query criteria. This parameter
     *                                       may be modified by merging {@link dbCriteria}.
     * @since v1.2.2
     */
    public function applyScopes(&$criteria)
    {
        if ($criteria === null) {
            $criteria = new EMongoCriteria();
        } elseif (is_array($criteria)) {
            $criteria = new EMongoCriteria($criteria);
        } elseif (!($criteria instanceof EMongoCriteria)) {
            throw new EMongoException('Cannot apply scopes to criteria');
        }

        if (($c = $this->getDbCriteria(false)) !== null) {
            $c->mergeWith($criteria);
            $criteria=$c;
            $this->_criteria=null;
        }
    }

    /**
     * Saves the current record.
     *
     * The record is inserted as a row into the database table if its {@link isNewRecord}
     * property is true (usually the case when the record is created using the 'new'
     * operator). Otherwise, it will be used to update the corresponding row in the table
     * (usually the case if the record is obtained using one of those 'find' methods.)
     *
     * Validation will be performed before saving the record. If the validation fails,
     * the record will not be saved. You can call {@link getErrors()} to retrieve the
     * validation errors.
     *
     * If the record is saved via insertion, its {@link isNewRecord} property will be
     * set false, and its {@link scenario} property will be set to be 'update'.
     * And if its primary key is auto-incremental and is not set before insertion,
     * the primary key will be populated with the automatically generated key value.
     *
     * @param boolean $runValidation whether to perform validation before saving the record.
     * If the validation fails, the record will not be saved to database.
     * @param array $attributes list of attributes that need to be saved. Defaults to null,
     * meaning all attributes that are loaded from DB will be saved.
     * @return boolean whether the saving succeeds
     * @since v1.0
     */
    public function save($runValidation = true, $attributes = null)
    {
        if (!$runValidation || $this->validate($attributes)) {
            return $this->getIsNewRecord() ? $this->insert($attributes) : $this->update($attributes);
        } else {
            return false;
        }
    }

    /**
     * Inserts a row into the table based on this active record attributes.
     * If the table's primary key is auto-incremental and is null before insertion,
     * it will be populated with the actual value after insertion.
     * Note, validation is not performed in this method. You may call
     * {@link validate} to perform the validation.
     * After the record is inserted to DB successfully, its {@link isNewRecord}
     * property will be set false, and its {@link scenario} property will be set to
     * be 'update'.
     *
     * @param array $attributes list of attributes that need to be saved. Defaults to
     *                          null, meaning all attributes that are loaded from DB
     *                          will be saved.
     *
     * @return boolean whether the attributes are valid and the record is inserted
     *                 successfully.
     *
     * @throws CDbException                if the record is not new
     * @throws EMongoException             on fail of insert or insert of empty
     *                                     document
     * @throws MongoCursorException        on fail of insert, when write concern is
     *                                     set
     * @throws MongoCursorTimeoutException on timeout of db operation, when write
     *                                     concern is set
     * @since v1.0
     */
    public function insert(array $attributes = null)
    {
        if (!$this->getIsNewRecord()) {
            throw new CDbException(
                Yii::t(
                    'yii',
                    'The EMongoDocument cannot be inserted to database because it '
                    . 'is not new.'
                )
            );
        }
        if (! $this->beforeSave()) {
            return false;
        }

        Yii::trace(get_class($this) . '.insert()', 'MongoDb.EMongoDocument');
        $rawData = $this->toArray();
        // free the '_id' container if empty, mongo will not populate it if exists
        if (empty($rawData['_id'])) {
            unset($rawData['_id']);
        }
        // filter attributes if set in param
        if ($attributes !== null) {
            foreach ($rawData as $key => $value) {
                if (!in_array($key, $attributes)) {
                    unset($rawData[$key]);
                }
            }
        }

        $profile = $this->getEnableProfiler();
        if ($profile) {
            $profile = EMongoCriteria::commandToString(
                'insert',
                $this->getCollectionName(),
                $rawData
            );
            Yii::beginProfile($profile, 'system.db.EMongoDocument');
        }

        try {
            $result = $this->getCollection()->insert(
                $rawData,
                array(
                    'j' => $this->getFsyncFlag(),
                    'w' => $this->getSafeFlag(),
                )
            );
        } catch (MongoException $ex) {
            // Do not attempt retry for duplicate key errors
            if ($ex instanceof MongoCursorException && $ex->getCode() === 11000) {
                throw $ex;
            }
            Yii::log(
                'Failed to submit insert(); retrying. ' . PHP_EOL
                . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );
            $result = $this->getCollection()->insert(
                $rawData,
                array(
                    'j' => $this->getFsyncFlag(),
                    'w' => $this->getSafeFlag(),
                )
            );
        }

        if ($profile) {
            Yii::endProfile($profile, 'system.db.EMongoDocument');
        }

        if ($result !== false) { // strict comparison needed
            $this->_id = $rawData['_id'];
            $this->afterSave();
            $this->setIsNewRecord(false);
            $this->setScenario('update');

            return true;
        }

        if ($rawData) {
            $message = Yii::t('yii', 'Failed to save document');
        } else {
            $message = Yii::t(
                'yii',
                'Unable to save an empty document: {class}',
                array('{class}' => get_class($this))
            );
        }
        throw new EMongoException($message);
    }

    /**
     * Updates the row represented by this active record.
     * All loaded attributes will be saved to the database.
     * Note, validation is not performed in this method. You may call
     * {@link validate} to perform the validation.
     *
     * @param array   $attributes list of attributes that need to be saved. Defaults
     *                            to null, meaning all attributes that are loaded
     *                            from DB will be saved. Embedded attributes may be
     *                            specified using the dot notation (e.g. 'a.b').
     * @param boolean $modify     if set true only selected attributes will be
     *                            replaced, and not the whole document
     *
     * @return boolean whether the update is successful
     *
     * @throws CDbException                if the record is new
     * @throws EMongoException             on fail of update
     * @throws MongoCursorException        on fail of update, when write concern is
     *                                     set
     * @throws MongoCursorTimeoutException on timeout of db operation, when write
     *                                     concern is set
     * @since v1.0
     */
    public function update(array $attributes = null, $modify = false)
    {
        if ($this->getIsNewRecord()) {
            throw new CDbException(
                Yii::t(
                    'yii',
                    'The EMongoDocument cannot be updated because it is new.'
                )
            );
        }
        if (! $this->beforeSave()) {
            return false;
        }
        Yii::trace(get_class($this).'.update()', 'MongoDb.EMongoDocument');
        $rawData = $this->toArray();
        // filter attributes if set in param
        if ($attributes !== null) {
            if (!in_array('_id', $attributes) && !$modify) {
                $attributes[] = '_id'; // This is very easy to forget
            }

            $data = [];
            foreach ($attributes as $attrib) {
                // Check if subdocument attributes are set to be updated
                if (false !== strpos($attrib, '.')) {
                    $values = $rawData;
                    // Get the value for the inner attribute specified
                    foreach (explode('.', $attrib) as $key) {
                        if (!is_array($values) || !array_key_exists($key, $values)) {
                            $message = Yii::t(
                                'yii',
                                'Attribute {attr} does not exist on {doc}',
                                array(
                                    '{attr}' => $attrib, '{doc}' => get_class($this)
                                )
                            );
                            throw new EMongoException($message);
                        }
                        $values = $values[$key];
                    }
                    $data[$attrib] = $values;
                } elseif (array_key_exists($attrib, $rawData)) {
                    $data[$attrib] = $rawData[$attrib];
                }
            }

            $rawData = $data;
        }

        $profile = $this->getEnableProfiler();

        if ($modify) {
            if (isset($rawData['_id'])) {
                unset($rawData['_id']);
            }
            if ($profile) {
                $profile = EMongoCriteria::commandToString(
                    'update',
                    $this->getCollectionName(),
                    array('_id' => $this->_id),
                    array('$set' => $rawData)
                );
                Yii::beginProfile($profile, 'system.db.EMongoDocument');
            }

            try {
                $result = $this->getCollection()->update(
                    array('_id' => $this->_id),
                    array('$set' => $rawData),
                    array(
                        'j'        => $this->getFsyncFlag(),
                        'w'        => $this->getSafeFlag(),
                        'multiple' => false
                    )
                );
            } catch (MongoException $ex) {
                // Do not attempt retry for duplicate key errors
                if ($ex instanceof MongoCursorException
                    && $ex->getCode() === 11000
                ) {
                    throw $ex;
                }

                Yii::log(
                    'Failed to submit update(); retrying. ' . PHP_EOL
                    . 'Error: ' . $ex->getMessage(),
                    CLogger::LEVEL_WARNING
                );
                $result = $this->getCollection()->update(
                    array('_id' => $this->_id),
                    array('$set' => $rawData),
                    array(
                        'j'        => $this->getFsyncFlag(),
                        'w'        => $this->getSafeFlag(),
                        'multiple' => false
                    )
                );
            }
        } else {
            if ($profile) {
                $profile = EMongoCriteria::commandToString(
                    'save',
                    $this->getCollectionName(),
                    $rawData
                );
                Yii::beginProfile($profile, 'system.db.EMongoDocument');
            }
            try {
                $result = $this->getCollection()->save(
                    $rawData,
                    array(
                        'j' => $this->getFsyncFlag(),
                        'w' => $this->getSafeFlag(),
                    )
                );
            } catch (MongoException $ex) {
                // Do not attempt retry for duplicate key errors
                if ($ex instanceof MongoCursorException
                    && $ex->getCode() === 11000
                ) {
                    throw $ex;
                }

                Yii::log(
                    'Failed to submit update(); retrying. ' . PHP_EOL
                    . 'Error: ' . $ex->getMessage(),
                    CLogger::LEVEL_WARNING
                );
                $result = $this->getCollection()->save(
                    $rawData,
                    array(
                        'j' => $this->getFsyncFlag(),
                        'w' => $this->getSafeFlag(),
                    )
                );
            }
        }

        if ($profile) {
            Yii::endProfile($profile, 'system.db.EMongoDocument');
        }

        if ($result !== false) { // strict comparison needed
            $this->afterSave();

            return true;
        }

        if ($rawData) {
            $message = Yii::t('yii', 'Failed to save document');
        } else {
            $message = Yii::t(
                'yii',
                'Unable to save an empty document: {class}',
                array('{class}' => get_class($this))
            );
        }
        throw new EMongoException($message);
    }

    /**
     * Atomic, in-place update method.
     *
     * @param EMongoModifier $modifier updating rules to apply
     * @param EMongoCriteria $criteria condition to limit updating rules
     *
     * @since v1.3.6
     * @return boolean if the update command was successful
     */
    public function updateAll($modifier, $criteria = null)
    {
        Yii::trace(get_class($this) . '.updateAll()', 'MongoDb.EMongoDocument');
        if ($modifier->canApply !== true) {
            return false;
        }

        $this->applyScopes($criteria);

        $profile = $this->getEnableProfiler();
        if ($profile) {
            $profile = EMongoCriteria::commandToString(
                'update',
                $this->getCollectionName(),
                $criteria->getConditions(),
                $modifier->getModifiers(),
                array('multiple' => true)
            );
            Yii::beginProfile($profile, 'system.db.EMongoDocument');
        }
        try {
            $result = $this->getCollection()->update(
                $criteria->getConditions(),
                $modifier->getModifiers(),
                array(
                    'j'        => $this->getFsyncFlag(),
                    'w'        => $this->getSafeFlag(),
                    'upsert'   => false,
                    'multiple' => true
                )
            );
        } catch (MongoException $ex) {
            Yii::log(
                'Failed to submit updateAll(); retrying. ' . PHP_EOL
                . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );
            $result = $this->getCollection()->update(
                $criteria->getConditions(),
                $modifier->getModifiers(),
                array(
                    'j'        => $this->getFsyncFlag(),
                    'w'        => $this->getSafeFlag(),
                    'upsert'   => false,
                    'multiple' => true
                )
            );
        }

        return $result;
    }

    /**
     * Deletes the row corresponding to this EMongoDocument.
     *
     * @return boolean      Whether the deletion is successful.
     * @throws CDbException if the record is new
     * @since v1.0
     */
    public function delete()
    {
        if ($this->getIsNewRecord()) {
            throw new CDbException(
                Yii::t(
                    'yii',
                    'The EMongoDocument cannot be deleted because it is new.'
                )
            );
        }
        Yii::trace(get_class($this) . '.delete()', 'MongoDb.EMongoDocument');
        if ($this->beforeDelete()) {
            $result = $this->deleteByPk($this->getPrimaryKey());

            if ($result !== false) {
                $this->afterDelete();
                $this->setIsNewRecord(true);
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * Deletes document with the specified primary key.
     * See {@link findByPk()} for detailed explanation about $pk and $criteria.
     *
     * @param mixed                $pk       Primary key value(s). For composite
     *                                       key, each key value must be an array
     *                                       (field name => field value).
     * @param array|EMongoCriteria $criteria Additional query criteria.
     *
     * @return boolean Whether the delete was successful
     * @since v1.0
     */
    public function deleteByPk($pk, $criteria = null)
    {
        Yii::trace(get_class($this) . '.deleteByPk()', 'MongoDb.EMongoDocument');
        $this->applyScopes($criteria);
        $criteria->mergeWith($this->createPkCriteria($pk));

        $profile = $this->getEnableProfiler();
        if ($profile) {
            $profile = EMongoCriteria::commandToString(
                'remove',
                $this->getCollectionName(),
                $criteria->getConditions(),
                true // justOne
            );
            Yii::beginProfile($profile, 'system.db.EMongoDocument');
        }

        try {
            $result = $this->getCollection()->remove(
                $criteria->getConditions(),
                array(
                    'justOne' => true,
                    'j'       => $this->getFsyncFlag(),
                    'w'       => $this->getSafeFlag(),
                )
            );
        } catch (MongoException $ex) {
            Yii::log(
                'Failed to submit deleteByPk(); retrying. ' . PHP_EOL
                . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );
            $result = $this->getCollection()->remove(
                $criteria->getConditions(),
                array(
                    'justOne' => true,
                    'j'       => $this->getFsyncFlag(),
                    'w'       => $this->getSafeFlag(),
                )
            );
        }
        if ($profile) {
            Yii::endProfile($profile, 'system.db.EMongoDocument');
        }

        return $result;
    }


    /**
     * Repopulates this active record with the latest data.
     *
     * @return boolean whether the row still exists in the database. If true, the
     *                 latest data will be populated to this active record.
     * @since v1.0
     */
    public function refresh()
    {
        Yii::trace(get_class($this) . '.refresh()', 'MongoDb.EMongoDocument');
        if ($this->getIsNewRecord()) {
            return false;
        }

        $profile = $this->getEnableProfiler();
        if ($profile) {
            // Not an actual Mongo operation, so use class name instead of
            // collection name
            $profile = get_class($this) . '.refresh('
                . EMongoCriteria::queryValueToString(array('_id' => $this->_id))
                . ')';
            Yii::beginProfile($profile, 'system.db.EMongoDocument');
        }
        try {
            $count = $this->getCollection()->count(array('_id' => $this->_id));
        } catch (MongoException $ex) {
            Yii::log(
                'Failed to submit count for refresh(); retrying. ' . PHP_EOL
                . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );

            $count = $this->getCollection()->count(array('_id' => $this->_id));
        }

        if (1 == $count) {
            try {
                $this->setAttributes(
                    $this->getCollection()->findOne(array('_id' => $this->_id)),
                    false
                );
            } catch (MongoException $ex) {
                Yii::log(
                    'Failed to submit query for refresh(); retrying. ' . PHP_EOL
                    . 'Error: ' . $ex->getMessage(),
                    CLogger::LEVEL_WARNING
                );
                $this->setAttributes(
                    $this->getCollection()->findOne(array('_id' => $this->_id)),
                    false
                );
            }
            if ($profile) {
                Yii::endProfile($profile, 'system.db.EMongoDocument');
            }

            return true;
        } else {
            if ($profile) {
                Yii::endProfile($profile, 'system.db.EMongoDocument');
            }
            return false;
        }
    }

    /**
     * Finds a single EMongoDocument with the specified condition.
     *
     * @param array|EMongoCriteria $criteria query criteria.
     * If an array, it is treated as the initial values for constructing a
     * {@link EMongoCriteria} object; Otherwise, it should be an instance of
     * {@link EMongoCriteria}.
     * @param array $options Array of options passed to findOne: maxTimeMS
     *
     * @see MongoCollection::findOne Find operation
     * @return EMongoDocument|null the record found. Null if no record is found.
     * @since v1.0
     */
    public function find($criteria = null, $options = array())
    {
        Yii::trace(get_class($this) . '.find()', 'MongoDb.EMongoDocument');

        if (! $this->beforeFind()) {
            return null;
        }

        $this->applyScopes($criteria);

        $profile = $this->getEnableProfiler();
        if ($profile) {
            $profile = EMongoCriteria::findToString(
                $criteria,
                false,
                $this->getCollectionName()
            );
            Yii::beginProfile($profile, 'system.db.EMongoDocument');
        }
        try {
            $doc = $this->getCollection()->findOne(
                $criteria->getConditions(),
                $criteria->getSelect(),
                $options
            );
        } catch (MongoException $ex) {
            Yii::log(
                'Failed to submit find(); retrying. ' . PHP_EOL
                . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );
            $doc = $this->getCollection()->findOne(
                $criteria->getConditions(),
                $criteria->getSelect()
            );
        }

        if ($profile) {
            Yii::endProfile($profile, 'system.db.EMongoDocument');
        }

        return $this->populateRecord($doc);
    }

    /**
     * Finds all documents satisfying the specified condition.
     * See {@link find()} for detailed explanation about $condition and $params.
     *
     * @param array|EMongoCriteria $criteria query criteria.
     *
     * @return EMongoDocument[]|EMongoCursor All documents found or a cursor if
     *                                       {@link getUseCursor()} is true
     * @since v1.0
     */
    public function findAll($criteria = null)
    {
        Yii::trace(get_class($this) . '.findAll()', 'MongoDb.EMongoDocument');

        if (! $this->beforeFind()) {
            return array();
        }

        $this->applyScopes($criteria);

        $profile = $this->getEnableProfiler();
        if ($profile) {
            $profile = EMongoCriteria::findToString(
                $criteria,
                true,
                $this->getCollectionName()
            );
            Yii::beginProfile($profile, 'system.db.EMongoDocument');
        }

        $cursor = $this->getCursor($criteria);

        if ($this->getUseCursor($criteria)) {
            $return = new EMongoCursor($cursor, $this->model());
        } else {
            try {
                $return = $this->populateRecords($cursor);
            } catch (MongoException $ex) {
                Yii::log(
                    'Failed to submit populateRecords for findAll(); retrying. '
                    . PHP_EOL . 'Error: ' . $ex->getMessage(),
                    CLogger::LEVEL_WARNING
                );
                // The cursor may have died, submit query again to make sure all
                // data is populated
                $cursor = $this->getCursor($criteria);
                $return = $this->populateRecords($cursor);
            }
        }
        if ($profile) {
            Yii::endProfile($profile, 'system.db.EMongoDocument');
        }

        return $return;
    }

    /**
     * Convert a criteria object into an actual MongoCursor object
     *
     * @param EMongoCriteria $criteria Criteria for query
     *
     * @return MongoCursor
     */
    protected function getCursor(EMongoCriteria $criteria)
    {
        try {
            $cursor = $this->getCollection()->find($criteria->getConditions());
        } catch (MongoException $ex) {
            Yii::log(
                'Failed to get Mongo cursor; retrying. ' . PHP_EOL
                . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );
            $cursor = $this->getCollection()->find($criteria->getConditions());
        }

        if (null !== $criteria->getSort()) {
            $cursor->sort($criteria->getSort());
        }
        if (null !== $criteria->getLimit()) {
            $cursor->limit($criteria->getLimit());
        }
        if (null !== $criteria->getOffset()) {
            $cursor->skip($criteria->getOffset());
        }
        if ($criteria->getSelect()) {
            $cursor->fields($criteria->getSelect());
        }

        return $cursor;
    }

    /**
     * Finds document with the specified primary key.
     * In MongoDB world every document has '_id' unique field, so with this method
     * that field is in use as PK!
     * See {@link find()} for detailed explanation about $criteria.
     *
     * @param mixed                $pk       Primary key value(s). For composite
     *                                       key, each key value must be an array
     *                                       (column name => column value).
     * @param array|EMongoCriteria $criteria Additional query criteria.
     *
     * @return EMongoDocument|null The document found. Null is returned if none is
     *                             found.
     * @since v1.0
     */
    public function findByPk($pk, $criteria = null)
    {
        Yii::trace(get_class($this) . '.findByPk()', 'MongoDb.EMongoDocument');
        $criteria = new EMongoCriteria($criteria);
        $criteria->mergeWith($this->createPkCriteria($pk));

        return $this->find($criteria);
    }

    /**
     * Finds all documents with the specified primary keys.
     * In MongoDB world every document has '_id' unique field, so with this method
     * that field is in use as PK by default.
     * See {@link find()} for detailed explanation about $condition.
     *
     * @param mixed                $pk       Primary key value(s). Use array for
     *                                       multiple primary keys. For composite
     *                                       key, each key value must be an array
     *                                       (column name => column value).
     * @param array|EMongoCriteria $criteria query criteria.
     *
     * @return EMongoDocument[]|EMongoCursor All documents found or a cursor if
     *                                       {@link getUseCursor()} is true
     * @since v1.0
     */
    public function findAllByPk($pk, $criteria = null)
    {
        Yii::trace(get_class($this) . '.findAllByPk()', 'MongoDb.EMongoDocument');
        $criteria = new EMongoCriteria($criteria);
        $criteria->mergeWith($this->createPkCriteria($pk, true));

        return $this->findAll($criteria);
    }

    /**
     * Finds a document with the specified attributes.
     *
     * @param array $attributes Query criteria. Should be in the format
     *                          (field name => field value).
     *
     * @return EMongoDocument|null The document found. Null is returned if none
     *                             found.
     * @since v1.0
     */
    public function findByAttributes(array $attributes)
    {
        $criteria = new EMongoCriteria();
        foreach ($attributes as $name => $value) {
            $criteria->$name('==', $value);
        }

        return $this->find($criteria);
    }

    /**
     * Finds all documents with the specified attributes.
     *
     * @param array $attributes Query criteria. Should be in the format
     *                          (field name => field value).
     *
     * @return EMongoDocument[]|EMongoCursor All documents found or a cursor if
     *                                       {@link getUseCursor()} is true
     * @since v1.0
     */
    public function findAllByAttributes(array $attributes)
    {
        $criteria = new EMongoCriteria();
        foreach ($attributes as $name => $value) {
            $criteria->$name('==', $value);
        }

        return $this->findAll($criteria);
    }

    /**
     * Counts all documents satisfying the specified condition.
     * See {@link find()} for detailed explanation about $condition and $params.
     *
     * @param array|EMongoCriteria $criteria query criteria.
     *
     * @return integer Count of all documents satisfying the specified condition.
     * @since v1.0
     */
    public function count($criteria = null)
    {
        Yii::trace(get_class($this) . '.count()', 'MongoDb.EMongoDocument');

        $this->applyScopes($criteria);

        $profile = $this->getEnableProfiler();
        if ($profile) {
            $profile = EMongoCriteria::commandToString(
                'count',
                $this->getCollectionName(),
                $criteria->getConditions()
            );
            Yii::beginProfile($profile, 'system.db.EMongoDocument');
        }

        try {
            $result = $this->getCollection()->count($criteria->getConditions());
        } catch (MongoException $ex) {
            Yii::log(
                'Failed to submit count(); retrying. ' . PHP_EOL
                . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );
            $result = $this->getCollection()->count($criteria->getConditions());
        }
        if ($profile) {
            Yii::endProfile($profile, 'system.db.EMongoDocument');
        }

        return $result;
    }

    /**
     * Counts all documents satisfying the specified condition.
     * See {@link findByAttributes()} for detailed explanation about $attributes.
     *
     * @param array $attributes query criteria.
     *
     * @return integer Count of all documents satisfying the specified condition.
     * @since v1.2.2
     */
    public function countByAttributes(array $attributes)
    {
        Yii::trace(
            get_class($this) . '.countByAttributes()',
            'MongoDb.EMongoDocument'
        );

        $criteria = new EMongoCriteria;
        foreach ($attributes as $name => $value) {
            $criteria->$name = $value;
        }

        $this->applyScopes($criteria);

        $profile = $this->getEnableProfiler();
        if ($profile) {
            $profile = EMongoCriteria::commandToString(
                'count',
                $this->getCollectionName(),
                $criteria->getConditions()
            );
            Yii::beginProfile($profile, 'system.db.EMongoDocument');
        }

        try {
            $result = $this->getCollection()->count($criteria->getConditions());
        } catch (MongoException $ex) {
            Yii::log(
                'Failed to submit countByAttributes(); retrying. ' . PHP_EOL
                . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );
            $result = $this->getCollection()->count($criteria->getConditions());
        }

        if ($profile) {
            Yii::endProfile($profile, 'system.db.EMongoDocument');
        }

        return $result;
    }

    /**
     * Deletes documents with the specified critieria.
     * See {@link find()} for detailed explanation about $criteria.
     *
     * @param array|EMongoCriteria $condition query criteria.
     *
     * @see MongoCollection::remove()
     * @return true|array The result of MongoCollection::remove()
     * @since v1.0
     */
    public function deleteAll($criteria = null)
    {
        Yii::trace(get_class($this).'.deleteAll()', 'MongoDb.EMongoDocument');
        $this->applyScopes($criteria);

        $profile = $this->getEnableProfiler();
        if ($profile) {
            $profile = EMongoCriteria::commandToString(
                'remove',
                $this->getCollectionName(),
                $criteria->getConditions(),
                false // justOne
            );
            Yii::beginProfile($profile, 'system.db.EMongoDocument');
        }

        try {
            $result = $this->getCollection()->remove(
                $criteria->getConditions(),
                array(
                    'justOne' => false,
                    'j'       => $this->getFsyncFlag(),
                    'w'       => $this->getSafeFlag(),
                )
            );
        } catch (MongoException $ex) {
            Yii::log(
                'Failed to submit deleteAll(); retrying. ' . PHP_EOL
                . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );
            $result = $this->getCollection()->remove(
                $criteria->getConditions(),
                array(
                    'justOne' => false,
                    'j'       => $this->getFsyncFlag(),
                    'w'       => $this->getSafeFlag(),
                )
            );
        }

        if ($profile) {
            Yii::endProfile($profile, 'system.db.EMongoDocument');
        }

        return $result;
    }

    /**
     * This event is raised before the record is saved.
     * By setting {@link CModelEvent::isValid} to be false, the normal {@link save()} process will be stopped.
     * @param CModelEvent $event the event parameter
     * @since v1.0
     */
    public function onBeforeSave($event)
    {
        $this->raiseEvent('onBeforeSave', $event);
    }

    /**
     * This event is raised after the record is saved.
     * @param CEvent $event the event parameter
     * @since v1.0
     */
    public function onAfterSave($event)
    {
        $this->raiseEvent('onAfterSave', $event);
    }

    /**
     * This event is raised before the record is deleted.
     * By setting {@link CModelEvent::isValid} to be false, the normal {@link delete()} process will be stopped.
     * @param CModelEvent $event the event parameter
     * @since v1.0
     */
    public function onBeforeDelete($event)
    {
        $this->raiseEvent('onBeforeDelete', $event);
    }

    /**
     * This event is raised after the record is deleted.
     * @param CEvent $event the event parameter
     * @since v1.0
     */
    public function onAfterDelete($event)
    {
        $this->raiseEvent('onAfterDelete', $event);
    }

    /**
     * This event is raised before finder performs a find call.
     * In this event, the {@link CModelEvent::criteria} property contains the query criteria
     * passed as parameters to those find methods. If you want to access
     * the query criteria specified in scopes, please use {@link getDbCriteria()}.
     * You can modify either criteria to customize them based on needs.
     * @param CModelEvent $event the event parameter
     * @see beforeFind
     * @since v1.0
     */
    public function onBeforeFind($event)
    {
        $this->raiseEvent('onBeforeFind', $event);
    }

    /**
     * This event is raised after the record is instantiated by a find method.
     * @param CEvent $event the event parameter
     * @since v1.0
     */
    public function onAfterFind($event)
    {
        $this->raiseEvent('onAfterFind', $event);
    }

    /**
     * This method is invoked before saving a record (after validation, if any).
     * The default implementation raises the {@link onBeforeSave} event.
     * You may override this method to do any preparation work for record saving.
     * Use {@link isNewRecord} to determine whether the saving is
     * for inserting or updating record.
     * Make sure you call the parent implementation so that the event is raised properly.
     * @return boolean whether the saving should be executed. Defaults to true.
     * @since v1.0
     */
    protected function beforeSave()
    {
        if ($this->hasEventHandler('onBeforeSave')) {
            $event = new CModelEvent($this);
            $this->onBeforeSave($event);
            return $event->isValid;
        } else {
            return true;
        }
    }

    /**
     * This method is invoked after saving a record successfully.
     * The default implementation raises the {@link onAfterSave} event.
     * You may override this method to do postprocessing after record saving.
     * Make sure you call the parent implementation so that the event is raised properly.
     * @since v1.0
     */
    protected function afterSave()
    {
        if ($this->hasEventHandler('onAfterSave')) {
            $this->onAfterSave(new CEvent($this));
        }
    }

    /**
     * This method is invoked before deleting a record.
     * The default implementation raises the {@link onBeforeDelete} event.
     * You may override this method to do any preparation work for record deletion.
     * Make sure you call the parent implementation so that the event is raised properly.
     * @return boolean whether the record should be deleted. Defaults to true.
     * @since v1.0
     */
    protected function beforeDelete()
    {
        if ($this->hasEventHandler('onBeforeDelete')) {
            $event = new CModelEvent($this);
            $this->onBeforeDelete($event);
            return $event->isValid;
        } else {
            return true;
        }
    }

    /**
     * This method is invoked after deleting a record.
     * The default implementation raises the {@link onAfterDelete} event.
     * You may override this method to do postprocessing after the record is deleted.
     * Make sure you call the parent implementation so that the event is raised properly.
     * @since v1.0
     */
    protected function afterDelete()
    {
        if ($this->hasEventHandler('onAfterDelete')) {
            $this->onAfterDelete(new CEvent($this));
        }
    }

    /**
     * This method is invoked before an AR finder executes a find call.
     * The find calls include {@link find}, {@link findAll}, {@link findByPk},
     * {@link findAllByPk}, {@link findByAttributes} and {@link findAllByAttributes}.
     * The default implementation raises the {@link onBeforeFind} event.
     * If you override this method, make sure you call the parent implementation
     * so that the event is raised properly.
     *
     * Starting from version 1.1.5, this method may be called with a hidden {@link CDbCriteria}
     * parameter which represents the current query criteria as passed to a find method of AR.
     * @since v1.0
     */
    protected function beforeFind()
    {
        if ($this->hasEventHandler('onBeforeFind')) {
            $event = new CModelEvent($this);
            $this->onBeforeFind($event);
            return $event->isValid;
        } else {
            return true;
        }
    }

    /**
     * This method is invoked after each record is instantiated by a find method.
     * The default implementation raises the {@link onAfterFind} event.
     * You may override this method to do postprocessing after each newly found record is instantiated.
     * Make sure you call the parent implementation so that the event is raised properly.
     * @since v1.0
     */
    protected function afterFind()
    {
        if ($this->hasEventHandler('onAfterFind')) {
            $this->onAfterFind(new CEvent($this));
        }
    }

    /**
     * Creates an document instance.
     * This method is called by {@link populateRecord} and {@link populateRecords}.
     * You may override this method if the instance being created
     * depends the attributes that are to be populated to the record.
     * @param array $attributes list of attribute values for the active records.
     * @return EMongoDocument the document
     * @since v1.0
     */
    protected function instantiate($attributes)
    {
        $class=get_class($this);
        $model = new $class(null);
        $model->initEmbeddedDocuments();
        $model->setAttributes($attributes, false);
        return $model;
    }

    /**
     * Creates an EMongoDocument with the given attributes.
     * This method is internally used by the find methods.
     * @param array $attributes attribute values (column name=>column value)
     * @param boolean $callAfterFind whether to call {@link afterFind} after the record is populated.
     * This parameter is added in version 1.0.3.
     * @return EMongoDocument the newly created document. The class of the object is the same as the model class.
     * Null is returned if the input data is false.
     * @since v1.0
     */
    public function populateRecord($document, $callAfterFind = true)
    {
        if ($document!==null) {
            $model = $this->instantiate($document);
            $model->setScenario('update');
            $model->init();

            // Behaviors have already been attached in the constructor so we need to
            // prevent duplicates but allow for behaviors that are conditionally
            // attached based on populated values
            $model->detachBehaviors();
            $model->attachBehaviors($model->behaviors());

            if ($callAfterFind) {
                $model->afterFind();
            }
            return $model;
        } else {
            return null;
        }
    }

    /**
     * Creates a list of documents based on the input data.
     * This method is internally used by the find methods.
     *
     * @param array   $data          List of attribute values for the active records
     * @param boolean $callAfterFind Whether to call {@link afterFind} after each
     *                               record is populated. This parameter is added in
     *                               version 1.0.3.
     * @param string  $index         The name of the attribute whose value will be
     *                               used as indexes of the query result array. If
     *                               null, it means the array will be indexed by
     *                               zero-based integers.
     *
     * @return EMongoDocument[] Array of active records.
     * @since v1.0
     */
    public function populateRecords($data, $callAfterFind = true, $index = null)
    {
        $records = array();
        foreach ($data as $attributes) {
            $record = $this->populateRecord($attributes, $callAfterFind);
            if (null !== $record) {
                if (null === $index) {
                    $records[] = $record;
                } else {
                    $records[$record->$index] = $record;
                }
            }
        }

        return $records;
    }

    /**
     * Magic search method, provides basic search functionality.
     *
     * Returns EMongoDocument objects with criteria set to
     * rexexp: /$attributeValue/i
     * Used for Data provider search functionality
     *
     * @param boolean $caseSensitive Whether do a case-sensitive search, default to false
     *
     * @return EMongoDocumentDataProvider Provider with results
     * @since v1.2.2
     */
    public function search($caseSensitive = false)
    {
        $criteria = $this->getDbCriteria();
        $opRegex = '/^(?:\s*(<>|<=|>=|<|>|=|!=|==))?(.*)$/';

        foreach ($this->getSafeAttributeNames() as $attribute) {
            // Ignore unset embedded documents
            if (isset(self::$_embeddedConfig[get_class($this)][$attribute])
                && (!isset($this->_embedded) || null === $this->_embedded->itemAt($attribute))
            ) {
                continue;
            }
            if (null !== $this->$attribute && '' !== $this->$attribute) {
                if (is_array($this->$attribute) || is_object($this->$attribute)) {
                    $criteria->$attribute = $this->$attribute;
                } elseif (preg_match($opRegex, $this->$attribute, $matches)) {
                    $op = $matches[1];
                    $value = $matches[2];

                    if ($op === '=') {
                        $op = '==';
                    }

                    if ($op !== '') {
                        // Call magic setter on EMongoCriteria
                        call_user_func(
                            array($criteria, $attribute),
                            $op,
                            is_numeric($value) ? floatval($value) : $value
                        );
                    } else {
                        $regex = new MongoRegex(
                            '/' . preg_quote($this->$attribute, '/') . '/'
                            . ($caseSensitive ? '' : 'i')
                        );
                        $criteria->$attribute = $regex;
                    }
                }
            }
        }

        $this->setDbCriteria($criteria);

        return new EMongoDocumentDataProvider($this);
    }

    /**
     * Returns the static model of the specified EMongoDocument class.
     * The model returned is a static instance of the EMongoDocument class.
     * It is provided for invoking class-level methods (something similar to static
     * class methods.)
     *
     * @param string $className EMongoDocument class name. If not provided, defaults
     *                          late-static binding so that the method does not have
     *                          to be overriden by child clases.
     *
     * @return EMongoDocument EMongoDocument model instance.
     * @since v1.0
     */
    public static function model($className = null)
    {
        if (null === $className) {
            $className = get_called_class();
        }

        if (isset(self::$_models[$className])) {
            return self::$_models[$className];
        } else {
            $model = self::$_models[$className] = new $className(null);
            $model->attachBehaviors($model->behaviors());
            return $model;
        }
    }

    /**
     * @since v1.2.2
     */
    protected function createPkCriteria($pk, $multiple = false)
    {
        $pkField = $this->primaryKey();
        $criteria = new EMongoCriteria();

        if (is_string($pkField)) {
            if (!$multiple) {
                $criteria->{$pkField} = $pk;
            } else {
                $criteria->{$pkField}('in', $pk);
            }
        } elseif (is_array($pkField)) {
            if (!$multiple) {
                for ($i=0; $i<count($pkField); $i++) {
                    $criteria->{$pkField[$i]} = $pk[$i];
                }
            } else {
                throw new EMongoException(
                    Yii::t(
                        'yii',
                        'Cannot create PK criteria for multiple composite '
                        . 'key\'s (not implemented yet)'
                    )
                );
            }
        }

        return $criteria;
    }

    /**
     * This method does the actual convertion to an array.
     * Ensures the primary key field(s) are included regardless of attributeNames().
     *
     * @see EMongoEmbeddedDocument::_toArray()
     * @return array an associative array of the contents of this object
     * @since v1.4.0
     */
    protected function _toArray()
    {
        $arr = parent::_toArray();

        // Ensure the primary key is always included as it may not be in
        // attributeNames
        $pk = $this->primaryKey();
        if (is_array($pk)) {
            foreach ($pk as $attribute) {
                $arr[$attribute] = $this->{$attribute};
            }
        } else {
            $arr[$pk] = $this->{$pk};
        }

        return $arr;
    }

    /**
     * Custom rule to ensure an attribute is unique. Overrides CActiveRecord-based
     * {@link CUniqueValidator} by being an inline validator and proxies validation
     * to EMongoUniqueValidator.
     *
     * @param string $attribute Attribute to be validated
     * @param array  $params    Parameters passed to EMongoUniqueValidator
     *
     * @see EMongoUniqueValidator
     * @since v1.4.0
     */
    public function uniqueValidator($attribute, $params)
    {
        // Proxy to EMongoUniqueValidator
        $validator = CValidator::createValidator(
            'MongoDb\\extra\\EMongoUniqueValidator',
            $this,
            $attribute,
            $params
        );
        $validator->validate($this, array($attribute));
    }
}
