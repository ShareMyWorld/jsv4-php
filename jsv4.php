<?php

define('JSV4_INVALID_TYPE', 0);
define('JSV4_ENUM_MISMATCH', 1);
define('JSV4_ANY_OF_MISSING', 10);
define('JSV4_ONE_OF_MISSING', 11);
define('JSV4_ONE_OF_MULTIPLE', 12);
define('JSV4_NOT_PASSED', 13);
// Numeric errors
define('JSV4_NUMBER_MULTIPLE_OF', 100);
define('JSV4_NUMBER_MINIMUM', 101);
define('JSV4_NUMBER_MINIMUM_EXCLUSIVE', 102);
define('JSV4_NUMBER_MAXIMUM', 103);
define('JSV4_NUMBER_MAXIMUM_EXCLUSIVE', 104);
// String errors
define('JSV4_STRING_LENGTH_SHORT', 200);
define('JSV4_STRING_LENGTH_LONG', 201);
define('JSV4_STRING_PATTERN', 202);
// Object errors
define('JSV4_OBJECT_PROPERTIES_MINIMUM', 300);
define('JSV4_OBJECT_PROPERTIES_MAXIMUM', 301);
define('JSV4_OBJECT_REQUIRED', 302);
define('JSV4_OBJECT_ADDITIONAL_PROPERTIES', 303);
define('JSV4_OBJECT_DEPENDENCY_KEY', 304);
// Array errors
define('JSV4_ARRAY_LENGTH_SHORT', 400);
define('JSV4_ARRAY_LENGTH_LONG', 401);
define('JSV4_ARRAY_UNIQUE', 402);
define('JSV4_ARRAY_ADDITIONAL_ITEMS', 403);

/**
 * Json schema version 4 validator
 * Supports both objects and associative arrays
 *
 */
class Jsv4 {
	static public function validate($data, $schema, $associative = FALSE, $options = []) {
		return new Jsv4($data, $schema, TRUE, $associative, $options);
	}

	static public function isValid($data, $schema, $associative = FALSE) {
		$result = new Jsv4($data, $schema, TRUE, $associative);
		return $result->valid;
	}

	static public function copyAndValidate($data, $schema, $associative = FALSE, $options = []) {
		if (!is_scalar($data)) {
			$data = igbinary_unserialize(igbinary_serialize($data));
		}
		$result = new Jsv4($data, $schema, TRUE, $associative, $options);
		if ($result->valid) {
			$result->value = $result->data;
		}
		return $result;
	}

	static public function pointerJoin($parts) {
		$result = "";
		foreach ($parts as $part) {
			$part = str_replace("~", "~0", $part);
			$part = str_replace("/", "~1", $part);
			$result .= "/".$part;
		}
		return $result;
	}

	static public function recursiveEqual($a, $b) {
		if ($a === $b) {
			return TRUE;
		} elseif (is_array($a)) {
			if (!is_array($b)) {
				return FALSE;
			}
			if (count($a) !== count($b)) {
				return FALSE;
			}
			foreach ($a as $key => $value) {
				if (!isset($b[$key])) {
					return FALSE;
				}
				if (!self::recursiveEqual($value, $b[$key])) {
					return FALSE;
				}
			}
			return TRUE;
		} elseif (is_object($a)) {
			if (!is_object($b)) {
				return FALSE;
			}
			$arrayA = get_object_vars($a);
			$arrayB = get_object_vars($b);
			if (count($arrayA) !== count($arrayB)) {
				return FALSE;
			}
			foreach ($arrayA as $key => $value) {
				if (!isset($arrayB[$key])) {
					return FALSE;
				}
				if (!self::recursiveEqual($value, $arrayB[$key])) {
					return FALSE;
				}
			}
			return TRUE;
		}
		return FALSE;
	}

	/**
     * @var mixed
     */
	private $data;

    /**
     * @var \stdClass
     */
	private $schema;

    /**
     * @var boolean
     */
	private $firstErrorOnly;

    /**
     * @var boolean
     */
    private $associative;

    /**
     * @var array
     */
	private $options;

    /**
     * @var boolean
     */
	public $valid;

    /**
     * @var \Jsv4Error[]
     */
	public $errors;

