<?php
/**
 * EMongoGridFS.php
 *
 * PHP version 5.3+
 *
 * @author      Jose Martinez <jmartinez@ibitux.com>
 * @author      Philippe Gaultier <pgaultier@ibitux.com>
 * @author      Dariusz Górecki <darek.krk@gmail.com>
 * @author      Invenzzia Group, open-source division of CleverIT company http://www.invenzzia.org
 * @copyright   2011 Ibitux
 * @license     http://www.yiiframework.com/license/ BSD license
 * @version     SVN: $Revision: $
 * @category    ext
 * @package     ext.YiiMongoDbSuite
 * @since       v1.3
 */

namespace YiiMongoDbSuite;

use \CDbException;
use \CException;
use \CLogger;
use \MongoId;
use \MongoException;
use \MongoGridFSFile;
use \Yii;

/**
 * EMongoGridFS
 *
 * @author      Jose Martinez <jmartinez@ibitux.com>
 * @author      Philippe Gaultier <pgaultier@ibitux.com>
 * @author      Dariusz Górecki <darek.krk@gmail.com>
 * @author      Invenzzia Group, open-source division of CleverIT company http://www.invenzzia.org
 * @copyright   2011 Ibitux
 * @license     http://www.yiiframework.com/license/ BSD license
 * @version     SVN: $Revision: $
 * @category    ext
 * @package     ext.YiiMongoDbSuite
 * @since       v1.3
 *
 */
abstract class EMongoGridFS extends EMongoDocument
{
    /**
     * MongoGridFSFile will be stored here
     * @var MongoGridFSFile
     */
    private $_gridFSFile;

    /**
     * Every EMongoGridFS object has to have one
     * @var String $filename
     * @since v1.3
     */
    public $filename = null; // mandatory

    /**
     * Returns current MongoGridFS object
     * By default this method use {@see getCollectionName()}
     * @return MongoGridFS
     * @since v1.3
     */
    public function getCollection()
    {
        if (!isset(self::$_collections[$this->getCollectionName()])) {
            self::$_collections[$this->getCollectionName()]
                = $this->getDb()->getGridFS($this->getCollectionName());
        }

        return self::$_collections[$this->getCollectionName()];
    }

    /**
     * Inserts a row into the table based on this active record attributes.
     * If the table's primary key is auto-incremental and is null before insertion,
     * it will be populated with the actual value after insertion.
     * Note, validation is not performed in this method. You may call
     * {@link validate} to perform the validation. After the record is inserted to
     * DB successfully, its {@link isNewRecord} property will be set false, and its
     * {@link scenario} property will be set to be 'update'.
     *
     * @param array $attributes list of attributes that need to be saved. Defaults
     *                          to null, meaning all attributes that are loaded from
     *                          DB will be saved.
     *
     * @return boolean whether the attributes are valid and the record is inserted
     *                 successfully.
     * @throws CDbException if the record is not new
     * @since v1.3
     */
    public function insert(array $attributes = null)
    {
        if (!$this->getIsNewRecord()) {
            throw new CDbException(
                Yii::t(
                    'yii',
                    'The EMongoDocument cannot be inserted to '
                    . 'database because it is not new.'
                )
            );
        }
        if (! $this->beforeSave()) {
            return false;
        }
        Yii::trace(get_class($this) . '.insert()', 'MongoDb.EMongoGridFS');
        $rawData = $this->toArray();
        // free the '_id' container if empty, mongo will not populate it if exists
        if (empty($rawData['_id'])) {
            unset($rawData['_id']);
        }

        return $this->insertData($rawData, $attributes);
    }

    /**
     * Insertion by Primary Key inserts a MongoGridFSFile forcing the MongoID
     *
     * @param MongoId $pk
     * @param array   $attributes
     *
     * @throws CDbException If PK is not a MongoId
     * @throws CException
     * @return boolean whether the insert success
     * @since v1.3
     */
    public function insertWithPk($pk, array $attributes = null)
    {
        if (!($pk instanceof MongoId)) {
            throw new CDbException(
                Yii::t(
                    'yii',
                    'The EMongoDocument cannot be inserted to database '
                    . 'primary key is not defined.'
                )
            );
        }
        if (!$this->beforeSave()) {
            return false;
        }
        Yii::trace(get_class($this) . '.insertWithPk()', 'MongoDb.EMongoGridFS');
        $rawData = $this->toArray();
        $rawData['_id'] = $pk;

        return $this->insertData($rawData, $attributes);
    }

