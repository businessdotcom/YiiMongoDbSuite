<?php

/**
 * EMongoCriteria.php
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
 *
 */

namespace YiiMongoDbSuite;

use \CJSON;
use \MongoId;
use \MongoDate;
use \MongoRegex;
use \MongoBinData;

/**
 * EMongoCriteria class
 *
 * This class is a helper for building MongoDB query arrays, it support three syntaxes for adding conditions:
 *
 * 1. 'equals' syntax:
 * $criteriaObject->fieldName = $value; // this will produce fieldName == value query
 * 2. fieldName call syntax
 * $criteriaObject->fieldName($operator, $value); // this will produce fieldName <operator> value
 * 3. addCond method
 * $criteriaObject->addCond($fieldName, $operator, $vale); // this will produce fieldName <operator> value
 *
 * For operators list {@see EMongoCriteria::$operators}
 *
 * @author       Dariusz Górecki <darek.krk@gmail.com>
 * @since        v1.0
 */
class EMongoCriteria extends \CComponent
{
    /**
     * @since v1.0
     * @var array $operators supported operators lists
     */
    public static $operators = array(
        'greater'       => '$gt',
        '>'             => '$gt',
        'greatereq'     => '$gte',
        '>='            => '$gte',
        'less'          => '$lt',
        '<'             => '$lt',
        'lesseq'        => '$lte',
        '<='            => '$lte',
        'noteq'         => '$ne',
        '!='            => '$ne',
        '<>'            => '$ne',
        'in'            => '$in',
        'notin'         => '$nin',
        'all'           => '$all',
        'size'          => '$size',
        'type'          => '$type',
        'exists'        => '$exists',
        'notexists'     => '$exists',
        'elemmatch'     => '$elemMatch',
        'mod'           => '$mod',
        '%'             => '$mod',
        'equals'        => '$$eq',
        'eq'            => '$$eq',
        '=='            => '$$eq',
        'where'         => '$where',
        'or'            => '$or',
        'nor'           => '$nor',
        'not'           => '$not',
        'near'          => '$near',
        'nearsphere'    => '$nearSphere',
        'geowithin'     => '$geoWithin',
        'geointersects' => '$geoIntersects',
        'type'          => '$type',
        'search'        => '$search',
        'maxDistance'   => '$maxDistance',
    );

    const SORT_ASC        = 1;
    const SORT_DESC       = -1;

    private $_select        = array();
    private $_limit         = null;
    private $_offset        = null;
    private $_conditions    = array();
    private $_sort          = array();
    private $_workingFields = array();
    private $_useCursor     = null;

    /**
     * Constructor
     * Example criteria:
     *
     * <PRE>
     * 'criteria' = array(
     *     'conditions'=>array(
     *        'fieldName1'=>array('greater' => 0),
     *        'fieldName2'=>array('>=' => 10),
     *        'fieldName3'=>array('<' => 10),
     *        'fieldName4'=>array('lessEq' => 10),
     *        'fieldName5'=>array('notEq' => 10),
     *        'fieldName6'=>array('in' => array(10, 9)),
     *        'fieldName7'=>array('notIn' => array(10, 9)),
     *        'fieldName8'=>array('all' => array(10, 9)),
     *        'fieldName9'=>array('size' => 10),
     *        'fieldName10'=>array('exists'),
     *        'fieldName11'=>array('notExists'),
     *        'fieldName12'=>array('mod' => array(10, 9)),
     *         'fieldName13'=>array('==' => 1)
     *     ),
     *     'select'=>array('fieldName', 'fieldName2'),
     *     'limit'=>10,
     *  'offset'=>20,
     *  'sort'=>array('fieldName1'=>EMongoCriteria::SORT_ASC, 'fieldName2'=>EMongoCriteria::SORT_DESC),
     * );
     * </PRE>
     * @param mixed $criteria
     * @since v1.0
     */
    public function __construct($criteria = null)
    {
        if (is_array($criteria)) {
            if (isset($criteria['conditions'])) {
                foreach ($criteria['conditions'] as $fieldName => $conditions) {
                    $fieldNameArray = explode('.', $fieldName);
                    if (count($fieldNameArray) === 1) {
                        $fieldName = array_shift($fieldNameArray);
                    } else {
                        $fieldName = array_pop($fieldNameArray);
                    }

                    foreach ($conditions as $operator => $value) {
                        $this->setWorkingFields($fieldNameArray);
                        $operator = strtolower($operator);

                        $this->$fieldName($operator, $value);
                    }
                }
            }

            if (isset($criteria['select'])) {
                $this->select($criteria['select']);
            }
            if (isset($criteria['limit'])) {
                $this->limit($criteria['limit']);
            }
            if (isset($criteria['offset'])) {
                $this->offset($criteria['offset']);
            }
            if (isset($criteria['sort'])) {
                $this->setSort($criteria['sort']);
            }
            if (isset($criteria['useCursor'])) {
                $this->setUseCursor($criteria['useCursor']);
            }
        } elseif ($criteria instanceof EMongoCriteria) {
            $this->mergeWith($criteria);
        }
    }