    /**
     *
     * @param mixed $data
     * @param \stdClass $schema
     * @param boolean $firstErrorOnly
     * @param boolean $associative - Set to TRUE if evaluating associative arrays instead of objects
     * @param array $options - Additional options for the validator:
     *                      - 'expandDefault' : If a property is not set, create it with default values if schema has defined the 'default' property
     *                      - 'removeAdditionalPropertiesByDefault': Remove all additional properties unless the schema defines "additionalProperties": true || { schema }
     */
	private function __construct(&$data, $schema, $firstErrorOnly=FALSE, $associative=FALSE, $options=[]) {
        // By reference if we have set options that will mutate the array
		$this->data =& $data;
		$this->schema = $schema;
		$this->firstErrorOnly = $firstErrorOnly;
        $this->associative = $associative;
		$this->options = $options;
		$this->valid = TRUE;
		$this->errors = array();

		try {

			if (is_array($this->data)) {
				$this->checkArray();
            } elseif (is_object($this->data)) {
				$this->checkObject();
			} elseif (is_string($this->data)) {
				$this->checkString();
			} elseif (is_numeric($this->data)) {
				$this->checkNumber();
			} elseif (is_bool($this->data)) {
				if (isset($this->schema->type)) {
					$this->checkType('boolean');
				}
			} else {
				if (isset($this->schema->type)) {
					$this->checkType('null');
				}
			}

			if (isset($this->schema->enum)) {
				$this->checkEnum();
			}

			$this->checkComposite();
		} catch (Jsv4Error $e) {
		}
	}

	private function fail($code, $dataPath, $schemaPath, $errorMessage, $subErrors=NULL) {
		$this->valid = FALSE;
		$error = new Jsv4Error($code, $dataPath, $schemaPath, $errorMessage, $subErrors);
		$this->errors[] = $error;
		if ($this->firstErrorOnly) {
			throw $error;
		}
	}

	private function subResult(&$data, $schema) {
		return new Jsv4($data, $schema, $this->firstErrorOnly, $this->associative, $this->options);
	}

	private function includeSubResult($subResult, $dataPrefix, $schemaPrefix) {
		if (!$subResult->valid) {
			if (is_array($dataPrefix)) {
				$dataPrefix = self::pointerJoin($dataPrefix);
			}
			if (is_array($schemaPrefix)) {
				$schemaPrefix = self::pointerJoin($schemaPrefix);
			}
			$this->valid = FALSE;
			foreach ($subResult->errors as $error) {
				$this->errors[] = $error->prefix($dataPrefix, $schemaPrefix);
			}
		}
	}

	private function checkType($actualType) {
		$types = $this->schema->type;
		if (is_string($types)) {
			if ($types === $actualType || ($types === 'integer' && is_int($this->data))) {
				return;
			}
		} elseif (in_array($actualType, $types)) {
			return;
		}

		$type = gettype($this->data);
		if ($type === 'double') {
			$type = ((int)$this->data == $this->data) ? 'integer' : 'number';
		} elseif ($type == 'NULL') {
			$type = 'null';
		}
		$this->fail(JSV4_INVALID_TYPE, '', '/type', "Invalid type: $type");
	}

	private function checkEnum() {
		foreach ($this->schema->enum as $option) {
			if (self::recursiveEqual($this->data, $option)) {
				return;
			}
		}
		$this->fail(JSV4_ENUM_MISMATCH, '', '/enum', 'Value must be one of the enum options');
	}

