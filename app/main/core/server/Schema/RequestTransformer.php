<?php



namespace JingCafe\Core\Schema;


/**
 * RequestTransformer
 *
 * Perform a serious of transformations on a set of data fields, as specified by a RequestSchemaInterface.
 */
class RequestTransformer
{	
	/**
	 * @var array 	The specific columns of constants for verifying usage
	 */
	const SPECIFIC_COLUMNS = ['validators'];


	/**
	 * @var RequestSchemaInterface
	 */
	protected static $schema;

	/**
	 * @var HTMLPurifier
	 */
	protected static $purifier;

	/**
	 * Determine wether allow accessibility for html elements
	 * @var bool
	 */
	protected static $allowHtmlElements = false;

	/**
	 * XSS SQL elements
	 * @var array
	 */
	protected static $xssSqlPattern = [
		'\'' => '&apos;',
		'"' => '&quot;',
		'&' => '&amp;',
		'<' => '&lt;',
		'>' => '&gt;',
		//possible SQL injection remove from string with there is no '
		'SELECT * FROM' => '',
		'SELECT(' => '',
		'SLEEP(' => '',
		'AND (' => '',
		' AND' => '',
		'(CASE' => ''
	];

	/**
	 * XSS set of JavaScript characters
	 * @var array
	 */
	protected static $xssHtmlPattern = [
		// Fix &entity\n
		'!(&#0+[0-9]+)!' => '$1;',
		'/(&#*\w+)[\x00-\x20]+;/u' => '$1;>',
		'/(&#x*[0-9A-F]+);*/iu' => '$1;',
		//any attribute starting with "on" or xml name space
		'#(<[^>]+?[\x00-\x20"\'])(?:on|xmlns)[^>]*+>#iu' => '$1>',
		//javascript: and VB script: protocols
		'#([a-z]*)[\x00-\x20]*=[\x00-\x20]*([`\'"]*)[\x00-\x20]*j[\x00-\x20]*a[\x00-\x20]*v[\x00-\x20]*a[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu' => '$1=$2nojavascript...',
		'#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*v[\x00-\x20]*b[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu' => '$1=$2novbscript...',
		// Only works in IE: <span style="width: expression(alert('Ping!'));"></span>
		'#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*-moz-binding[\x00-\x20]*:#u' => '$1=$2nomozbinding...',
		'#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?behaviour[\x00-\x20]*\([^>]*+>#i' => '$1>',
		// Only works in IE: <span style="width: expression(alert('Ping!'));"></span>
		'#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?expression[\x00-\x20]*\([^>]*+>#i' => '$1>',
		'#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:*[^>]*+>#iu' => '$1>',
		// namespace elements
		'#</*\w+:\w[^>]*+>#i' => '',
		//unwanted tags
		'#</*(?:applet|b(?:ase|gsound|link)|embed|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i' => '',
		// Remove any attribute starting with "on" or xmlns
		'#(<[^>]+?[\x00-\x20"\'])(?:on|xmlns)[^>]*+[>\b]?#iu' => '$1>'
	];


