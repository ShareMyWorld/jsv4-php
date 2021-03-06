<?php

class SchemaStore {
	private static function pointerGet(&$value, $path="", $strict=FALSE) {
		if ($path == "") {
			return $value;
		} else if ($path[0] != "/") {
			throw new Exception("Invalid path: $path");
		}
		$parts = explode("/", $path);
		array_shift($parts);
		foreach ($parts as $part) {
			$part = str_replace("~1", "/", $part);
			$part = str_replace("~0", "~", $part);
			if (is_array($value) && is_numeric($part)) {
				$value =& $value[$part];
			} else if (is_object($value)) {
				if (isset($value->$part)) {
					$value =& $value->$part;
				} else if ($strict) {
					throw new Exception("Path does not exist: $path");
				} else {
					return NULL;
				}
			} else if ($strict) {
				throw new Exception("Path does not exist: $path");
			} else {
				return NULL;
			}
		}
		return $value;
	}

	private static function resolveUrl($base, $relative) {
		if (parse_url($relative, PHP_URL_SCHEME) != '') {
			// It's already absolute
			return $relative;
		}
		$baseParts = parse_url($base);
		if ($relative[0] == "?") {
			$baseParts['query'] = substr($relative, 1);
			unset($baseParts['fragment']);
		} else if ($relative[0] == "#") {
			$baseParts['fragment'] = substr($relative, 1);
		} else if ($relative[0] == "/") {
			if ($relative[1] == "/") {
				return $baseParts['scheme'].$relative;
			}
			$baseParts['path'] = $relative;
			unset($baseParts['query']);
			unset($baseParts['fragment']);
		} else {
			$basePathParts = explode("/", $baseParts['path']);
			$relativePathParts = explode("/", $relative);
			array_pop($basePathParts);
			while (count($relativePathParts)) {
				if ($relativePathParts[0] == "..") {
					array_shift($relativePathParts);
					if (count($basePathParts)) {
						array_pop($basePathParts);
					}
				} else if ($relativePathParts[0] == ".") {
					array_shift($relativePathParts);
				} else {
					array_push($basePathParts, array_shift($relativePathParts));
				}
			}
			$baseParts['path'] = implode("/", $basePathParts);
			if ($baseParts['path'][0] != '/') {
				$baseParts['path'] = "/".$baseParts['path'];
			}
		}

		$result = "";
		if (isset($baseParts['scheme'])) {
			$result .= $baseParts['scheme']."://";
			if (isset($baseParts['user'])) {
				$result .= ":".$baseParts['user'];
				if (isset($baseParts['pass'])) {
					$result .= ":".$baseParts['pass'];
				}
				$result .= "@";
			}
			$result .= $baseParts['host'];
			if (isset($baseParts['port'])) {
				$result .= ":".$baseParts['port'];
			}
		}
		$result .= $baseParts["path"];
		if (isset($baseParts['query'])) {
			$result .= "?".$baseParts['query'];
		}
		if (isset($baseParts['fragment'])) {
			$result .= "#".$baseParts['fragment'];
		}
		return $result;
	}

	private $schemas = array();
	private $refs = array();

	public function missing() {
		return array_keys($this->refs);
	}

    public function getLoadedSchemas() {
        return $this->schemas;
    }

    public function getMissingFullUrls() {
        $fullUrls = [];
        foreach ($this->refs as $fullUrlToRefs) {
            $fullUrls = array_merge($fullUrls, array_keys($fullUrlToRefs));
        }
        return array_unique($fullUrls);
    }

	public function add($url, $schema, $trusted = FALSE, $normalized = FALSE) {
		$urlParts = explode("#", $url);
		$baseUrl = array_shift($urlParts);
		$fragment = urldecode(implode("#", $urlParts));

		$trustBase = NULL;
		if (!$trusted) {
			$trustBase = explode("?", $baseUrl);
			$trustBase = $trustBase[0];
		}

		$this->schemas[$url] =& $schema;
		if (!$normalized) {
			$this->normalizeSchema($url, $schema, $trusted ? TRUE : $trustBase);
		}
		if ($fragment === '') {
			$this->schemas[$baseUrl] = $schema;
		}
		if (isset($this->refs[$baseUrl])) {
			foreach ($this->refs[$baseUrl] as $fullUrl => $refSchemas) {
                $subSchema = $this->get($fullUrl);
                if ($subSchema) {
                    foreach ($refSchemas as &$refSchema) {
                        $refSchema = $subSchema;
                    }
                    unset($this->refs[$baseUrl][$fullUrl]);
                }
			}
			if (empty($this->refs[$baseUrl])) {
				unset($this->refs[$baseUrl]);
			}
		}
	}