	private function checkObject() {
		if (isset($this->schema->type)) {
			$this->checkType('object');
		}

		if (isset($this->schema->required)) {
			foreach ($this->schema->required as $index => $key) {
				if (($this->associative && !array_key_exists($key, $this->data)) || (!$this->associative && !property_exists($this->data, $key))) {
					if (!empty($this->options['expandDefault'])) {
                        $this->createValueForProperty($key);
						continue;
					}
					$this->fail(JSV4_OBJECT_REQUIRED, '', "/required/{$index}", "Missing required property: {$key}");
				}
			}
		}

		if (isset($this->schema->minProperties) || isset($this->schema->maxProperties)) {
            if ($this->associative) {
                $propCount = count($this->data);
            } else {
                $propCount = count(get_object_vars($this->data));
            }
			if (isset($this->schema->minProperties) && $propCount < $this->schema->minProperties) {
				$this->fail(JSV4_OBJECT_PROPERTIES_MINIMUM, '', '/minProperties', ($this->schema->minProperties == 1) ? 'Object cannot be empty' : "Object must have at least {$this->schema->minProperties} defined properties");
			}
			if (isset($this->schema->maxProperties) && $propCount > $this->schema->maxProperties) {
				$this->fail(JSV4_OBJECT_PROPERTIES_MAXIMUM, '', '/minProperties', ($this->schema->maxProperties == 1) ? 'Object must have at most one defined property' : "Object must have at most {$this->schema->maxProperties} defined properties");
			}
		}

		$checkedProperties = array();
		if (isset($this->schema->properties)) {
			foreach ($this->schema->properties as $key => $subSchema) {
				$checkedProperties[$key] = TRUE;
                if ($this->associative) {
                    if (array_key_exists($key, $this->data)) {
                        $subResult = $this->subResult($this->data[$key], $subSchema);
                        $this->includeSubResult($subResult, [$key], ['properties', $key]);
                    }
                } else {
                    if (property_exists($this->data, $key)) {
                        $subResult = $this->subResult($this->data->$key, $subSchema);
                        $this->includeSubResult($subResult, [$key], ['properties', $key]);
                    }
                }
			}
		}
		if (isset($this->schema->patternProperties)) {
			foreach ($this->schema->patternProperties as $pattern => $subSchema) {
				$finalPattern = '/'.str_replace('/', '\\/', $pattern).'/';
				foreach ($this->data as $key => $subValue) {
					if (preg_match($finalPattern, $key)) {
						$checkedProperties[$key] = TRUE;
                        $subResult = $this->subResult($this->associative ? $this->data[$key] : $this->data->$key, $subSchema);
						$this->includeSubResult($subResult, [$key], ['patternProperties', $pattern]);
					}
				}
			}
		}

        $removeAdditionalProperties = !empty($this->options['removeAdditionalPropertiesByDefault']);
        $additionalProperties = isset($this->schema->additionalProperties) ? $this->schema->additionalProperties : !$removeAdditionalProperties;
        if ($additionalProperties === FALSE) {
            foreach ($this->data as $key => &$subValue) {
				if (isset($checkedProperties[$key])) {
					continue;
				}
                if ($removeAdditionalProperties) {
                    if ($this->associative) {
                        unset($this->data[$key]);
                    } else {
                        unset($this->data->$key);
                    }
				} else {
					$this->fail(JSV4_OBJECT_ADDITIONAL_PROPERTIES, self::pointerJoin([$key]), '/additionalProperties', 'Additional properties not allowed');
				}
			}
        } else if (is_object($additionalProperties)) {
			foreach ($this->data as $key => &$subValue) {
				if (isset($checkedProperties[$key])) {
					continue;
				}
                $subResult = $this->subResult($subValue, $additionalProperties);
                $this->includeSubResult($subResult, [$key], '/additionalProperties');
			}
		}

		if (isset($this->schema->dependencies)) {
			foreach ($this->schema->dependencies as $key => $dep) {
				if (($this->associative && !array_key_exists($key, $this->data)) || (!$this->associative && !property_exists($this->data, $key))) {
					continue;
				}
				if (is_object($dep)) {
					$subResult = $this->subResult($this->data, $dep);
					$this->includeSubResult($subResult, '', ['dependencies', $key]);
				} elseif (is_array($dep)) {
					foreach ($dep as $index => $depKey) {
                        if (($this->associative && !array_key_exists($depKey, $this->data)) || (!$this->associative && !property_exists($this->data, $depKey))) {
							$this->fail(JSV4_OBJECT_DEPENDENCY_KEY, '', self::pointerJoin(['dependencies', $key, $index]), "Property $key depends on $depKey");
						}
					}
				} else {
					if (($this->associative && !array_key_exists($dep, $this->data)) || (!$this->associative && !property_exists($this->data, $dep))) {
						$this->fail(JSV4_OBJECT_DEPENDENCY_KEY, '', self::pointerJoin(['dependencies', $key]), "Property $key depends on $dep");
					}
				}
			}
		}

	}