	/**
	 * Create a new data transformer.
	 *
	 * @param RequestSchemaInterface 	$schema 	RequestSchemaInterface object, containing the transformation rules.
	 */
	public function __construct(RequestSchemaInterface $schema)
	{
		$config = \HTMLPurifier_Config::createDefault();
		// Turn off cache
		$config->set('Cache.DefinitionImpl', null);
		$this->purifier = new \HTMLPurifier($config);

		$this->setSchema($schema);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function transform(RequestSchemaInterface $schema, array $data, $onUnexpectedVar = 'skip')
	{	
		if (!isset(self::$schema)) {
			self::setSchema($schema);
		}

		$schemaFields = self::$schema->all();
		
		// 1. Perform sequence of transformations on each field.
		$transformedData = [];
		
		foreach ($data as $fieldName => $fieldValue) {
			// handle values not listed in the schema
			if (isset($schemaFields[$fieldName]) || array_key_exists($fieldName, $schemaFields)) {			
				$transformedData[$fieldName] = static::transformField($schemaFields[$fieldName], $fieldValue);
			} else {
				switch ($onUnexpectedVar) {
					case 'allow':
						$transformedData[$name] = $value;
						break;
					case 'error':
						throw new \BadRequestException("The field '$name' is not a valid input field");
						break;
					case 'skip':
						continue;
						break;
				}
			}
			
			// 2. Get default values for any fields missing from data. Especially useful for checkboxs, etc which are not submitted when they are unchecked
			// foreach ($schemaFields as $fieldName => $field) {
			// 	if (!isset($transformedData[$fieldName]) && isset($field['default'])) {
			// 		$transformedData[$fieldName] = $field['default'];
			// 	}
			// }
		}

		return $transformedData;
	}

	/**
	 * {@inheritDoc}
	 * @param arary[mixed] 			$schemaParams
	 * @param array[mixed]|string 	$paramsValue
	 * @return mixed 	
	 */
	public static function transformField($schemaParams, $paramValue)
	{
		$schemaParams = static::unsetSpecificColumns($schemaParams);

		if (!isset($schemaParams['transformations']) || empty($schemaParams['transformations'])) {			
			// Determine the schema params have columns
			if (empty($schemaParams)) {
				return $paramValue;
			}
			
			$transformCache = [];
			foreach ($schemaParams as $schemaKey => $shcemaValue) {				
				$transformCache = array_merge($transformCache, [$schemaKey => static::transformField($shcemaValue, isset($paramValue[$schemaKey]) ? $paramValue[$schemaKey] : null)]);
			}

			return $transformCache;
		}

		$transformationOptions = [
			'purify'	=> function($paramValue) {
				self::$purifier = self::getHtmlPurifier();
				return self::$purifier->purify($paramValue);
			},
			'escape' 	=> function($paramValue) {
				return self::escapeHtmlCharacters($paramValue);
			},
			'purge' 	=> function($paramValue) {
				return self::purgeHtmlCharacters($paramValue);
			},
			'xss'		=> function($paramValue) {
				return self::filterXssSqlCharacters($paramValue);
			},
			'trim' 		=> function($paramValue) {
				return self::trim($paramValue);
			}
		];

		foreach ($schemaParams['transformations'] as $transformation) {
			if (isset($transformationOptions[strtolower($transformation)])) {
				$paramValue = $transformationOptions[strtolower($transformation)]($paramValue);
			}
		}

		return $paramValue;
	}

	
	/**
	 * Autodetect if a field is an array or scalar, and filter appropriately
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function escapeHtmlCharacters($value)
	{
		return is_array($value)
			? filter_var_array($value, FILTER_SANITIZE_SPECIAL_CHARS)
			: filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS);
	}


	/**
	 * Autodetect if a field is an array or scalar, and filter appropriately
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function purgeHtmlCharacters($value)
	{
		return is_array($value)
			? filter_var_array($value, FILTER_SANITIZE_STRING)
			: filter_var($value, FILTER_SANITIZE_STRING);
	}

	/**
	 * Filter XSS characters 
	 * @param string
	 */
	public static function filterXssSqlCharacters($value)
	{
		if (is_array($value)) {
			foreach ($value as $transformedValue) {
				return self::filterXssSqlCharacters($transformedValue);
			}
		}

		$transformedValue = html_entity_decode($value, ENT_NOQUOTES, 'UTF-8');
		$transformedValue = str_replace(['&amp;', '&lt;', '&gt;'], ['&amp;amp;', '&amp;lt;', '&amp;gt;'], $value);
		
		if (!self::$allowHtmlElements) {
			$transformedValue = str_replace(['&', '%', 'script', 'http', 'localhost'], ['', '', '', '', ''], $transformedValue);
		} else {
			$transformedValue = str_replace(['&', '%', 'script', 'localhost', '../'], ['', '', '', '', ''], $transformedValue);
		}

		array_walk(self::$xssSqlPattern, function($replaceVar, $pattern) use (&$transformedValue) {
			$transformedValue = str_replace($pattern, $replaceVar, $transformedValue);
		});

		return $transformedValue;
	}

	public static function filterXssHtmlCharacters($value)
	{
		foreach (self::$xssHtmlPattern as $pattern => $replaceVar) {
			$value = str_replace($pattern, $replaceVar, $value);
		}

		return $value;
	}


	/**
	 * Set the schema for this transformer, as a valid RequestSchema object
	 *
	 * @param RequestSchemaInterface 
	 * @return void
	 */
	protected static function setSchema(RequestSchemaInterface $schema)
	{
		static::$schema = $schema;
	}


	/**
	 * Unset the specific columns of array
	 * @param array[mixed] 	$schemaParams
	 */
	protected static function unsetSpecificColumns($schemaParams)
	{
		foreach (static::SPECIFIC_COLUMNS as $column) {
			if (isset($schemaParams[$column])) {
				unset($schemaParams[$column]);
			}
		}

		return $schemaParams;
	}


	protected static function getHtmlPurifier()
	{
		$config = \HTMLPurifier_Config::createDefault();
		$config = $config->set('Cache.DefinitionImpl', null);
		return new \HTMLPurifier($config);
	}


	/**
	 * Autodetect if a field is an array or scalar, and filter appropriately
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function trim($value)
	{
		return is_array($value) ? array_map('trim', $value) : trim($value);
	}


}