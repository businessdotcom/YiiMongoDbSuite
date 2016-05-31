<?php
/**
 * EMongoEmbeddedDocument.php
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
 * @since       v1.0.8
 */

namespace YiiMongoDbSuite;

use \CException;
use \CMap;
use \CModelEvent;
use \ReflectionClass;

/**
 * @since v1.0.8
 */
abstract class EMongoEmbeddedDocument extends \CModel
{
    private static $_attributes = array();

    /**
     * CMap of embedded documents
     * @var CMap $_embedded
     * @since v1.0.8
     */
    protected $_embedded = null;

    /**
     * Cached values for embeddedDocuments() method
     * @var array $_embeddedConfig
     * @since v1.3.2
     */
    protected static $_embeddedConfig = array();

    /**
     * Hold down owner pointer (if any)
     *
     * @var EMongoEmbeddedDocument $_owner
     * @since v1.0.8
     */
    protected $_owner = null;

    /**
     * Constructor.
     * @param string $scenario name of the scenario that this model is used in.
     * See {@link CModel::scenario} on how scenario is used by models.
     * @see getScenario
     * @since v1.0.8
     */
    public function __construct($scenario = 'insert')
    {
        $this->setScenario($scenario);
        $this->init();
        $this->attachBehaviors($this->behaviors());
        $this->afterConstruct();

        $this->initEmbeddedDocuments();
    }

    /**
     * Initializes this model.
     * This method is invoked in the constructor right after {@link scenario} is set.
     * You may override this method to provide code that is needed to initialize the model (e.g. setting
     * initial property values.)
     * @since 1.0.8
     */
    public function init()
    {
    }

    /**
     * @since v1.0.8
     */
    protected function initEmbeddedDocuments()
    {
        if (!$this->hasEmbeddedDocuments() || !$this->beforeEmbeddedDocsInit()) {
            return false;
        }

        $this->_embedded = new CMap;
        if (!isset(self::$_embeddedConfig[get_class($this)])) {
            self::$_embeddedConfig[get_class($this)] = $this->embeddedDocuments();
        }
        $this->afterEmbeddedDocsInit();
    }

    /**
     * @since v1.0.8
     */
    public function onBeforeEmbeddedDocsInit($event)
    {
        $this->raiseEvent('onBeforeEmbeddedDocsInit', $event);
    }

    /**
     * @since v1.0.8
     */
    public function onAfterEmbeddedDocsInit($event)
    {
        $this->raiseEvent('onAfterEmbeddedDocsInit', $event);
    }

    /**
     * @since v1.0.8
     */
    public function onBeforeToArray($event)
    {
        $this->raiseEvent('onBeforeToArray', $event);
    }

    /**
     * @since v1.0.8
     */
    public function onAfterToArray($event)
    {
        $this->raiseEvent('onAfterToArray', $event);
    }

    /**
     * Method to determine whether an array conversion should be allowed to continue
     *
     * @since v1.0.8
     * @return boolean Whether the array conversion should continue.
     */
    protected function beforeToArray()
    {
        $event = new CModelEvent($this);
        $this->onBeforeToArray($event);
        return $event->isValid;
    }

    /**
     * @since v1.0.8
     */
    protected function afterToArray()
    {
        $this->onAfterToArray(new CModelEvent($this));
    }

    /**
     * Method to determine whether a call to initialize embedded documents should be
     * allowed to continue.
     *
     * @since v1.0.8
     * @return boolean Whether the embeddedDocsInit event should be preformed
     */
    protected function beforeEmbeddedDocsInit()
    {
        $event = new CModelEvent($this);
        $this->onBeforeEmbeddedDocsInit($event);
        return $event->isValid;
    }

    /**
     * @since v1.0.8
     */
    protected function afterEmbeddedDocsInit()
    {
        $this->onAfterEmbeddedDocsInit(new CModelEvent($this));
    }

    /**
     * @since v1.0.8
     */
    public function __get($name)
    {
        if ($this->hasEmbeddedDocuments()
            && isset(self::$_embeddedConfig[get_class($this)][$name])
        ) {
            // Late creation of embedded documents on first access
            if (null === $this->_embedded->itemAt($name)) {
                $docClassName = self::$_embeddedConfig[get_class($this)][$name];
                if (is_array($docClassName)) {
                    // Check for a default class to use if not initialized yet
                    if (isset($docClassName['default'])) {
                        $docClassName = $docClassName['default'];
                    } else {
                        return null;
                    }
                }
                $doc = new $docClassName($this->getScenario());
                $doc->setOwner($this);
                $this->_embedded->add($name, $doc);
            }

            return $this->_embedded->itemAt($name);
        } else {
            return parent::__get($name);
        }
    }