    /**
     * Consolidated logic for insert() and insertWithPk() for inserting a new GridFS
     * document.
     * Expects beforeSave() and method specific validation checks to be performed
     * before calling this method.
     *
     * @param array      $rawData    Data to be saved (with filename)
     * @param array|null $attributes Attributes to be filtered from raw data
     *
     * @return boolean Success of insert
     * @throws EMongoException If failed to persist document
     * @throws CException If a filename was not specified
     */
    protected function insertData(array $rawData, $attributes)
    {
        // filter attributes if set in param
        if (null !== $attributes) {
            foreach ($rawData as $key => $value) {
                if (!in_array($key, $attributes)) {
                    unset($rawData[$key]);
                }
            }
        }

        // check file
        if (empty($rawData['filename'])) {
            throw new CException(Yii::t('yii', 'We need a filename'));
        } else {
            $filename = $rawData['filename'];
            unset($rawData['filename']);
        }

        $profile = $this->getEnableProfiler();
        if ($profile) {
            $profile = EMongoCriteria::commandToString(
                'write',
                $this->getCollectionName(),
                $filename,
                $rawData
            );
            Yii::beginProfile($profile, 'system.db.EMongoGridFS');
        }

        try {
            $result = $this->getCollection()->put(
                $filename,
                $rawData,
                array('w' => $this->getSafeFlag(), 'j' => $this->getFsyncFlag())
            );
        } catch (MongoException $ex) {
            Yii::log(
                'Failed to send put(); retrying: ' . PHP_EOL . 'Error: '
                . $ex->getMessage(),
                CLogger::LEVEL_WARNING
            );
            $result = $this->getCollection()->put(
                $filename,
                $rawData,
                array('w' => $this->getSafeFlag(), 'j' => $this->getFsyncFlag())
            );
        }
        if ($profile) {
            Yii::endProfile($profile, 'system.db.EMongoGridFS');
        }
        // strict comparsion driver may return empty array
        if ($result !== false) {
            if ($profile) {
                $criteria = new EMongoCriteria(
                    array('conditions' => array('_id' => $this->_id))
                );
                $profile = EMongoCriteria::findToString(
                    $criteria,
                    false,
                    $this->getCollectionName()
                );
                Yii::beginProfile($profile, 'system.db.EMongoGridFS');
            }
            $this->_id = $result;

            try {
                $this->_gridFSFile = $this->getCollection()->findOne(
                    array('_id' => $this->_id)
                );
            } catch (MongoException $ex) {
                Yii::log(
                    'Failed to send insert(); retrying: ' . PHP_EOL . 'Error: '
                    . $ex->getMessage(),
                    CLogger::LEVEL_WARNING
                );
                $this->_gridFSFile = $this->getCollection()->findOne(
                    array('_id' => $this->_id)
                );
            }
            if ($profile) {
                Yii::endProfile($profile, 'system.db.EMongoGridFS');
            }

            $this->setIsNewRecord(false);
            $this->setScenario('update');
            $this->afterSave();
            return true;
        }

        throw new EMongoException(Yii::t('yii', 'Failed to save document'));
    }