	private function checkArray() {
        $counter = 0;
        $actuallyAssociative = FALSE;
        foreach ($this->data as $index => $subData) {
            if ($counter++ !== $index) {
                if ($this->associative) {
                    $actuallyAssociative = TRUE;
                    break;
                }
                throw new Exception("Arrays must only be numerically-indexed");
            }
        }

        if ($actuallyAssociative) {
            $this->checkObject();
        } else {
            if (isset($this->schema->type)) {
                $this->checkType('array');
            }
            if (isset($this->schema->minItems)) {
                if (count($this->data) < $this->schema->minItems) {
                    $this->fail(JSV4_ARRAY_LENGTH_SHORT, '', '/minItems', "Array is too short (must have at least {$this->schema->minItems} items)");
                }
            }
            if (isset($this->schema->maxItems)) {
                if (count($this->data) > $this->schema->maxItems) {
                    $this->fail(JSV4_ARRAY_LENGTH_LONG, '', '/maxItems', "Array is too long (must have at most {$this->schema->maxItems} items)");
                }
            }
            if (isset($this->schema->items)) {
                $items = $this->schema->items;
                if (is_array($items)) {
                    foreach ($this->data as $index => &$subData) {
                        if (isset($items[$index])) {
                            $subResult = $this->subResult($subData, $items[$index]);
                            $this->includeSubResult($subResult, "/{$index}", "/items/{$index}");
                        } else if (isset($this->schema->additionalItems)) {
                            $additionalItems = $this->schema->additionalItems;
                            if (!$additionalItems) {
                                $this->fail(JSV4_ARRAY_ADDITIONAL_ITEMS, "/{$index}", '/additionalItems', 'Additional items (index '.count($items).' or more) are not allowed');
                            } else if ($additionalItems !== TRUE) {
                                $subResult = $this->subResult($subData, $additionalItems);
                                $this->includeSubResult($subResult, "/{$index}", '/additionalItems');
                            }
                        }
                    }
                } else {
                    foreach ($this->data as $index => &$subData) {
                        $subResult = $this->subResult($subData, $items);
                        $this->includeSubResult($subResult, "/{$index}", '/items');
                    }
                }
            }
            if (isset($this->schema->uniqueItems)) {
                foreach ($this->data as $indexA => $itemA) {
                    foreach ($this->data as $indexB => $itemB) {
                        if ($indexA > $indexB) {
                            if (self::recursiveEqual($itemA, $itemB)) {
                                $this->fail(JSV4_ARRAY_UNIQUE, "", "/uniqueItems", "Array items must be unique (items $indexB and $indexA)");
                                break 2;
                            }
                        } else {
                            break;
                        }
                    }
                }
            }
        }
	}

	private function checkString() {
		if (isset($this->schema->type)) {
			$this->checkType('string');
		}
		if (isset($this->schema->minLength)) {
			if (mb_strlen($this->data) < $this->schema->minLength) {
				$this->fail(JSV4_STRING_LENGTH_SHORT, '', '/minLength', "String must be at least {$this->schema->minLength} characters long");
			}
		}
		if (isset($this->schema->maxLength)) {
			if (mb_strlen($this->data) > $this->schema->maxLength) {
				$this->fail(JSV4_STRING_LENGTH_LONG, '', '/maxLength', "String must be at most {$this->schema->maxLength} characters long");
			}
		}
		if (isset($this->schema->pattern)) {
			$pattern = $this->schema->pattern;
			$patternFlags = isset($this->schema->patternFlags) ? $this->schema->patternFlags : '';
			$result = preg_match("/".str_replace("/", "\\/", $pattern)."/".$patternFlags, $this->data);
			if ($result === 0) {
				$this->fail(JSV4_STRING_PATTERN, '', '/pattern', "String does not match pattern: $pattern");
			}
		}
	}

	private function checkNumber() {
		if (isset($this->schema->type)) {
			$this->checkType('number');
		}
		if (isset($this->schema->multipleOf)) {
			if (fmod($this->data/$this->schema->multipleOf, 1) != 0) {
				$this->fail(JSV4_NUMBER_MULTIPLE_OF, '', '/multipleOf', "Number must be a multiple of {$this->schema->multipleOf}");
			}
		}
		if (isset($this->schema->minimum)) {
			$minimum = $this->schema->minimum;
			if (empty($this->schema->exclusiveMinimum)) {
				if ($this->data < $minimum) {
					$this->fail(JSV4_NUMBER_MINIMUM, '', '/minimum', "Number must be >= $minimum");
				}
			} elseif ($this->data <= $minimum) {
				$this->fail(JSV4_NUMBER_MINIMUM_EXCLUSIVE, '', '', "Number must be > $minimum");
			}
		}
		if (isset($this->schema->maximum)) {
			$maximum = $this->schema->maximum;
			if (empty($this->schema->exclusiveMaximum)) {
				if ($this->data > $maximum) {
					$this->fail(JSV4_NUMBER_MAXIMUM, '', '/maximum', "Number must be <= $maximum");
				}
			} elseif ($this->data >= $maximum) {
				$this->fail(JSV4_NUMBER_MAXIMUM_EXCLUSIVE, '', '', "Number must be < $maximum");
			}
		}
	}