    /**
     * Merge with other criteria
     * - Field list operators will be merged
     * - Limit and offet will be overriden
     * - Select fields list will be merged
     * - Sort fields list will be merged
     * @param array|EMongoCriteria $criteria
     * @since v1.0
     */
    public function mergeWith($criteria)
    {
        if (is_array($criteria)) {
            $criteria = new EMongoCriteria($criteria);
        } elseif (empty($criteria)) {
            return $this;
        }

        $opTable = array_values(self::$operators);

        foreach ($criteria->_conditions as $fieldName => $conds) {
            if (is_array($conds)
                && count(array_diff(array_keys($conds), $opTable)) == 0
            ) {
                if (isset($this->_conditions[$fieldName])
                    && is_array($this->_conditions[$fieldName])
                ) {
                    foreach ($this->_conditions[$fieldName] as $operator => $value) {
                        if (!in_array($operator, $opTable)) {
                            unset($this->_conditions[$fieldName][$operator]);
                        }
                    }
                } else {
                    $this->_conditions[$fieldName] = array();
                }

                foreach ($conds as $operator => $value) {
                    $this->_conditions[$fieldName][$operator] = $value;
                }
            } else {
                $this->_conditions[$fieldName] = $conds;
            }
        }

        if (!empty($criteria->_limit)) {
            $this->_limit    = $criteria->_limit;
        }
        if (!empty($criteria->_offset)) {
            $this->_offset    = $criteria->_offset;
        }
        if (!empty($criteria->_sort)) {
            $this->_sort    = array_merge($this->_sort, $criteria->_sort);
        }
        if (!empty($criteria->_select)) {
            $this->_select    = array_merge($this->_select, $criteria->_select);
        }

        return $this;
    }

    /**
     * If we have operator add it otherwise call parent implementation
     * @see CComponent::__call()
     * @since v1.0
     */
    public function __call($fieldName, $parameters)
    {
        if (isset($parameters[0])) {
            $operatorName = strtolower($parameters[0]);
        }
        if (isset($parameters[1]) || ($parameters[1] === null)) {
            $value = $parameters[1];
        }

        if (is_numeric($operatorName)) {
            $operatorName = strtolower(trim($value));
            $value = (strtolower(trim($value)) === 'exists') ? true : false;
        }

        if (in_array($operatorName, array_keys(self::$operators))) {
            array_push($this->_workingFields, $fieldName);
            $fieldName = implode('.', $this->_workingFields);
            $this->_workingFields = array();
            switch($operatorName)
            {
                case 'exists':
                        $this->addCond($fieldName, $operatorName, true);
                    break;
                case 'notexists':
                        $this->addCond($fieldName, $operatorName, false);
                    break;
                default:
                    $this->addCond($fieldName, $operatorName, $value);
            }
            return $this;
        } else {
            return parent::__call($fieldName, $parameters);
        }
    }

    /**
     * @since v1.0.2
     */
    public function __get($name)
    {
        array_push($this->_workingFields, $name);
        return $this;
    }

    /**
     * @since v1.0.2
     */
    public function __set($name, $value)
    {
        array_push($this->_workingFields, $name);
        $fieldList = implode('.', $this->_workingFields);
        $this->_workingFields = array();
        $this->addCond($fieldList, '==', $value);
    }

    /**
     * Return query array
     * @return array query array
     * @since v1.0
     */
    public function getConditions()
    {
        return $this->_conditions;
    }

    /**
     * @since v1.0
     */
    public function setConditions(array $conditions)
    {
        $this->_conditions = $conditions;
    }

    /**
     * @since v1.0
     * @return integer|null Limit if set
     */
    public function getLimit()
    {
        return $this->_limit;
    }

    /**
     * Limit the number of results for a findAll query
     *
     * @param integer|null $limit Limit to set. Null for all
     *
     * @see limit()
     * @since v1.0
     */
    public function setLimit($limit)
    {
        $this->limit($limit);
    }

    /**
     * Get configured findAll query result offset
     *
     * @return integer|null
     * @since v1.0
     */
    public function getOffset()
    {
        return $this->_offset;
    }