    /**
     * @since v1.0.8
     */
    public function __set($name, $value)
    {
        if ($this->hasEmbeddedDocuments()
            && isset(self::$_embeddedConfig[get_class($this)][$name])
        ) {
            if (is_array($value)) {
                $docClassName = self::$_embeddedConfig[get_class($this)][$name];
                if (is_array($docClassName)) {
                    if (! isset($docClassName['classField'])) {
                        throw new CException(
                            'Invalid embeddedDocument definition for'
                            . get_class($this) . '->' . $name
                        );
                    }
                    if (! empty($value[$docClassName['classField']])) {
                        $docClassName = (isset($docClassName['prefix']) ? $docClassName['prefix'] : '')
                            . $value[$docClassName['classField']];
                    } elseif (isset($docClassName['default'])) {
                        // Fallback to default if defined
                        $docClassName = $docClassName['default'];
                    } else {
                        throw new CException(
                            'Unable to determine embedded document class for '
                            . get_class($this) . '->' . $name
                        );
                    }
                }

                // Late creation of embedded documents on first access or if the
                // class has changed
                if (null === $this->_embedded->itemAt($name)
                    || get_class($this->_embedded->itemAt($name)) != $docClassName
                ) {
                    $doc = new $docClassName($this->getScenario());
                    $doc->setOwner($this);
                    $this->_embedded->add($name, $doc);
                }

                return $this->_embedded->itemAt($name)->attributes = $value;
            } elseif ($value instanceof EMongoEmbeddedDocument) {
                return $this->_embedded->add($name, $value);
            }
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * @since v1.3.2
     * @see CComponent::__isset()
     */
    public function __isset($name)
    {
        if ($this->hasEmbeddedDocuments()
            && isset(self::$_embeddedConfig[get_class($this)][$name])
        ) {
            if (isset($this->_embedded)) {
                return $this->_embedded->contains($name);
            } else {
                return false;
            }
        } else {
            return parent::__isset($name);
        }
    }

    /**
     * @since v1.4.1
     * @see CComponent::__unset()
     */
    public function __unset($name)
    {
        if ($this->hasEmbeddedDocuments()
            && isset(self::$_embeddedConfig[get_class($this)][$name])
        ) {
            if (isset($this->_embedded)) {
                $this->_embedded->remove($name);
            }
        } else {
            return parrent::__unset($name);
        }
    }

    /**
     * Embedded document definitions. Defined as an array of name to class mapping.
     *
     * For a dynamic class, the class name should be an array with the following
     * parameters: 'prefix' (optional), 'classField', 'default' (optional). The
     * resulting class name would be the prefix (if any) concatenated with the value
     * of the classField in the embedded document's value.
     * The default class is used when attempting the load the embedded document
     * without specifying any values. Otherwise null will be returned.
     * Example: an embedded document with an attribute 't' that contains the value
     * 'SoftEmbeddedDocument' and a prefix of 'EMongo' would result in an class of
     * 'EMongoSoftEmbeddedDocument' being generated.
     *
     *
     * @example array('property' => 'EMongoEmbeddedDocumentClass')
     * @example array('property' => array('prefix' => 'EMongo', 'classField' => 't'))
     *
     * @since v1.0.8
     * @return array
     */
    public function embeddedDocuments()
    {
        return array();
    }

    /**
     * Determine if this mjodel as defined embedded documents.
     *
     * @since v1.0.8
     * @see embeddedDocuments()
     * @return boolean Whether any embedded documents are configured for this model.
     */
    public function hasEmbeddedDocuments()
    {
        if (isset(self::$_embeddedConfig[get_class($this)])) {
            return true;
        }

        return count($this->embeddedDocuments()) > 0;
    }

    /**
     * Returns the list of attribute names.
     * By default, this method returns all public properties of the class and any
     * configured embedded documents.
     * You may override this method to change the default.
     *
     * @return array list of attribute names. Defaults to all public properties of the class.
     * @since v1.0.8
     */
    public function attributeNames()
    {
        $className = get_class($this);
        if (!isset(self::$_attributes[$className])) {
            $class = new ReflectionClass($className);
            $names = array();
            foreach ($class->getProperties() as $property) {
                $name = $property->getName();
                if ($property->isPublic() && !$property->isStatic()) {
                    $names[] = $name;
                }
            }
            if ($this->hasEmbeddedDocuments()) {
                if (! isset(self::$_embeddedConfig[$className])) {
                    self::$_embeddedConfig[get_class($this)] = $this->embeddedDocuments();
                }

                $names = array_merge(
                    $names,
                    array_keys(self::$_embeddedConfig[$className])
                );
            }
            self::$_attributes[$className] = $names;
        }

        return self::$_attributes[$className];
    }

    /**
     * Returns the given object as an associative array
     * Fires beforeToArray and afterToArray events
     * @return array an associative array of the contents of this object
     * @since v1.0.8
     */
    public function toArray()
    {
        if ($this->beforeToArray()) {
            $arr = $this->_toArray();
            $this->afterToArray();
            return $arr;
        } else {
            return array();
        }
    }

    /**
     * This method does the actual convertion to an array.
     * Includes all defined attributes in {@link attributeNames()}. If an embedded
     * document has not been loaded, it will have a return value of null.
     * Does not fire any events.
     *
     * @return array an associative array of the contents of this object
     * @since v1.3.4
     */
    protected function _toArray()
    {
        $arr = array();
        $embeddedDocs = $this->embeddedDocuments();
        foreach ($this->attributeNames() as $name) {
            if (isset($embeddedDocs[$name])) {
                // Only populate embedded document if not null
                if (null !== $this->_embedded->itemAt($name)) {
                    $arr[$name] = $this->_embedded[$name]->toArray();
                } else {
                    $arr[$name] = null;
                }
            } else {
                $arr[$name] = $this->{$name};
            }
        }

        return $arr;
    }

    /**
     * Return owner of this document if one has been set
     *
     * @return EMongoEmbeddedDocument|null Owner object
     * @since v1.0.8
     */
    public function getOwner()
    {
        return $this->_owner;
    }

    /**
     * Set owner of this document
     *
     * @param EMongoEmbeddedDocument $owner
     * @since v1.0.8
     */
    public function setOwner(EMongoEmbeddedDocument $owner)
    {
        $this->_owner = $owner;
    }

    /**
     * Override default seScenario method for populating to embedded records
     * @see CModel::setScenario()
     * @since v1.0.8
     */
    public function setScenario($value)
    {
        if ($this->hasEmbeddedDocuments() && $this->_embedded !== null) {
            foreach ($this->_embedded as $doc) {
                $doc->setScenario($value);
            }
        }
        parent::setScenario($value);
    }

    /**
     * Validate current document and all loaded or specified embedded documents.
     *
     * @param array|null $attributes  Attributes to validate, or null for all
     * @param boolean    $clearErrors Whether any previous errors should be cleared
     *                                before performing validation.
     *
     * @return boolean Whether the model is considered valid for the given parameters
     * @since v1.4.0
     */
    public function validate($attributes = null, $clearErrors = true)
    {
        $valid = parent::validate($attributes, $clearErrors);
        if ($this->hasEmbeddedDocuments()) {
            if (null === $attributes && isset($this->_embedded)) {
                foreach ($this->_embedded as $attribute => $embedded) {
                    if (! $embedded->validate(null, $clearErrors)) {
                        $valid = false;

                        // Populate error message with embedded document messages
                        if ($this->$attribute->hasErrors()) {
                            foreach ($this->$attribute->getErrors() as $errors) {
                                foreach ((array) $errors as $error) {
                                    $this->addError($attribute, $error);
                                }
                            }
                        }
                    }
                }
            } elseif (is_array($attributes)) {
                // If an embedded document is specified in $attributes, validate
                // regardless of whether its currently loaded or not
                foreach (array_keys($this->embeddedDocuments()) as $attribute) {
                    if (in_array($attribute, $attributes)
                        && ! $this->$attribute->validate(null, $clearErrors)
                    ) {
                        $valid = false;

                        // Populate error message with embedded document messages
                        if ($this->$attribute->hasErrors()) {
                            foreach ($this->$attribute->getErrors() as $errors) {
                                foreach ((array) $errors as $error) {
                                    $this->addError($attribute, $error);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $valid;
    }

    /**
     * Override parent getAttributes to ensure embedded documents without values are
     * not created
     *
     * @param array|null $names Attribute names to include. (default null for all)
     *
     * @return array
     * @see CModel::getAttributes()
     */
    public function getAttributes($names = null)
    {
        $values = array();
        $embeddedDocs = $this->embeddedDocuments();
        foreach ($this->attributeNames() as $name) {
            // Check if attribute is an embedded document and whether it has a value
            // or not
            if (! array_key_exists($name, $embeddedDocs)
                || (array_key_exists($name, $embeddedDocs)
                && null !== $this->_embedded->itemAt($name))
            ) {
                $values[$name] = $this->$name;
            } else {
                $values[$name] = null;
            }
        }

        if (is_array($names)) {
            $values2 = array();
            foreach ($names as $name) {
                $values2[$name] = isset($values[$name]) ? $values[$name] : null;
            }

            return $values2;
        } else {
            return $values;
        }
    }

    /**
     * When de-serializing the document, ensure static variables are setup (embedded
     * documents) and the model is initialized.
     *
     * @since v1.4.0
     */
    public function __wakeup()
    {
        // Ensure model setup
        $this->init();

        // Ensure the embedded document configuration is setup for this class
        if ($this->hasEmbeddedDocuments()) {
            self::$_embeddedConfig[get_class($this)] = $this->embeddedDocuments();
        }
    }
}