	private function checkComposite() {
		if (isset($this->schema->allOf)) {
			foreach ($this->schema->allOf as $index => $subSchema) {
				$subResult = $this->subResult($this->data, $subSchema);
				$this->includeSubResult($subResult, '', "/allOf/$index");
			}
		}
		if (isset($this->schema->anyOf)) {
			$failResults = array();
			$foundValid = FALSE;
			foreach ($this->schema->anyOf as $index => $subSchema) {
				$subResult = $this->subResult($this->data, $subSchema);
				if ($subResult->valid) {
					$foundValid = TRUE;
					break;
				}
				$failResults[] = $subResult;
			}
			if (!$foundValid) {
				$this->fail(JSV4_ANY_OF_MISSING, "", "/anyOf", "Value must satisfy at least one of the options", $failResults);
			}
		}
		if (isset($this->schema->oneOf)) {
			$failResults = array();
			$successIndex = NULL;
			foreach ($this->schema->oneOf as $index => $subSchema) {
				$subResult = $this->subResult($this->data, $subSchema);
				if ($subResult->valid) {
					if ($successIndex === NULL) {
						$successIndex = $index;
					} else {
						$this->fail(JSV4_ONE_OF_MULTIPLE, '', '/oneOf', "Value satisfies more than one of the options ($successIndex and $index)");
					}
					continue;
				}
				$failResults[] = $subResult;
			}
			if ($successIndex === NULL) {
				$this->fail(JSV4_ONE_OF_MISSING, '', '/oneOf', 'Value must satisfy one of the options', $failResults);
			}
		}
		if (isset($this->schema->not)) {
			$subResult = $this->subResult($this->data, $this->schema->not);
			if ($subResult->valid) {
				$this->fail(JSV4_NOT_PASSED, '', '/not', 'Value satisfies prohibited schema');
			}
		}
	}

	private function createValueForProperty($key) {
		$schema = NULL;
		if (isset($this->schema->properties->$key)) {
			$schema = $this->schema->properties->$key;
		} else if (isset($this->schema->patternProperties)) {
			foreach ($this->schema->patternProperties as $pattern => $subSchema) {
				if (preg_match("/".str_replace("/", "\\/", $pattern)."/", $key)) {
					$schema = $subSchema;
					break;
				}
			}
		}
		if (!$schema && isset($this->schema->additionalProperties) && is_object($this->schema->additionalProperties)) {
			$schema = $this->schema->additionalProperties;
		}
		if ($schema) {
			if (isset($schema->default)) {
				if (is_scalar($schema->default)) {
                    if ($this->associative) {
                        $this->data[$key] = $schema->default;
                    } else {
                        $this->data->$key = $schema->default;
                    }
				} else {
                    if ($this->associative) {
                        $this->data[$key] = igbinary_unserialize(igbinary_serialize($schema->default));
                    } else {
                        $this->data->$key = igbinary_unserialize(igbinary_serialize($schema->default));
                    }
				}

				return TRUE;
			}
		}
		return FALSE;
	}
}

class Jsv4Error extends Exception {
    /**
     * @var int
     */
	public $code;

    /**
     * @var string
     */
	public $dataPath;

    /**
     * @var string
     */
	public $schemaPath;

    /**
     * @var string
     */
	public $message;

	public function __construct($code, $dataPath, $schemaPath, $errorMessage, $subResults=NULL) {
		parent::__construct($errorMessage);
		$this->code = $code;
		$this->dataPath = $dataPath;
		$this->schemaPath = $schemaPath;
		$this->message = $errorMessage;
		if ($subResults) {
			$this->subResults = $subResults;
		}
	}

	public function prefix($dataPrefix, $schemaPrefix) {
		return new Jsv4Error($this->code, $dataPrefix.$this->dataPath, $schemaPrefix.$this->schemaPath, $this->message);
	}
}