    /**
     * Set result offset for a findAll query
     *
     * @param integer|null $limit Offset to set. Null for none
     *
     * @see offset()
     * @since v1.0
     */
    public function setOffset($offset)
    {
        $this->offset($offset);
    }

    /**
     * Get sorted fields for a findAll query
     *
     * @return array|null Field => direction mapping, if set
     * @since v1.0
     */
    public function getSort()
    {
        return $this->_sort;
    }

    /**
     * Set query result sorting
     *
     * @param array $sort Field => direction (SORT_ASC, SORT_DESC) mapping
     *
     * @see sort()
     * @since v1.0
     */
    public function setSort(array $sort)
    {
        $this->_sort = $sort;
    }

    /**
     * Get whether the specific cursor has been configured to use EMongoCursor
     *
     * @since v1.3.7
     * @return boolean|null Boolean if explicitly set, or null if not configured
     */
    public function getUseCursor()
    {
        return $this->_useCursor;
    }

    /**
     * Set flag of whether to return query results using EMongoCursor
     *
     * @param boolean|null $useCursor Whether or not to use EMongoCursor. If null,
     *                                EMongoDocument will use fallback logic
     *
     * @see EMongoDocument::getUseCursor()
     * @since v1.3.7
     */
    public function setUseCursor($useCursor)
    {
        $this->_useCursor = $useCursor;
    }

    /**
     * Return selected fields
     *
     * @since v1.3.1
     * @return array|null Field names to be selected (field => boolean)
     */
    public function getSelect()
    {
        return $this->_select;
    }

    /**
     * Choose fields to be returned for a find/findAll query
     *
     * @param array $select Array of fields (either a indexed-array or
     *                      'field' => boolean mapping)
     *
     * @since v1.3.1
     */
    public function setSelect(array $select)
    {
        $this->_select = array();
        // Convert the select array to field=>true/false format
        foreach ($select as $key => $value) {
            if (is_int($key)) {
                $this->_select[$value] = true;
            } else {
                $this->_select[$key] = $value;
            }
        }
    }

    /**
     * @since v1.3.1
     */
    public function getWorkingFields()
    {
        return $this->_workingFields;
    }

    /**
     * @since v1.3.1
     */
    public function setWorkingFields(array $select)
    {
        $this->_workingFields = $select;
    }

    /**
     * List of fields to get from DB
     * Multiple calls to this method will merge all given fields
     *
     * @param array $fieldList list of fields to select
     *
     * @return EMongoCriteria Current object
     * @since v1.0
     */
    public function select(array $fieldList = null)
    {
        if (null !== $fieldList) {
            $this->setSelect(array_merge($this->_select, $fieldList));
        }

        return $this;
    }

    /**
     * Set linit
     * Multiple calls will overrride previous value of limit
     *
     * @param integer $limit limit
     *
     * @return EMongoCriteria Current object
     * @since v1.0
     */
    public function limit($limit)
    {
        $this->_limit = intval($limit);

        return $this;
    }

    /**
     * Set offset
     * Multiple calls will override previous value
     *
     * @param integer $offset offset
     *
     * @return EMongoCriteria Current object
     * @since v1.0
     */
    public function offset($offset)
    {
        $this->_offset = intval($offset);

        return $this;
    }

    /**
     * Add sorting, avaliabe orders are: EMongoCriteria::SORT_ASC and EMongoCriteria::SORT_DESC
     * Each call will be groupped with previous calls
     *
     * @param string  $fieldName Field name to sort on
     * @param integer $order     Direction (see SORT_ASC and SORT_DESC)
     *
     * @return EMongoCriteria Current object
     * @since v1.0
     */
    public function sort($fieldName, $order)
    {
        $this->_sort[$fieldName] = intval($order);

        return $this;
    }

    /**
     * Add condition
     * If specified field already has a condition, values will be merged
     * duplicates will be overriden by new values!
     *
     * @param string $fieldName Field name for condition.
     * @param string $op        operator {@see $operators}
     * @param mixed  $value     Value to compare
     *
     * @return EMongoCriteria Current object
     * @see __call() Sets working fields before calling this method
     * @since v1.0
     */
    public function addCond($fieldName, $op, $value)
    {
        $op = self::$operators[$op];

        if ($op == self::$operators['or']) {
            if (!isset($this->_conditions[$op])) {
                $this->_conditions[$op] = array();
            }
            $this->_conditions[$op][] = array($fieldName=>$value);
        } else {
            if (!isset($this->_conditions[$fieldName])
                && $op != self::$operators['equals']
            ) {
                $this->_conditions[$fieldName] = array();
            }

            if ($op != self::$operators['equals']) {
                if (!is_array($this->_conditions[$fieldName])
                    || count(array_diff(array_keys($this->_conditions[$fieldName]), array_values(self::$operators))) > 0
                ) {
                    $this->_conditions[$fieldName] = array();
                }
                $this->_conditions[$fieldName][$op] = $value;
            } else {
                $this->_conditions[$fieldName] = $value;
            }
        }

        return $this;
    }

