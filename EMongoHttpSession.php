<?php

/**
 * @author   Ianaré Sévi (merge into EMongoDB)
 * @author   aoyagikouhei (original author)
 * @license  New BSD license
 * @version  1.3
 * @category ext
 * @package  ext.YiiMongoDbSuite
 */

namespace YiiMongoDbSuite;

use \CLogger;
use \MongoException;
use \Yii;

/**
 * EMongoHttpSession
 *
 * Example, in config/main.php:
 *     'session' => array(
 *         'class' => 'ext.EMongoDbHttpSession',
 *         'collectionName' => 'yiisession',
 *         'idColumn' => 'id',
 *         'dataColumn' => 'data',
 *         'expireColumn' => 'expire',
 *     ),
 *
 * Options:
 * connectionID         : mongo component name          : default mongodb
 * collectionName       : collaction name               : default yiisession
 * idColumn             : id column name                : default id
 * dataColumn           : data column name              : default dada
 * expireColumn         : expire column name            : default expire
 * fsync                : journaling flag               : default false
 * safe                 : write concern                 : default 0
 * queryTimeout         : timeout miliseconds           : default null
 *
 */
class EMongoHttpSession extends \CHttpSession
{
    /**
     * @var string Mongo DB component.
     */
    public $connectionID = 'mongodb';
    /**
     * @var string Collection name
     */
    public $collectionName = 'yiisession';
    /**
     * @var string id column name
     */
    public $idColumn = 'id';
    /**
     * @var string level data name
     */
    public $dataColumn = 'data';
    /**
     * @var string expire column name
     */
    public $expireColumn = 'expire';
    /**
     * @var boolean forces the update to be synced to disk before returning success.
     */
    protected $fsync = false;
    /**
     * Controls whether/how to wait for a database response
     * @var integer|string
     */
    protected $safe = 0;
    /**
     * @var integer if "safe" is set, this sets how long (in milliseconds) for the client to wait for a database response.
     */
    public $updateTimeout = null;
    /**
     * Whether or not the query profiler should be enabled
     * @var boolean
     */
    public $profiler = false;
    /**
     * Optional override for the read preference of session operations
     * @see MongoCollection::setReadPreference
     * @var string
     */
    public $readPreference;
    /**
     * @var array insert options
     */
    private $_options;
    /**
     * @var MongoCollection mongo Db collection
     */
    private $_collection;

    /**
     * Returns current MongoCollection object.
     * @return MongoCollection
     */
    protected function setCollection($collectionName)
    {
        if (!isset($this->_collection)) {
            $db = Yii::app()->getComponent($this->connectionID);
            if (!($db instanceof EMongoDB)) {
                throw new EMongoException(
                    'EMongoHttpSession.connectionID is invalid'
                );
            }

            $this->_collection = $db->getDbInstance()->selectCollection(
                $collectionName
            );
            if ($this->readPreference) {
                $this->_collection->setReadPreference($this->readPreference);
            }
        }
        return $this->_collection;
    }

    /**
     * Initializes the route.
     * This method is invoked after the route is created by the route manager.
     */
    public function init()
    {
        $this->setCollection($this->collectionName);
        $this->_options = array(
            'j' => $this->fsync,
            'w' => $this->safe
        );
        if (null !== $this->updateTimeout) {
            $this->_options['socketTimeoutMS'] = $this->updateTimeout;
        }
        parent::init();
    }

    protected function getData($id)
    {
        if ($this->profiler) {
            $criteria = new EMongoCriteria(
                array(
                    'conditions' => array($this->idColumn => $id),
                    'select'     => array($this->dataColumn => true)
                )
            );
            $profile = EMongoCriteria::findToString(
                $criteria,
                false,
                $this->collectionName
            );
            Yii::beginProfile($profile, 'system.db.EMongoHttpSession');
        }
        try {
            $data = $this->_collection->findOne(
                array($this->idColumn => $id),
                array($this->dataColumn => true)
            );
        } catch (MongoException $ex) {
            // Try again if switching master or timeout
            Yii::log(
                'Failed attempting to retrieve session data; trying again.'
                . PHP_EOL . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );

            $data = $this->_collection->findOne(
                array($this->idColumn => $id),
                array($this->dataColumn => true)
            );
        }
        if ($this->profiler && isset($profile)) {
            Yii::endProfile($profile, 'system.db.EMongoHttpSession');
        }

        return $data;
    }

