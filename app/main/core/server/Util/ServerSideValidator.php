<?php

namespace JingCafe\Core\Util;

use InvalidArgumentException;
use JingCafe\Core\Schema\RequestSchemaInterface;

/**
 * Validate data parameters of request schma for HTTP requests. 
 *
 *
 * @author Mong Cheng
 */
class ServerSideValidator extends Validator
{	
	const SPECIFIC_COLUMNS = ['transformations'];

	/**
	 * 
	 * @var ServerSideValidator instance
	 */
	private static $serverSideValidatorInstance;


	/**
	 * RequestSchemaInterace instance
	 * @var RequestSchemaInterface
	 */
	protected $schema;

	/**
	 * Validators
	 * @var array
	 */
	protected $validators = [];

	/**
	 * Validate method storage
	 * @var
	 */
	protected $rules = [];

	/**
	 * @var array
	 */
	protected $specifiedRules = [
		'length'
	];

	/**
	 * List of  validate method
	 * @var array
	 */
	// protected static $rules = [
	// 	'array',
	// 	'email',
	// 	'equals',
	// 	'integer',
	// 	'length',
	// 	'matches',
	// 	'member_of',
	// 	'no_leading_whitespace',
	// 	'no_trailing_whitespace',
	// 	'not_equals',
	// 	'not_member_of',
	// 	'numeric',
	// 	'boolean',
	// 	'range',
	// 	'regex',
	// 	'required',
	// 	'telephone',
	// 	'uri',
	// 	'username',
	// 	'phone'
	// ];
	
	private function __construct()
	{
	}


	/**
	 * Set schema validator
	 *
	 * @param RequestSchemaInterface 
	 */
	public static function loadSchema(RequestSchemaInterface $schema)
	{
		if (!isset(static::$serverSideValidatorInstance)) {
			static::$serverSideValidatorInstance = new static;
		}

		static::$serverSideValidatorInstance->setSchema($schema);

		return static::$serverSideValidatorInstance;
	}


	public static function addValidator($fieldName, $rule, $errorMessage = null)
	{
		if (!isset(static::$validators[$fieldName])) {
			unset(static::$validators[$fieldName]);
		}

		$ruleMethod = static::setValidateMethod($rule);
		static::isValidateMethodExist($ruleMethod);

		static::$validators[$fieldName][] = ['rule' => $ruleMethod, 'message' => $errorMessage];
	}


	public function setSchema(RequestSchemaInterface $schema)
	{
		$this->schema = $schema;
		return $this;
	}

	/**
	 * @deprecated old version 
	 * Lower spped
	 */
	// public static function executeValidate(array $data = [])
	// {
	// 	$schemaFields = static::$schema->all();

	// 	if (empty(static::$schema)) {
	// 		return $data;
	// 	}

	// 	// Filter the input data of schema column  
	// 	$fields = array_intersect_key($data, array_flip(array_keys($schemaFields)));
	// 	$transformedValues = [];

	// 	// Execution of validation
	// 	$validate = function($fieldName, $fieldValue, $validator) use (&$transformedValues) {
	// 		$transformedValues[$fieldName] = static::$validator['rule']($fieldValue, $validator['message']);
	// 	};

	// 	foreach ($fields as $fieldName => $fieldValue) {			
	// 		if (!isset(static::$validators[$fieldName])) {
	// 			$transformedValues[$fieldName] = $fieldValue;
	// 			continue; 
	// 		}

	// 		foreach (static::$validators[$fieldName] as $validator) {
	// 			$validate($fieldName, $fieldValue, $validator);
	// 		}
	// 	}
		
	// 	return $transformedValues;
	// }

	/**
	 * Get validators of schema file
	 * @return array
	 */
	public function getValidators()
	{
		return $this->validators;
	}