    /**
     * Generate a MongoDB query string based on the given criteria.
     *
     * @param EMongoCriteria $criteria   Criteria for operation, or parameter
     * @param boolean        $multiple   Flag indicating if find or findOne
     * @param string         $collection Collection operation is performed on
     *
     * @return string The generated MongoDb query string
     * @since v1.4.0
     */
    public static function findToString(
        EMongoCriteria $criteria,
        $multiple = true,
        $collection = null
    ) {
        if ($collection) {
            $query = 'db.' . $collection . '.';
        } else {
            $query = '';
        }
        $query .= 'find' . ($multiple ? '' : 'One') . '(';
        $query .= self::queryValueToString($criteria->getConditions());
        $fields = $criteria->getSelect();
        if (! empty($fields)) {
            $query .= ', ' . self::queryValueToString($fields);
        }
        $query .= ')';
        // Add fields valid only for cursors
        if ($multiple) {
            if (array() !== $criteria->getSort()) {
                $query .= '.sort(' . self::queryValueToString($criteria->getSort())
                . ')';
            }
            if (null !== $criteria->getLimit()) {
                $query .= '.limit(' . $criteria->getLimit() . ')';
            }
            if (null !== $criteria->getOffset()) {
                $query .= '.skip(' . $criteria->getOffset() . ')';
            }
        }

        return $query;
    }

    /**
     * Generate a MongoDB query string based on the given criteria.
     *
     * @param string  $command    Operation that is being performed
     * @param string  $collection Collection operation is performed on
     * @param mixed   $params,... Additional parameters passed to command
     *
     * @return string The generated MongoDb query string
     * @since v1.4.0
     */
    public static function commandToString($command, $collection = null)
    {
        if ($collection) {
            $query = 'db.' . $collection . '.';
        } else {
            $query = 'db.';
        }
        $query .= $command . '(';

        $count = func_num_args();
        if ($count > 2) {
            for ($i = 2; $i < $count; $i++) {
                if (2 !== $i) {
                    $query .= ', ';
                }
                $query .= self::queryValueToString(func_get_arg($i));
            }
        }
        $query .= ')';

        return $query;
    }

    /**
     * Convert a condition value to a string
     *
     * @param mixed $value Value to be converted to a string
     *
     * @return string Query portion as a string
     * @since v1.4.0
     */
    public static function queryValueToString($value)
    {
        if (! is_array($value)) {
            // Force objects to be explicitly cast as a string (e.g. MongoId)
            if ($value instanceof MongoId) {
                $string = 'ObjectId("' . (string) $value . '")';
            } elseif ($value instanceof MongoRegex) {
                $string = (string) $value;
            } elseif ($value instanceof MongoDate) {
                // Get ISO format with microseconds inserted
                $format = 'Y-m-d\TH:i:s.' . $value->usec / 1000 . 'O';
                $string = 'ISODate("' . date($format, $value->sec) . '")';
            } elseif ($value instanceof MongoBinData) {
                switch ($value->type) {
                    case MongoBinData::UUID:
                    case MongoBinData::UUID_RFC4122:
                        $unpacked = unpack('h*', $value->bin);
                        $string = 'UUID("' . $unpacked[1] . '")';
                        break;
                    default:
                        $string = 'BinData(' . $value->type . ', "'
                            . base64_decode($value->bin) . '")';
                }
            } else {
                $string = CJSON::encode($value);
            }
        } else {
            $string = '';
            $isArray = isset($value[0]);
            if ($isArray) {
                $string .= '[';
            } else {
                $string .= '{';
            }
            // Ensure inner values are serialized
            foreach ($value as $key => $innerValue) {
                // Check if an associative array or not
                if (!is_int($key)) {
                    $string .= CJSON::encode($key) . ' : ';
                }
                $string .= self::queryValueToString($innerValue) . ', ';
            }
            $string = rtrim($string, ', ');
            if ($isArray) {
                $string .= ']';
            } else {
                $string .= '}';
            }
        }

        return $string;
    }

    /**
     * @return string Query as a string
     * @since v1.4.0
     */
    public function __toString()
    {
        // Default to a find operation
        return self::findToString($this);
    }
}