    protected function getExipireTime()
    {
        return time() + $this->getTimeout();
    }

    /**
     * Returns a value indicating whether to use custom session storage.
     * This method overrides the parent implementation and always returns true.
     * @return boolean whether to use custom storage.
     */
    public function getUseCustomStorage()
    {
        return true;
    }

    /**
     * Session open handler.
     * Do not call this method directly.
     *
     * @param string $savePath session save path
     * @param string $sessionName session name
     *
     * @return boolean whether session is opened successfully
     */
    public function openSession($savePath, $sessionName)
    {
        $this->gcSession(0);
    }

    /**
     * Session read handler.
     * Do not call this method directly.
     *
     * @param string $id session ID
     *
     * @return string the session data
     */
    public function readSession($id)
    {
        $row = $this->getData($id);
        return is_null($row) ? '' : $row[$this->dataColumn];
    }

    /**
     * Session write handler.
     * Do not call this method directly.
     *
     * @param string $id   session ID
     * @param string $data session data
     *
     * @return boolean whether session write is successful
     */
    public function writeSession($id, $data)
    {
        $options = $this->_options;
        $options['upsert'] = true;
        $data = array(
            $this->dataColumn   => $data,
            $this->expireColumn => $this->getExipireTime(),
            $this->idColumn     => $id
        );
        if ($this->profiler) {
            $profile = EMongoCriteria::commandToString(
                'update',
                $this->collectionName,
                $data,
                array('upsert' => true)
            );
            Yii::beginProfile($profile, 'system.db.EMongoHttpSession');
        }
        try {
            $result = $this->_collection->update(
                array($this->idColumn => $id),
                $data,
                $options
            );
        } catch (MongoException $ex) {
            // Try again if switching master or timeout
            Yii::log(
                'Failed attempting to write session; trying again.'
                . PHP_EOL . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );

            $result = $this->_collection->update(
                array($this->idColumn => $id),
                $data,
                $options
            );
        }
        if ($this->profiler && isset($profile)) {
            Yii::endProfile($profile, 'system.db.EMongoHttpSession');
        }

        return $result;
    }

    /**
     * Session destroy handler.
     * Do not call this method directly.
     *
     * @param string $id session ID
     *
     * @return boolean whether session is destroyed successfully
     */
    public function destroySession($id)
    {
        if ($this->profiler) {
            $profile = EMongoCriteria::commandToString(
                'remove',
                $this->collectionName,
                array($this->idColumn => $id)
            );
            Yii::beginProfile($profile, 'system.db.EMongoHttpSession');
        }
        try {
            $result = $this->_collection->remove(
                array($this->idColumn => $id),
                $this->_options
            );
        } catch (MongoException $ex) {
            // Try again if switching master or timeout
            Yii::log(
                'Failed attempting to destroy session; trying again.'
                . PHP_EOL . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );

            $result = $this->_collection->remove(
                array($this->idColumn => $id),
                $this->_options
            );
        }
        if ($this->profiler && isset($profile)) {
            Yii::endProfile($profile, 'system.db.EMongoHttpSession');
        }

        return $result;
    }

    /**
     * Session GC (garbage collection) handler.
     * Do not call this method directly.
     *
     * @param integer $maxLifetime the number of seconds after which data will be
     *                             seen as 'garbage' and cleaned up.
     *
     * @return boolean whether session is GCed successfully
     */
    public function gcSession($maxLifetime)
    {
        $query = array($this->expireColumn => array('$lt' => time()));
        if ($this->profiler) {
            $profile = EMongoCriteria::commandToString(
                'remove',
                $this->collectionName,
                $query
            );
            Yii::beginProfile($profile, 'system.db.EMongoHttpSession');
        }
        try {
            $result = $this->_collection->remove($query, $this->_options);
        } catch (MongoException $ex) {
            // Try again if switching master or timeout
            Yii::log(
                'Failed attempting to gc session; trying again.'
                . PHP_EOL . 'Error: ' . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );

            $result = $this->_collection->remove($query, $this->_options);
        }
        if ($this->profiler && isset($profile)) {
            Yii::endProfile($profile, 'system.db.EMongoHttpSession');
        }

        return $result;
    }