    /**
     * Updates the row represented by this active record.
     * All loaded attributes will be saved to the database.
     * Note, validation is not performed in this method. You may call
     * {@link validate} to perform the validation.
     *
     * @param array $attributes list of attributes that need to be saved. Defaults
     *                          to null, meaning all attributes that are loaded from
     *                          DB will be saved.
     * @param bool  $modify     Ignored. Kept for compliance with parent definition
     *
     * @return boolean whether the update is successful
     * @throws CDbException if the record is new
     * @since v1.3
     */
    public function update(array $attributes = null, $modify = true)
    {
        Yii::trace(get_class($this) . '.update()', 'MongoDb.EMongoGridFS');
        if ($this->getIsNewRecord()) {
            throw new CDbException(
                Yii::t(
                    'yii',
                    'The EMongoDocument cannot be updated because it is new.'
                )
            );
        }

        if ((null === $attributes || in_array('filename', $attributes))
            && is_file($this->filename)
        ) {
            if ($this->deleteByPk($this->_id) !== false) {
                $result = $this->insertWithPk($this->_id, $attributes);
                return ($result === true);
            }
        } else {
            return parent::update($attributes, true);
        }
    }

    /**
     * Creates an EMongoGridFS with the given attributes.
     * This method is internally used by the find methods.
     *
     * @param MongoGridFSFile $document      Mongo gridFSFile
     * @param boolean         $callAfterFind whether to call {@link afterFind} after
     *                                       the record is populated.
     *
     * @return EMongoGridFS|null the newly created document. The class of the
     *                           object is the same as the model class. Null is
     *                           returned if the input data is false.
     * @since v1.3
     */
    public function populateRecord($document, $callAfterFind = true)
    {
        Yii::trace(get_class($this) . '.populateRecord()', 'MongoDb.EMongoGridFS');
        if ($document instanceof MongoGridFSFile) {
            $model = parent::populateRecord($document->file, $callAfterFind);
            $model->_gridFSFile = $document;
            return $model;
        } else {
            return parent::populateRecord($document, $callAfterFind);
        }
    }

    /**
     * Returns the file size
     * GetSize wrapper of MongoGridFSFile function
     *
     * @return integer|false file size or false is returned if error
     * @since v1.3
     */
    public function getSize()
    {
        Yii::trace(get_class($this) . '.getSize()', 'MongoDb.EMongoGridFS');
        if (method_exists($this->_gridFSFile, 'getSize')) {
            return $this->_gridFSFile->getSize();
        } else {
            return false;
        }
    }

    /**
     * Returns the filename
     * GetFilename wrapper of MongoGridFSFile function
     *
     * @return string|false filename or false is returned if error
     * @since v1.3
     */
    public function getFilename()
    {
        Yii::trace(get_class($this) . '.getFilename()', 'MongoDb.EMongoGridFS');
        if (method_exists($this->_gridFSFile, 'getFilename')) {
            return $this->_gridFSFile->getFilename();
        } else {
            return false;
        }
    }

    /**
     * Returns the file's contents as a string of bytes
     * getBytes wrapper of MongoGridFSFile function
     *
     * @return string|false string of bytes or false is returned if error
     * @since v1.3
     */
    public function getBytes()
    {
        Yii::trace(get_class($this) . '.getBytes()', 'MongoDb.EMongoGridFS');
        if (method_exists($this->_gridFSFile, 'getBytes')) {
            return $this->_gridFSFile->getBytes();
        } else {
            return false;
        }
    }

    /**
     * Writes this file to the system
     *
     * @param string $filename The location to which to write the file. If none is
     *                         given, the stored filename will be used.
     *
     * @return integer|false number of bytes written or false if no document loaded
     * @since v1.3
     */
    public function write($filename = null)
    {
        Yii::trace(get_class($this) . '.write()', 'MongoDb.EMongoGridFS');
        if (method_exists($this->_gridFSFile, 'write') === true) {
            return $this->_gridFSFile->write($filename);
        } else {
            return false;
        }
    }

    /**
     * Return underlying GridFS document.
     * Method is protected to allow class to be extensible.
     *
     * @return MongoGridFSFile|null Document if loaded
     */
    protected function getGridFsFile()
    {
        return $this->_gridFSFile;
    }


    /**
     * Set the underlying GridFS document.
     * Method is protected to allow class to be extensible.
     * Note: the MongoGridFSFile is currently only written to the database on insert
     * or update if the file is persisted locally ({@see is_file()}).
     *
     * @param MongoGridFSFile $file MongoGridFsFile.
     */
    protected function setGridFsFile(MongoGridFSFile $file)
    {
        $this->_gridFSFile = $file;
    }
}
