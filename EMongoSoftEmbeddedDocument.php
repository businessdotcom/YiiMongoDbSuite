<?php
/**
 * An embedded document that may contain attributes. Unlike EMongoSoftDocument, if
 * the soft attribute value's is null, the attribute will not be persisted.
 * Additionally, soft attributes are predefined in the class, not dynamically added.
 *
 * PHP version 5.3+
 *
 * @author    Steven Hadfield <steven.hadfield@business.com>
 * @copyright 2014 Business.com Media Inc
 * @license   http://www.yiiframework.com/license/ BSD license
 * @version   1.4.0
 * @category  ext
 * @package   ext.YiiMongoDbSuite
 * @since     v1.4.0
 */

namespace YiiMongoDbSuite;

/**
 * EMongoSoftEmbeddedDocument class
 * @since v1.4.0
 */
abstract class EMongoSoftEmbeddedDocument extends EMongoEmbeddedDocument
{
    /**
     * Array that holds initialized soft attributes
     * @var array $softAttributes
     * @since v1.4.0
     */
    protected $softAttributes = array();

    /**
     * Adds soft attributes support to magic __get method
     * @see EMongoEmbeddedDocument::__get()
     * @since v1.4.0
     */
    public function __get($name)
    {
        if (array_key_exists($name, $this->softAttributes)) {
            return $this->softAttributes[$name];
        } else {
            return parent::__get($name);
        }
    }

    /**
     * Adds soft attributes support to magic __set method
     * @see EMongoEmbeddedDocument::__set()
     * @since v1.4.0
     */
    public function __set($name, $value)
    {
        if (array_key_exists($name, $this->softAttributes)) {
            $this->softAttributes[$name] = $value;
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * Adds soft attributes support to magic __isset method.
     * Checks to see if a soft attribute is defined for the given name and whether
     * it has a value of null. If the attribute is not a soft attribute, then this
     * call falls back to parent logic.
     *
     * @param string $name Attribute name to check if it is set
     *
     * @see EMongoEmbeddedDocument::__isset()
     * @return boolean if attribute is defined and not null
     * @since v1.4.0
     */
    public function __isset($name)
    {
        if (array_key_exists($name, $this->softAttributes)) {
            return null !== $this->softAttributes[$name];
        } else {
            return parent::__isset($name);
        }
    }

    /**
     * Adds soft attributes support to magic __unset method.
     * If the attribute is a defined soft attribute, then the soft attribute value
     * is set to null. If the attribute is not a soft attribute, then this call
     * falls back to parent logic.
     *
     * @param string $name Attribute name to unset
     *
     * @see CComponent::__unset()
     * @since v1.4.0
     */
    public function __unset($name)
    {
        if (array_key_exists($name, $this->softAttributes)) {
            $this->softAttributes[$name] = null;
        } else {
            parent::__unset($name);
        }
    }

    /**
     * Return the list of attribute names of this model, with respect of initialized soft attributes
     *
     * @see EMongoEmbeddedDocument::attributeNames()
     * @since v1.4.0
     */
    public function attributeNames()
    {
        return array_merge(
            array_keys($this->softAttributes),
            parent::attributeNames()
        );
    }

    /**
     * This method does the actual convertion to an array. Includes all non-null soft
     * attributes.
     * Does not fire any events
     *
     * @return array an associative array of the contents of this object
     * @since v1.4.0
     */
    protected function _toArray()
    {
        $arr = array();
        $embeddedDocs = $this->embeddedDocuments();
        // Iterate over parent list of attribute names to exclude soft attributes
        foreach (parent::attributeNames() as $name) {
            if (isset($embeddedDocs[$name])) {
                // Only populate embedded document if not null
                if (null !== $this->_embedded->itemAt($name)) {
                    $arr[$name] = $this->_embedded[$name]->toArray();
                }
                // Note: unlike EMongoEmbeddedDocument, we do not set attribute to
                // null if the embedded document is null
            } else {
                $arr[$name] = $this->{$name};
            }
        }

        foreach ($this->softAttributes as $key => $value) {
            if (null === $value) {
                // when a softAttribute has a NULL value, it wants to be excluded
                unset($arr[$key]);
            } else {
                $arr[$key]=$value;
            }
        }

        return $arr;
    }

    /**
     * Return the actual list of soft attributes being used by this model
     *
     * @return array list of initialized soft attributes
     * @since v1.4.0
     */
    public function getSoftAttributeNames()
    {
        return array_keys($this->softAttributes);
    }
}