    /**
     * Updates the current session id with a newly generated one.
     * Please refer to {@link http://php.net/session_regenerate_id} for more details.
     *
     * @param boolean $deleteOldSession Whether to delete the old associated session
     *                                  file or not.
     */
    public function regenerateID($deleteOldSession = false)
    {
        $oldId = session_id();
        parent::regenerateID(false);
        $newId = session_id();
        $row = $this->getData($oldId);
        if (null === $row) {
            $data = array(
                $this->idColumn     => $newId,
                $this->expireColumn => $this->getExipireTime(),
            );
            if ($this->profiler) {
                $profile = EMongoCriteria::commandToString(
                    'insert',
                    $this->collectionName,
                    $data
                );
                Yii::beginProfile($profile, 'system.db.EMongoHttpSession');
            }
            try {
                $this->_collection->insert($data, $this->_options);
            } catch (MongoException $e) {
                // Try again if switching master or timeout
                Yii::log(
                    'Failed attempting to persist session; trying again.' . PHP_EOL
                    . 'Error: ' . $e->getMessage(),
                    CLogger::LEVEL_WARNING
                );

                $this->_collection->insert($data, $this->_options);
            }
        } elseif ($deleteOldSession && '_id' !== $this->idColumn) {
            if ($this->profiler) {
                $profile = EMongoCriteria::commandToString(
                    'update',
                    $this->collectionName,
                    array($this->idColumn => $oldId),
                    array($this->idColumn => $newId)
                );
                Yii::beginProfile($profile, 'system.db.EMongoHttpSession');
            }
            try {
                $this->_collection->update(
                    array($this->idColumn => $oldId),
                    array($this->idColumn => $newId),
                    $this->_options
                );
            } catch (MongoException $e) {
                // Try again if switching master or timeout
                Yii::log(
                    'Failed attempting to update session; trying again.' . PHP_EOL
                    . 'Error: ' . $e->getMessage(),
                    CLogger::LEVEL_WARNING
                );

                $this->_collection->update(
                    array($this->idColumn => $oldId),
                    array($this->idColumn => $newId),
                    $this->_options
                );
            }
        } else {
            unset($row['_id']); // unset before in case idColumn is _id
            $row[$this->idColumn] = $newId;
            if ($this->profiler) {
                $profile = EMongoCriteria::commandToString(
                    'insert',
                    $this->collectionName,
                    $row
                );
                Yii::beginProfile($profile, 'system.db.EMongoHttpSession');
            }
            try {
                $this->_collection->insert($row, $this->_options);
            } catch (MongoException $e) {
                // Try again if switching master or timeout
                Yii::log(
                    'Failed attempting to persist session; trying again.' . PHP_EOL
                    . 'Error: ' . $e->getMessage(),
                    CLogger::LEVEL_WARNING
                );
                $this->_collection->insert($row, $this->_options);
            }
        }

        if ($this->profiler && isset($profile)) {
            Yii::endProfile($profile, 'system.db.EMongoHttpSession');
        }
    }

    /**
     * Set the file-sync mode flag for insert/update
     *
     * @param boolean $fsync Whether fsync should be set
     */
    public function setFsync($fsync)
    {
        $this->fsync = (bool) $fsync;
        $this->_options['j'] = (bool) $fsync;
    }

    /**
     * Set the write concern for insert/update
     *
     * @param string|integer $safe Write concern to be set
     */
    public function setSafe($safe)
    {
        $this->safe = $safe;
        $this->_options['w'] = $safe;
    }
}
