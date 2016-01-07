<?php
/**
 * EMongoUniqueValidator.php
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
 * @since       v1.1
 */

namespace YiiMongoDbSuite\extra;

use \YiiMongoDbSuite\EMongoCriteria;
use \YiiMongoDbSuite\EMongoDocument;
use \Yii;

/**
 * @since v1.1
 */
class EMongoUniqueValidator extends \CValidator
{
    public $allowEmpty = true;

    /**
     * Additional query criteria to use for uniqueness check.
     * If value is an array, it will be passed to the EMongoCriteria constructor as
     * 'conditions' parameter.
     * @var array|EMongoCriteria
     * @see EMongoCriteria::__construct()
     */
    public $criteria;

    /**
     * Include a list of additional attributes to include (as a compound key)
     * This is necessary since a validator rule is set once, and so including an
     * attribute's value in $this->criteria would lock that value in.  Changing the
     * value on the object will not update the validator.  This feature lets
     * additional attributes be looked up dynamically on validation.
     *
     * @var array List of attributes
     */
    public $additionalAttributes = array();

    public function validateAttribute($object, $attribute)
    {
        $value = $object->{$attribute};
        if (null === $value || '' === $value) {
            if (! $this->allowEmpty) {
                $this->addError(
                    $object,
                    $attribute,
                    Yii::t('yii', '{attribute} must be set')
                );
            }

            return;
        }

        if (!$object instanceof EMongoDocument) {
            throw new CException('Invalid object type: ' . get_class($object));
        }

        if (is_array($this->criteria)) {
            $criteria = new EMongoCriteria(array('conditions' => $this->criteria));
        } else {
            $criteria = new EMongoCriteria($this->criteria);
        }
        if (!$object->getIsNewRecord()) {
            $criteria->addCond(
                $object->primaryKey(),
                '!=',
                $object->getPrimaryKey()
            );
        }
        $criteria->addCond($attribute, '==', $value);
        foreach ($this->additionalAttributes as $additional) {
            $criteria->addCond($additional, '==', $object->$additional);
        }
        $count = $object->model()->count($criteria);

        if (0 !== $count) {
            $this->addError(
                $object,
                $attribute,
                Yii::t('yii', '{attribute} is not unique')
            );
        }
    }
}
