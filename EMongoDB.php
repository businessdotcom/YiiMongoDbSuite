<?php
/**
 * EMongoDB.php
 *
 * PHP version 5.3+
 * Mongo version 1.3+
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

use \MongoClient;
use \MongoConnectionException;
use \Yii;

/**
 * EMongoDB
 *
 * This is merge work of tyohan, Alexander Makarov and mine
 * @since v1.0
 */
class EMongoDB extends \CApplicationComponent
{
    /**
     * @var string host:port
     *
     * Correct syntax is:
     * mongodb://[username:password@]host1[:port1][,host2[:port2:],...]
     *
     * @example mongodb://localhost:27017
     * @since v1.0
     */
    public $connectionString;

    /**
     * @var boolean $autoConnect whether the Mongo connection should be automatically established when
     * the component is being initialized. Defaults to true. Note, this property is only
     * effective when the EMongoDB object is used as an application component.
     * @since v1.0
     */
    public $autoConnect = true;

    /**
     * Name of the replica set (if any)
     * @see http://php.net/manual/en/mongo.connecting.rs.php
     * @var string|null
     */
    public $replicaSet;

    /**
     * Read preference used when reading from a replica set. Defaults to the closest
     * server.
     * @see http://php.net/manual/en/mongo.readpreferences.php
     * @var string
     * @example MongoClient::RP_PRIMARY
     */
    public $readPreference = MongoClient::RP_NEAREST;

    /**
     * @var string $dbName name of the Mongo database to use
     * @since v1.0
     */
    public $dbName = null;

    /**
     * @var MongoDB $_mongoDb instance of MongoDB driver
     */
    private $_mongoDb;

    /**
     * @var MongoClient $_mongoConnection instance of MongoDB driver
     */
    private $_mongoConnection;

    /**
     * If set to TRUE, all internal DB operations will use journaling flag with data
     * modification requests, in other words, all write operations will have to wait
     * for a disc sync!
     *
     * MongoDB default value for this flag is: false.
     *
     * @var boolean
     * @since v1.0
     */
    public $fsyncFlag = false;

    /**
     * Default write concern for all internal DB operations with data modification requests.
     *
     * MongoDB default value for this flag is: 1.
     * Value for EMongoDB is set to 0 for backwards compatibility.
     *
     * @var integer|string
     */
    public $safeFlag = 0;

    /**
     * If set to TRUE findAll* methods of models, will return {@see EMongoCursor} instead of
     * raw array of models.
     *
     * Generally you should want to have this set to TRUE as cursor use lazy-loading/instaninating of
     * models, this is set to FALSE, by default to keep backwards compatibility.
     *
     * Note: {@see EMongoCursor} does not implement ArrayAccess interface and cannot be used like an array,
     * because offset access to cursor is highly ineffective and pointless.
     *
     * @var boolean $useCursor state of Use Cursor flag (global scope)
     */
    public $useCursor = false;

    /**
     * Whether to generate profiler log messages.
     * @var boolean
     * @since v1.4.1
     */
    public $enableProfiler = false;

    /**
     * Storage location for temporary files used by the GridFS Feature.
     * If set to null, component will not use temporary storage
     * @var string $gridFStemporaryFolder
     */
    public $gridFStemporaryFolder = null;

    /**
     * If a connection failure occurs on load, this flag controls whether the connection should be retried.
     * @var boolean
     */
    public $autoReconnect = true;

    /**
     * Internal flag indicating whether the connection should be forcibly closed.
     * Work around for Mongo driver not clearing old persistent connections on connection failure
     * @var boolean
     */
    private $forceClose = false;

    /**
     * Connect to DB if connection is already connected this method doeas nothing
     * @since v1.0
     */
    public function connect()
    {
        if (!$this->getConnection()->connected) {
            return $this->getConnection()->connect();
        }
    }

    /**
     * Returns Mongo connection instance if not exists will create new
     *
     * @return MongoClient
     * @throws EMongoException
     * @since v1.0
     */
    public function getConnection()
    {
        if (null === $this->_mongoConnection) {
            $params = array('connect' => $this->autoConnect);

            if (!empty($this->replicaSet)) {
                $params['replicaSet']     = $this->replicaSet;
                $params['readPreference'] = $this->readPreference;
            }
            Yii::trace('Opening MongoDB connection', 'MongoDb.EMongoDB');
            if (empty($this->connectionString)) {
                throw new EMongoException(
                    Yii::t('yii', 'EMongoDB.connectionString cannot be empty.')
                );
            }
            try {
                $this->_mongoConnection = new MongoClient(
                    $this->connectionString,
                    $params
                );
            } catch (MongoConnectionException $e) {
                // To prevent the next request from also failing to connect, we need
                // to force the connection closed. Otherwise, the next connection
                // will have also have to connect again
                $this->forceClose = true;
                $failureMessage = 'EMongoDB failed to open connection: {error}';

                if ($this->autoReconnect) {
                    Yii::trace(
                        'MongoDB connection failure: ' . $e->getMessage(),
                        'MongoDb.EMongoDB'
                    );

                    try {
                        $this->_mongoConnection = new MongoClient(
                            $this->connectionString,
                            $params
                        );
                    } catch (MongoConnectionException $inner) {
                        throw new EMongoException(
                            Yii::t(
                                'yii',
                                $failureMessage,
                                array('{error}' => $inner->getMessage())
                            ),
                            $inner->getCode(),
                            $inner
                        );
                    }
                } else {
                    throw new EMongoException(
                        Yii::t(
                            'yii',
                            $failureMessage,
                            array('{error}'=>$e->getMessage())
                        ),
                        $e->getCode(),
                        $e
                    );
                }
            }
        }

        return $this->_mongoConnection;
    }

    /**
     * Set the connection
     *
     * @param MongoClient $connection
     *
     * @since v1.0
     */
    public function setConnection(MongoClient $connection)
    {
        $this->_mongoConnection = $connection;
    }

    /**
     * Get MongoDB instance
     *
     * @return MongoDB Mongo object with configured dbName
     * @since v1.0
     */
    public function getDbInstance()
    {
        if (null === $this->_mongoDb) {
            $this->setDbInstance($this->dbName);
        }

        return $this->_mongoDb;
    }

    /**
     * Set MongoDB instance
     *
     * @param string $name Name of Mongo database
     *
     * @since v1.0
     */
    public function setDbInstance($name)
    {
        $this->_mongoDb = $this->getConnection()->selectDB($name);
    }

    /**
     * Closes the currently active Mongo connection.
     * It does nothing if the connection is already closed.
     * @since v1.0
     */
    protected function close()
    {
        if (null !== $this->_mongoConnection) {
            $this->_mongoConnection->close();
            $this->_mongoConnection = null;
            Yii::trace('Closing MongoDB connection', 'MongoDb.EMongoDB');
        }
    }

    /**
     * Drop the current DB
     *
     * @since v1.0
     * @return boolean Whether the drop was successful
     */
    public function dropDb()
    {
        $result = $this->getDbInstance()->drop();
        return isset($result['ok']) && 1 == $result['ok'];
    }

    /**
     * Closes the persistent connection if a connection failure was detected earlier
     */
    public function __destruct()
    {
        if ($this->forceClose) {
            $this->close();
        }
    }
}