	/**
	 * Verfy params data with schema field  
	 *
	 * @param array[mixed]	$params
	 * @param bool 			$isClearUnexpectedVar
	 */
	public function validate($params = [], $isClearUnexpectedVar = true)
	{
		if (empty($schema = $this->schema->all())) {
			return $params;
		}

		$validateValues = [];

		foreach ($params as $paramKey => $paramValue) {
			if (isset($schema[$paramKey]) || array_key_exists($paramKey, $schema)) {
				$validateValues[$paramKey] = $this->validateFieldValue($schema[$paramKey], $paramKey, $paramValue);
			} else {
				if (!$isClearUnexpectedVar) {
					$validateValues[$paramKey] = $paramValue; 
				}
			}
		}

		return $validateValues;
	}



	/**
	 * Load schema and execute validate of data
	 * @param RequestSchemaInterface 	$schema
	 * @param array 					$data
	 */
	// public function validate(array $data = [])
	// {	
	// 	if (empty($this->validators)) {
	// 		return $data;
	// 	}

	// 	$fields = array_intersect_key($data, array_flip(array_keys($this->validators)));
	// 	$validatedValue = [];
	// 	$validators = $this->validators;

	// 	$validate = function($validator, $fieldName, $fieldValue) use (&$validatedValue) {
	// 		$validatedValue[$fieldName] = static::$validator['rule']($fieldValue, $validator['message'], $fieldName, $validator['params']);
	// 	};

	// 	// Execution Validatotion
	// 	foreach ($fields as $fieldName => $fieldValue) {
	// 		foreach ($validators[$fieldName] as $validator) {
	// 			$validate($validator, $fieldName, $fieldValue);
	// 		}
	// 	}

	// 	return $validatedValue;
	// }

	/**
	 * {@inheritDoc}
	 * 
	 * We expose this method to the public interface for testing purpose
	 */
	public function hasRule($name, $field)
	{
		return array_key_exists($field, $this->$validators[$name]);
	}
		

	/**
	 * Validate the field value 
	 *
	 * @param array[mixed] 	$fieldName
	 * @param string|array 	$paramValue
	 * @param string 		$paramKey 	The column of validator
	 * @return mixed 		The params data
	 */
	protected function validateFieldValue($schemaParams, $paramKey, $paramValue)
	{
		$schemaParams = $this->unsetSpecificColumns($schemaParams);

		if (!isset($schemaParams['validators'])) {
			// Whether or not the schemaParams have validate columns
			if (empty($schemaParams)) {
				return $paramValue;
			}

			$paramsCache = [];
			foreach ($schemaParams as $schemaKey => $schemaValue) {
				$paramsCache = array_merge($paramsCache, [$schemaKey => $this->validateFieldValue($schemaValue, $schemaKey, $paramValue[$schemaKey])]);
			}

			return $paramsCache;
		}

		try {
			foreach ($schemaParams['validators'] as $validatorName => $validator) {
				$message = isset($validator['message']) ? $validator['message'] : null;
				
				if (in_array($validatorName, $this->specifiedRules)) {
					unset($validator['message']);

					array_walk($validator, function($restriction, $rule) use ($paramValue, $paramKey, $message) {
						if (!is_array($restriction)) {
							$restriction = [$restriction];
						}

						$this->verifyValue($rule, $paramValue, $message, $restriction);
					});
				} else  {
					$paramValue = $this->verifyValue($validatorName, $paramValue, $message);
				}
			}
		} catch(InvalidArgumentException $e) {
			throw new InvalidArgumentException($e->getMessage() . '|' . $paramKey);
		}

		return $paramValue;
	}


	/**
	 * Determine whether the rule method is exist
	 * @return bool
	 */
	protected static function isValidateMethodExist($ruleMethod)
	{
		return method_exists(__CLASS__, $ruleMethod);
 		// throw new \InvalidArgumentException("Rule {$ruleMethod} has not been register with ". __CLASS__);
	}

	/**
	 * Unset specific columns of array
	 * @param array 
	 */ 
	protected function unsetSpecificColumns($schemaParams)
	{
		foreach (static::SPECIFIC_COLUMNS as $column) {
			if (isset($schemaParams[$column])) {
				unset($schemaParams[$column]);
			}
		}

		return $schemaParams;
	}