	/**
	 * Adds a normalized schema.
	 * CAUTION: No attempt is made to actually verify that the schema is normalized.
	 * Make sure to only use this method with schemas previously retrieved with getNormalizedSchema()
	 */
	public function addNormalizedSchema($url, $schema) {
		$this->add($url, $schema, TRUE, TRUE);
	}

	private function normalizeSchema($url, &$schema, $trustPrefix = '') {
		if (is_object($schema)) {
			if (isset($schema->{'$ref'})) {
				$refUrl = $schema->{'$ref'} = self::resolveUrl($url, $schema->{'$ref'});
				if ($refSchema = $this->get($refUrl)) {
					$schema = $refSchema;
					return;
				} else {
					$urlParts = explode("#", $refUrl);
					$baseUrl = $urlParts[0];
					$this->refs[$baseUrl][$refUrl][] =& $schema;
				}
			} elseif (isset($schema->id) && is_string($schema->id)) {
				$schema->id = $url = self::resolveUrl($url, $schema->id);
				$regex = '/^'.preg_quote($trustPrefix, '/').'(?:[#\/?].*)?$/';
				if (($trustPrefix === TRUE || preg_match($regex, $schema->id)) && !isset($this->schemas[$schema->id])) {
					$this->add($schema->id, $schema);
				}
			}
			foreach ($schema as $key => &$value) {
				if ($key != 'enum' && !is_scalar($value)) {
                    self::normalizeSchema($url, $value, $trustPrefix);
				}
			}
		} elseif (is_array($schema)) {
			foreach ($schema as &$value) {
				self::normalizeSchema($url, $value, $trustPrefix);
			}
		}
	}

	public function get($url, $strict = FALSE) {
		if (isset($this->schemas[$url])) {
			return $this->schemas[$url];
		}
		$urlParts = explode('#', $url);
		$baseUrl = array_shift($urlParts);
		$schema = NULL;

		if (isset($this->schemas[$baseUrl])) {
            $fragment = urldecode(implode('#', $urlParts));
			$baseSchema = $this->schemas[$baseUrl];
            if ($baseSchema) {
                if ($fragment === '' || $fragment[0] === '/') {
                    $schema = self::pointerGet($baseSchema, $fragment, $strict);
                    $this->add($url, $schema);
                } else {
                    $fragmentParts = explode('/', $fragment);
                    if (!empty($fragmentParts)) {
                        $namedFragmentUrl = $baseUrl . '#' . array_shift($fragmentParts);
                        if (isset($this->schemas[$namedFragmentUrl])) {
                            $fragmentSchema = $this->schemas[$namedFragmentUrl];
                            $schema = self::pointerGet($fragmentSchema, '/' . implode('/', $fragmentParts), $strict);
                        }
                    }
                }
            }
		}

		if (empty($schema) && $strict) {
			throw new Exception("Schema not found: $url");
		}

		return $schema;
	}

    public function hasBaseSchema($baseUrl) {
        return isset($this->schemas[$baseUrl]);
    }

	/**
	 * Like get() but will throw an exception if not all its descendant refs have been resolved.
	 *
	 * @param string $url
	 */
	public function getNormalizedSchema($url) {
		$schema = $this->get($url, TRUE);
		if (isset($schema->id)) {
			$baseUrl = $schema->id;
		} else {
			$urlParts = explode('#', $url);
			$baseUrl = array_shift($urlParts);
		}
		if (!empty($this->refs[$baseUrl])) {
			throw new Exception('Schema is not normalized. Missing: ' . json_encode($this->refs[$baseUrl]));
		}
		return $schema;
	}

}