	/**
	 * Find the verifyed method exist and execute method
	 *
	 * @param string 	$rule
	 * @param mixed 	$value
	 * @param string 	$message
	 * @param array 	$params
	 * @throws InvalidArgumentException
	 * @return mixed|null
	 */
	protected function verifyValue($rule, $value, $message, $params = [])
	{
		$ruleMethod = static::buildValidateMethod($rule);

		if (static::isValidateMethodExist($ruleMethod)) {
			return static::$ruleMethod($value, $message, $params);
		}

		return null;
	}

	/**
	 * Load file of schema validators
	 */
	protected function loadValidators()
	{
		$fields = $this->schema->all();

		if (!isset($fields) || !is_array($fields)) {
			return;
		}

		foreach ($fields as $fieldName => $field) {
			if (!isset($field['validators'])) {
				continue;
			}
			
			foreach ($field['validators'] as $validatorName => $validator) {		
				// Find validate in rules 
				if (in_array($validatorName, $this->rules)) {
					continue;
				} elseif (in_array($validatorName, $this->specifiedRules)) {
					$errorMessage = isset($validator['message']) ? $validator['message'] : null;
					unset($validator['message']);
					array_walk($validator, function($innerValue, $innerName) use ($fieldName, $errorMessage) {
						if (!is_array($innerValue)) {
							$innerValue = [$innerValue];
						}

						$this->setValidateRules($fieldName, $innerName, $errorMessage, $innerValue);
					});
				} else {
					$errorMessage = isset($validator['message']) ? $validator['message'] : null;
					$this->setValidateRules($fieldName, $validatorName, $errorMessage);
				}
			}
		}
	}

	/**
	 * Load valdiate rule in validators of property
	 *
	 * @param string 		$fieldName
	 * @param string 		$ruel
	 * @param string|null 	$message
	 * @param array|null 	$params
	 */
	protected function setValidateRules($fieldName, $rule, $message, array $params = [])
	{
		$ruleMethod = static::buildValidateMethod($rule);

		if (static::isValidateMethodExist($ruleMethod)) {
			$this->rules[] = $rule;
			$this->validators[$fieldName][] = [
				'rule' 		=> $ruleMethod,
				'message' 	=> $message,
				'params'	=> $params
			];
		}
	}

	/**
	 * Validate that a field contains only valid username characters: alpha-number, dots dashes and underscores
	 *
	 * @param string 	$field
	 * @param mixed 	$value
	 * @return bool
	 */
	protected function validateUsername($field, $value)
	{	
		//$value = preg_replace('/\s+/', '', $value);
		return preg_match('/^([a-z0-9\.\-_])+$/i', $value);
	}

	protected static function buildValidateMethod($value)
	{
		return 'validate' . Util::convertToCamelCase($value);		
	}

	/**
	 * Validate a field has a particular value
	 *
	 * @param string 	$field
	 * @param mixed 	$value
	 * @param string 	$targetValue
	 * @param bool 		$caseSensitive
	 * @return bool
	 */
	protected function validateEqualsValue($field, $value, $params)
	{
		if (is_bool($params[1]) && !$params[1]) {
			$value = strtolower($value);
			$params[0] = strtolower($params[0]);
		}

		return $value === $params[0];
	}

	/**
	 * Validates a field does not have a particular value.
	 *
	 * @param string 	$field 
	 * @param mixed 	$value
	 * @param string 	$targetValue
	 * @param bool 		$caseSensitive
	 * @return bool
	 */
	protected function validateNotEqualsValue($field, $value, $params)
	{
		return !$this->validateEqualsValue($field, $value, $params);
	}

	/**
	 * Add a rule to the validator, along with a specified error message if that rule is failed to the data.
	 *
	 * @param string 	$rule 		The name of the validation rule.
	 * @param strinb 	$message 	The message tp display when validation against this rule fails 
	 */
	private function ruleWithMessage($rule, $message)
	{
		// Weird way to adapt with Valitron's funky interface
		$params = array_merge([$rule], array_slice(func_get_args(), 2));
		call_user_func_array([$this, 'rule'], $params);

		if (!$message) {
			$message = "'" . $params[1] . "' " . vsprintf(static::$_ruleMessages[$rule], array_slice(func_get_args(), 3));
		}

		$this->message($message);
	}

}

