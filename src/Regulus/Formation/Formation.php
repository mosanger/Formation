<?php namespace Regulus\Formation;

/*----------------------------------------------------------------------------------------------------------
	Formation
		A powerful form creation and form data saving composer package for Laravel 4.

		created by Cody Jassman
		version 0.6.7.2
		last updated on November 12, 2014
----------------------------------------------------------------------------------------------------------*/

use Illuminate\Html\FormBuilder;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

class Formation extends FormBuilder {

	/**
	 * The default values for form fields.
	 *
	 * @var array
	 */
	protected $defaults = [];

	/**
	 * The labels for form fields.
	 *
	 * @var array
	 */
	protected $labels = [];

	/**
	 * The access keys for form fields.
	 *
	 * @var array
	 */
	protected $accessKeys = [];

	/**
	 * The validation rules (routed through Formation's validation() method to Validator library to allow
	 * automatic addition of error classes to labels and fields).
	 *
	 * @var array
	 */
	protected $validation = [];

	/**
	 * The form fields to be validated.
	 *
	 * @var array
	 */
	protected $validationFields = [];

	/**
	 * Laravel FormRequest
	 *
	 * @var array
	 */
	protected $formRequest;

	/**
	 * Default Error Messages
	 *
	 * @var array
	 */
	protected $defaultErrors = [];

	/**
	 * The form values array or object.
	 *
	 * @var array
	 */
	protected $values = [];

	/**
	 * The form errors.
	 *
	 * @var array
	 */
	protected $errors = [];

	/**
	 * Whether form fields are being reset to their default values rather than the POSTed values.
	 *
	 * @var bool
	 */
	protected $reset = false;

	/**
	 * The request spoofer.
	 *
	 * @var string
	 */
	protected $spoofer = '_method';

	/**
	 * Cache application encoding locally to save expensive calls to config::get().
	 *
	 * @var string
	 */
	protected $encoding = null;

	/**
	 * Returns the POST data.
	 *
	 * @return mixed
	 */
	public function post()
	{
		if (Input::old())
			return Input::old();

		return Input::all();
	}

	/**
	 * Sets the default values for the form.
	 *
	 * @param  array    $defaults
	 * @param  array    $relations
	 * @param  mixed    $prefix
	 * @return array
	 */
	public function setDefaults($defaults = [], $relations = [], $prefix = null)
	{
		//check if relations is an associative array
		$associative = (bool) count(array_filter(array_keys((array) $relations), 'is_string'));

		//prepare prefix
		if (is_string($prefix) && $prefix != "")
			$prefix .= ".";
		else
			$prefix = "";

		//format default values for times
		$defaults = $this->formatDefaults($defaults);

		//set defaults array
		$defaultsArray = $this->defaults;

		//turn Eloquent collection into an array
		if (isset($defaults) && isset($defaults->incrementing) && isset($defaults->timestamps))
			$defaultsFormatted = $defaults->toArray();
		else
			$defaultsFormatted = $defaults;

		foreach ($defaultsFormatted as $field => $value) {
			$addValue = true;
			if ((is_array($value) || is_object($value)) && ! (int) $field)
				$addValue = false;

			if ($addValue)
				$defaultsArray[$prefix.$field] = $value;
		}

		//the suffix that formatted values will have if Formation's BaseModel is used as the model
		$formattedSuffix = $this->getFormattedFieldSuffix();

		//add relations data to defaults array if it is set
		if (!empty($relations)) {
			$i = 1;

			foreach ($relations as $key => $relation) {

				$relationField = false;
				if ($associative) {
					if (is_string($relation))
						$relationField = $relation;

					$relation = $key;
				}

				if (count($defaults->{$relation}))
				{
					foreach ($defaults->{$relation} as $item) {
						$item = $item->toArray();

						$itemPrefix = $prefix.($this->camelCaseToUnderscore($relation));

						foreach ($item as $field => $value)
						{
							if (!$relationField || $relationField == $field || ($relationField && $field == "pivot"))
							{
								if ($field == "pivot") {
									foreach ($value as $pivotField => $pivotValue)
									{
										if ($relationField) {
											if ($relationField == $pivotField)
												$defaultsArray[$itemPrefix.'.pivot.'][] = $pivotValue;
										} else {
											if (substr($field, -(strlen($formattedSuffix))) == $formattedSuffix)
												$fieldName = str_replace($formattedSuffix, '', $pivotField);
											else
												$fieldName = $pivotField;

											$defaultsArray[$itemPrefix.'.'.$i.'.pivot.'.$fieldName] = $pivotValue;
										}
									}
								} else {
									if (substr($field, -(strlen($formattedSuffix))) == $formattedSuffix)
										$fieldName = str_replace($formattedSuffix, '', $field);
									else
										$fieldName = $field;

									if ($relationField)
										$defaultsArray[$itemPrefix][] = $value;
									else
										$defaultsArray[$itemPrefix.'.'.$i.'.'.$fieldName] = $value;
								}
							}
						}

						$i ++;
					}

					$i ++;
				}
			}
		}

		$this->defaults = $defaultsArray;
		return $this->defaults;
	}

	/**
	 * Format default values for times.
	 *
	 * @param  array    $defaults
	 * @return void
	 */
	private function formatDefaults($defaults = [])
	{
		foreach ($defaults as $field => $value) {
			$fieldArray = explode('.', $field);

			//divide any field that starts with "time" into "hour", "minutes", and "meridiem" fields
			if (substr(end($fieldArray), 0, 4) == "time") {
				$valueArray = explode(':', $value);
				if (count($valueArray) >= 2) {
					$defaults[$field.'_hour']     = $valueArray[0];
					$defaults[$field.'_minutes']  = $valueArray[1];
					$defaults[$field.'_meridiem'] = "am";
					if ($valueArray[0] >= 12) {
						$defaults[$field.'_hour']     -= 12;
						$defaults[$field.'_meridiem']  = "pm";
					}
				}
			}
		}
		return $defaults;
	}

	/**
	 * Get formatted field suffix.
	 *
	 * @return string
	 */
	public function getFormattedFieldSuffix()
	{
		return "_formatted";
	}

	/**
	 * Get an array of all values. Turns values with decimal notation names back into proper arrays.
	 *
	 * @param  mixed    $name
	 * @param  boolean  $object
	 * @param  boolean  $defaults
	 * @return mixed
	 */
	public function getValuesArray($name = null, $object = false, $defaults = false) {
		$result = [];

		if (!$defaults && Input::all() || Input::old()) {
			if (Input::all())
				$values = Input::all();
			else
				$values = Input::old();

			$result = $values;
		} else {
			foreach ($this->defaults as $field => $value) {
				$s = explode('.', $field);

				if (!is_null($value)) {
					switch (count($s)) {
						case 1:	$result[$s[0]] = $value; break;
						case 2:	$result[$s[0]][$s[1]] = $value; break;
						case 3:	$result[$s[0]][$s[1]][$s[2]] = $value; break;
						case 4:	$result[$s[0]][$s[1]][$s[2]][$s[3]] = $value; break;
						case 5:	$result[$s[0]][$s[1]][$s[2]][$s[3]][$s[4]] = $value; break;
						case 6:	$result[$s[0]][$s[1]][$s[2]][$s[3]][$s[4]][$s[5]] = $value; break;
						case 7:	$result[$s[0]][$s[1]][$s[2]][$s[3]][$s[4]][$s[5]][$s[6]] = $value; break;
					}
				}
			}
		}

		if (!is_null($name)) {
			if (isset($result[$name]))
				$result = $result[$name];
			else
				$result = [];
		}

		if ($object)
			$result = json_decode(json_encode($result));

		$this->values = $result;

		return $result;
	}

	/**
	 * Get an object of all values.
	 *
	 * @param  mixed    $name
	 * @return object
	 */
	public function getValuesObject($name = null)
	{
		return $this->getValuesArray($name, true, false);
	}

	/**
	 * Get a JSON string of all values.
	 *
	 * @param  mixed    $name
	 * @return object
	 */
	public function getJsonValues($name = null)
	{
		return addslashes(json_encode($this->getValuesArray($name)));
	}

	/**
	 * Get an array of all default values. Turns values with decimal notation names back into proper arrays.
	 *
	 * @param  mixed    $name
	 * @return array
	 */
	public function getDefaultsArray($name = null)
	{
		return $this->getValuesArray($name, false, true);
	}

	/**
	 * Get an object of all default values.
	 *
	 * @param  mixed    $name
	 * @return object
	 */
	public function getDefaultsObject($name = null)
	{
		return $this->getValuesArray($name, true, true);
	}

	/**
	 * Get a value from an array if it exists.
	 *
	 * @param  string   $field
	 * @param  array    $values
	 * @return string
	 */
	public function getValueFromArray($field, $values = null)
	{
		if (isset($values[$field]))
			return $values[$field];

		return "";
	}

	/**
	 * Get a value from an object if it exists.
	 *
	 * @param  string   $field
	 * @param  object   $values
	 * @return string
	 */
	public function getValueFromObject($field, $values = null)
	{
		$fieldKeys = explode('.', $field);

		if (is_null($values))
			$values = $this->values;

		if (!is_object($values))
			$values = json_decode(json_encode($values));

		if (count($fieldKeys) == 1) {
			if (isset($values->{$fieldKeys[0]}))
				return $values->{$fieldKeys[0]};
		} else if (count($fieldKeys) == 2) {
			if (isset($values->{$fieldKeys[0]}->{$fieldKeys[1]}))
				return $values->{$fieldKeys[0]}->{$fieldKeys[1]};
		} else if (count($fieldKeys) == 3) {
			if (isset($values->{$fieldKeys[0]}->{$fieldKeys[1]}->{$fieldKeys[2]}))
				return $values->{$fieldKeys[0]}->{$fieldKeys[1]}->{$fieldKeys[2]};
		}

		return "";
	}

	/**
	 * Reset form field values back to defaults and ignores POSTed values.
	 *
	 * @param  array    $defaults
	 * @return void
	 */
	public function resetDefaults($defaults = [])
	{
		if (!empty($defaults)) $this->setDefaults($defaults); //if new defaults are set, pass them to $this->defaults
		$this->reset = true;
	}

	/**
	 * Assign labels to form fields.
	 *
	 * @param  array    $labels
	 * @return void
	 */
	public function setLabels($labels = [])
	{
		if (is_object($labels))
			$labels = (array) $labels;

		$this->labels = array_merge($this->labels, $labels);
	}

	/**
	 * Get the labels for form fields.
	 *
	 * @return array
	 */
	public function getLabels()
	{
		return $this->labels;
	}

	public function setDefaultErrors($messages = [])
	{
		$this->defaultErrors = $messages;
	}

	/**
	 * Route Validator validation rules through Formation to allow Formation
	 * to automatically add error classes to labels and fields.
	 *
	 * @param  array    $rules
	 * @param  mixed    $prefix
	 * @return array
	 */
	public function setValidationRules($rules = [], $prefix = null)
	{
		$rulesFormatted = [];
		foreach ($rules as $name => $rulesItem) {
			if (!is_null($prefix))
				$name = $prefix.'.'.$name;

			$this->validationFields[] = $name;

			$rulesArray = explode('.', $name);
			$last = $rulesArray[(count($rulesArray) - 1)];
			if (count($rulesArray) < 2) {
				$rulesFormatted['root'][$last] = $rulesItem;
			} else {
				$rulesFormatted[str_replace('.'.$last, '', $name)][$last] = $rulesItem;
			}
		}

		foreach ($rulesFormatted as $name => $rules) {
			if ($name == "root") {
				$this->validation['root'] = Validator::make(Input::all(), $rules);
			} else {
				$data = Input::get($name);
				if (is_null($data))
					$data = [];

				$this->validation[$name] = Validator::make($data, $rules);
			}
		}

		return $this->validation;
	}

	/**
	 * Check if one or all Validator instances are valid.
	 *
	 * @param  string   $index
	 * @return bool
	 */
	public function validated($index = null)
	{
		//if index is null, cycle through all Validator instances
		if (is_null($index)) {
			foreach ($this->validation as $fieldName => $validation) {
				if ($validation->fails()) return false;
			}
		} else {
			if (substr($index, -1) == ".") { //index ends in "."; validate all fields that start with that index
				foreach ($this->validation as $fieldName => $validation) {
					if (substr($fieldName, 0, strlen($index)) == $index) {
						if ($validation->fails()) return false;
					}
				}
			} else {
				if (isset($this->validation[$index])) {
					if ($this->validation[$index]->fails()) return false;
				} else {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Set up whole form with one big array
	 *
	 * @param  array    $form
	 * @return array
	 */
	public function setup($form = [])
	{
		$labels   = [];
		$rules    = [];
		$defaults = [];

		if (is_object($form))
			$form = (array) $form;

		foreach ($form as $name => $field) {
			if (is_object($field))
				$field = (array) $field;

			if (isset($field[0]) && !is_null($field[0]) && $field[0] != "") $labels[$name]   = $field[0];
			if (isset($field[1]) && !is_null($field[1]) && $field[1] != "") $rules[$name]    = $field[1];
			if (isset($field[2]) && !is_null($field[2]) && $field[2] != "") $defaults[$name] = $field[2];
		}

		$this->setLabels($labels);
		$this->setValidationRules($rules);
		$this->setDefaults($defaults);

		return $this->validation;
	}

	/**
	 * Determine the appropriate request method to use for a form.
	 *
	 * @param  string  $method
	 * @return string
	 */
	protected function method($method = 'POST')
	{
		return $method !== "GET" ? "POST" : $method;
	}

	/**
	 * Determine the appropriate request method for a resource controller form.
	 *
	 * @param  mixed   $route
	 * @return string
	 */
	public function methodResource($route = null)
	{
		$route  = $this->route($route);
		$method = "POST";

		if (substr($route[0], -5) == ".edit")
			$method = "PUT";

		return $method;
	}

	/**
	 * Determine the appropriate action parameter to use for a form.
	 *
	 * If no action is specified, the current request URI will be used.
	 *
	 * @param  string   $action
	 * @param  bool     $https
	 * @return string
	 */
	protected function route($route = null)
	{
		if (!is_null($route))
			return $route;

		return array_merge(
			[Route::currentRouteName()],
			array_values(Route::getCurrentRoute()->parameters())
		);
	}

	/**
	 * Open up a new HTML form and populate defaults with model properties
	 *
	 * @param  \Illuminate\Database\Eloquent\Model    $model
	 * @param  array    $options
	 * @return string
	 */
	public function model($model, array $options = [])
	{
		$this->setDefaults($model);

		return $this->open($options);
	}

	/**
	 * Open up a new HTML form.
	 *
	 * @param  array    $options
	 * @return string
	 */
	public function open(array $options = [])
	{
		$this->setErrors();

		$method = array_get($options, 'method', 'post');

		// We need to extract the proper method from the attributes. If the method is
		// something other than GET or POST we'll use POST since we will spoof the
		// actual method since forms don't support the reserved methods in HTML.
		$attributes['method'] = $this->getMethod($method);

		$attributes['action'] = $this->getAction($options);

		$attributes['accept-charset'] = 'UTF-8';

		// If the method is PUT, PATCH or DELETE we will need to add a spoofer hidden
		// field that will instruct the Symfony request to pretend the method is a
		// different method than it actually is, for convenience from the forms.
		$append = $this->getAppendage($method);

		if (isset($options['files']) && $options['files'])
		{
			$options['enctype'] = 'multipart/form-data';
		}

		// Finally we're ready to create the final form HTML field. We will attribute
		// format the array of attributes. We will also add on the appendage which
		// is used to spoof requests for this PUT, PATCH, etc. methods on forms.
		$attributes = array_merge(

			$attributes, array_except($options, $this->reserved)

		);

		// Finally, we will concatenate all of the attributes into a single string so
		// we can build out the final form open statement. We'll also append on an
		// extra value for the hidden _method field if it's needed for the form.
		$attributes = $this->html->attributes($attributes);

		return '<form'.$attributes.'>'."\n\n\t".$append;
	}

	/**
	 * Open an HTML form that automatically corrects the action for a resource controller.
	 *
	 * @param  mixed   $route
	 * @param  array   $attributes
	 * @return string
	 */
	public function openResource(array $attributes = [])
	{
		$route = $this->route();

		//set method based on action
		$method = $this->methodResource($route);

		$route[0] = str_replace('create', 'store', $route[0]);
		$route[0] = str_replace('edit', 'update', $route[0]);

		$options = array_merge([
			'route'  => $route,
			'method' => $method,
		], $attributes);

		return $this->open($options);
	}

	/**
	 * Get the value of the form or of a form field array.
	 *
	 * @param  string  $name
	 * @param  string  $type
	 * @return mixed
	 */
	public function values($name = null)
	{
		if (is_string($name))
			$name = str_replace('(', '', str_replace(')', '', $name));

		if ($_POST && !$this->reset) {
			return Input::get($name);
		} else if (Input::old($name) && !$this->reset) {
			return Input::old($name);
		} else {
			return $this->getDefaultsArray($name);
		}
	}

	/**
	 * Get the value of the form field. If no POST data exists or reinitialize() has been called, default value
	 * will be used. Otherwise, POST value will be used. Using "checkbox" type ensures a boolean return value.
	 *
	 * @param  string  $name
	 * @param  string  $type
	 * @return mixed
	 */
	public function value($name, $type = 'standard')
	{
		$name  = str_replace('(', '', str_replace(')', '', $name));
		$value = "";

		if (isset($this->defaults[$name]))
			$value = $this->defaults[$name];

		if ($_POST && !$this->reset)
			$value = Input::get($name);

		if (!is_null(Input::old($name)) && !$this->reset)
			$value = Input::old($name);

		if ($type == "checkbox")
			$value = (bool) $value;

		return $value;
	}

	/**
	 * Get the time value from 3 individual fields created from the selectTime() method.
	 *
	 * @param  string  $name
	 * @param  string  $type
	 * @return mixed
	 */
	public function valueTime($name)
	{
		if (substr($name, -1) != "_") $name .= "_";

		$hour     = Input::get($name.'hour');
		$minutes  = Input::get($name.'minutes');
		$meridiem = Input::get($name.'meridiem');

		if ($hour == 12)
			$hour = 0;

		if ($meridiem == "pm")
			$hour += 12;

		return sprintf('%02d', $hour).':'.sprintf('%02d', $minutes).':00';
	}

	/**
	 * Add values to a data object or array.
	 *
	 * @param  mixed   $values
	 * @param  array   $fields
	 * @return mixed
	 */
	public function addValues($data = [], $fields = [])
	{
		$associative = (bool) count(array_filter(array_keys((array) $fields), 'is_string'));

		if ($associative) {
			foreach ($fields as $field => $config) {
				$add = true;

				if (is_bool($config) || $config == "text") {
					$value = trim($this->value($field));

					if (!$config)
						$add = false;
				} else if (is_array($config)) {
					$value = trim($this->value($field));

					if (!in_array($value, $config))
						$add = false;
				} else if ($config == "checkbox") {
					$value = $this->value($field, 'checkbox');
				}

				if ($add) {
					if (is_object($data))
						$data->{$field} = $value;
					else
						$data[$field]   = $value;
				}
			}
		} else {
			foreach ($fields as $field) {
				$value = trim($this->value($field));

				if (is_object($data))
					$data->{$field} = $value;
				else
					$data[$field]   = $value;
			}
		}

		return $data;
	}

	/**
	 * Add checkbox values to a data object or array.
	 *
	 * @param  mixed   $values
	 * @param  array   $checkboxes
	 * @return mixed
	 */
	public function addCheckboxValues($data = [], $checkboxes = [])
	{
		foreach ($checkboxes as $checkbox) {
			$value = $this->value($checkbox, 'checkbox');

			if (is_object($data))
				$data->{$checkbox} = $value;
			else
				$data[$checkbox]   = $value;
		}

		return $data;
	}

	/**
	 * Check whether a checkbox is checked.
	 *
	 * @param  string  $name
	 * @return boolean
	 */
	public function checked($name)
	{
		return $this->value($name, 'checkbox');
	}

	/**
	 * Format array named form fields from strings with period notation for arrays ("data.id" = "data[id]")
	 *
	 * @param  string  $name
	 * @return string
	 */
	protected function name($name)
	{
		//remove index number from between round brackets
		if (preg_match("/\((.*)\)/i", $name, $match)) $name = str_replace($match[0], '', $name);

		$nameArray = explode('.', $name);
		if (count($nameArray) < 2) return $name;

		$nameFormatted = $nameArray[0];
		for ($n=1; $n < count($nameArray); $n++) {
			$nameFormatted .= '['.$nameArray[$n].']';
		}
		return $nameFormatted;
	}

	/**
	 * Create an HTML label element.
	 *
	 * <code>
	 *		// Create a label for the "email" input element
	 *		echo Form::label('email', 'Email Address');
	 * </code>
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  array   $attributes
	 * @param  boolean $save
	 * @return string
	 */
	public function label($name = null, $label = null, $attributes = [], $save = true)
	{
		//$attributes = $this->addErrorClass($name, $attributes);

		if (!is_null($name) && $name != "") {
			if (is_null($label)) $label = $this->nameToLabel($name);
		} else {
			if (is_null($label)) $label = "";
		}

		//save label in labels array if a label string contains any characters and $save is true
		if ($label != "" && $save)
			$this->labels[$name] = $label;

		//get ID of field for label's "for" attribute
		if (!isset($attributes['for'])) {
			$id = $this->id($name);
			$attributes['for'] = $id;
		}

		//add label suffix
		$suffix = Config::get('formation.label.suffix');
		if ($suffix != "" && (!isset($attributes['suffix']) || $attributes['suffix']))
			$label .= $suffix;

		if (isset($attributes['suffix']))
			unset($attributes['suffix']);

		//add tooltip and tooltip attributes if necessary
		if (Config::get('formation.error.typeLabelTooltip')) {
			$errorMessage = $this->errorMessage($name);

			if ($errorMessage) {
				$addAttributes = Config::get('formation.error.typeLabelAttributes');
				foreach ($addAttributes as $attribute => $attributeValue) {
					if (isset($attributes[$attribute]))
						$attributes[$attribute] .= ' '.$attributeValue;
					else
						$attributes[$attribute] = $attributeValue;
				}

				//set tooltip error message
				$attributes['title'] = str_replace('"', '&quot;', $errorMessage);
			}
		}

		//if any "{" characters are used, do not add "access" class for accesskey; Handlebars.js may be being used in field name or label
		if (preg_match('/\{/', $name)) $attributes['accesskey'] = false;

		//also do not add accesskey depiction if label already contains HTML tags or HTML special characters
		if ($label != strip_tags($label) || $label != $this->entities($label)) {
			$attributes['accesskey'] = false;
		} else {
			$label = $this->entities($label); //since there is no HTML present in label, convert entities to HTML special characters
		}

		//add accesskey
		$attributes = $this->addAccessKey($name, $label, $attributes, false);

		//add "control-label" class
		if (!isset($attributes['control-label-class']) || $attributes['control-label-class']) {
			if (isset($attributes['class']) && $attributes['class'] != "") {
				$attributes['class'] .= ' '.Config::get('formation.label.class');
			} else {
				$attributes['class'] = Config::get('formation.label.class');
			}
		}
		$attributesField = $this->addValidationAttributes($name, null, $attributes);
		if (isset($attributesField['required']))
		{
			$attributes['class'] = (isset($attributes['class']) ? $attributes['class'] . ' ' : '') . 'required';
		}

		if (isset($attributes['control-label-class'])) unset($attributes['control-label-class']);

		$tooltip = '';
		if (isset($attributes['tooltip']))
		{
			$tooltip = '<span class="tooltip" title="' . $this->entities($attributes['tooltip']) . '"></span>';
			unset($attributes['tooltip']);
		}

		//add non-breakable space if label is empty
		if ($label == "") $label = "&nbsp;";

		if (is_array($attributes) && isset($attributes['accesskey'])) {
			if (is_string($attributes['accesskey'])) {
				$newLabel = preg_replace('/'.strtoupper($attributes['accesskey']).'/', '<span class="access">'.strtoupper($attributes['accesskey']).'</span>', $label, 1);
				if ($newLabel == $label) { //if nothing changed with replace, try lowercase
					$newLabel = preg_replace('/'.$attributes['accesskey'].'/', '<span class="access">'.$attributes['accesskey'].'</span>', $label, 1);
				}
				$label = $newLabel;
			}
			unset($attributes['accesskey']);
		}

		$attributes = $this->attributes($attributes);

		return '<label'.$attributes.'>'.$tooltip.$label.'</label>' . "\n";
	}

	/**
	 * Create an HTML label element.
	 *
	 * <code>
	 *		// Create a label for the "email" input element
	 *		echo Form::label('email', 'E-Mail Address');
	 * </code>
	 *
	 * @param  string  $name
	 * @return string
	 */
	protected function nameToLabel($name)
	{
		if (isset($this->labels[$name]))
			return $this->labels[$name];

		$nameArray = explode('.', $name);
		if (count($nameArray) < 2) {
			$nameFormatted = str_replace('_', ' ', $name);
		} else { //if field is an array, create label from last array index
			$nameFormatted = str_replace('_', ' ', $nameArray[(count($nameArray) - 1)]);
		}

		//convert icon code to markup
		if (preg_match('/\[ICON:(.*)\]/', $nameFormatted, $match)) {
			$nameFormatted = str_replace($match[0], '<span class="glyphicon glyphicon-'.str_replace(' ', '', $match[1]).'"></span>&nbsp; ', $nameFormatted);
		}

		if ($nameFormatted == strip_tags($nameFormatted)) $nameFormatted = ucwords($nameFormatted);
		return $nameFormatted;
	}

	/**
	 * Add an accesskey attribute to a field based on its name.
	 *
	 * @param  string  $name
	 * @param  string  $label
	 * @param  array   $attributes
	 * @param  boolean $returnLowercase
	 * @return array
	 */
	public function addAccessKey($name, $label = null, $attributes = [], $returnLowercase = true)
	{
		if (!isset($attributes['accesskey']) || (!is_string($attributes['accesskey']) && $attributes['accesskey'] === true)) {
			$accessKey = false;
			if (is_null($label)) {
				if (isset($this->labels[$name])) {
					$label = $this->labels[$name];
				} else {
					$label = $this->nameToLabel($name);
				}
			}

			$label = strtr($label, 'Ã Ã¡Ã¢Ã£Ã¤Ã§Ã¨Ã©ÃªÃ«Ã¬Ã­Ã®Ã¯Ã±Ã²Ã³Ã´ÃµÃ¶Ã¹ÃºÃ»Ã¼Ã½Ã¿Ã€ÃÃ‚ÃƒÃ„Ã‡ÃˆÃ‰ÃŠÃ‹ÃŒÃÃŽÃÃ‘Ã’Ã“Ã”Ã•Ã–Ã™ÃšÃ›ÃœÃ', 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');
			$ignoreCharacters = [' ', '/', '!', '@', '#', '$', '%', '^', '*', '(', ')', '-', '_', '+', '=', '\\', '~', '?', '{', '}', '[', ']', '.'];

			//first check to see if an accesskey is already set for this field
			foreach ($this->accessKeys as $character => $nameAccessKey) {
				if ($nameAccessKey == $name) $accessKey = $character;
			}

			//if no accesskey is set, loop through the field name's characters and set one
			for ($l=0; $l < strlen($label); $l++) {
				if (!$accessKey) {
					$character = strtolower($label[$l]);
					if (!isset($this->accessKeys[$character]) && !in_array($character, $ignoreCharacters)) {
						$this->accessKeys[$character] = $name;
						$accessKey = $character;
					}
				}
			}

			if ($accessKey) {
				$attributes['accesskey'] = $accessKey;
				if ($returnLowercase) $attributes['accesskey'] = strtolower($attributes['accesskey']);
			}
		} else {
			if ($attributes['accesskey'] === false) unset($attributes['accesskey']); //allow ability to prevent accesskey by setting it to false
		}
		return $attributes;
	}

	/**
	 * Determine the ID attribute for a form element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	protected function id($name, $attributes = [])
	{
		// If an ID has been explicitly specified in the attributes, we will
		// use that ID. Otherwise, we will look for an ID in the array of
		// label names so labels and their elements have the same ID.
		if (array_key_exists('id', $attributes)) {
			$id = $attributes['id'];
		} else {
			//replace array denoting periods and underscores with dashes
			$id = strtolower(str_replace('.', '-', str_replace('_', '-', str_replace(' ', '-', $name))));

			//add ID prefix
			$idPrefix = Config::get('formation.field.idPrefix');
			if (!is_null($idPrefix) && $idPrefix !== false && $idPrefix != "")
				$id = $idPrefix.$id;
		}

		//remove icon code
		if (preg_match('/\[ICON:(.*)\]/i', $id, $match)) {
			$id = str_replace($match[0], '', $id);
		}

		//remove round brackets that are used to prevent index number from appearing in field name
		$id = str_replace('(', '', str_replace(')', '', $id));

		//replace double dashes with single dash
		$id = str_replace('--', '-', $id);

		//remove end dash if one exists
		if (substr($id, -1) == "-")
			$id = substr($id, 0, (strlen($id) - 1));

		//unset ID attribute if ID is empty
		if (!$id || $id == "")
			unset($attributes['id']);

		return $id;
	}

	public function helptext($text, $inline = false)
	{
		if ($text)
		{
			$text = $this->entities($text);
			$tag = $inline ? 'span' : 'p';
			return '<' . $tag . ' class="helptext">' . $text . '</' . $tag . '>';
		}
		return '';
	}

	/**
	 * Automatically set the field class for a field.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @param  string  $type
	 * @return array
	 */
	protected function setFieldClass($name, $attributes = [], $type = 'text')
	{
		if (!in_array($type, ['hidden', 'checkbox', 'radio'])) {
			$defaultClass = Config::get('formation.field.class');
			if ($defaultClass != "") {
				if (isset($attributes['class']) && $attributes['class'] != "") {
					$attributes['class'] .= ' '.$defaultClass;
				} else {
					$attributes['class'] = $defaultClass;
				}
			}
		}

		$nameSegments = explode('.', $name);
		$fieldClass   = strtolower(str_replace('_', '-', str_replace(' ', '-', end($nameSegments))));

		//add "pivot" prefix to field name if it exists
		if (count($nameSegments) > 1 && $nameSegments[count($nameSegments) - 2] == "pivot")
			$fieldClass = $nameSegments[count($nameSegments) - 2]."-".$fieldClass;

		//remove icon code
		if (preg_match('/\[ICON:(.*)\]/i', $fieldClass, $match)) {
			$fieldClass = str_replace($match[0], '', $fieldClass);
		}

		//remove round brackets that are used to prevent index number from appearing in field name
		$fieldClass = str_replace('(', '', str_replace(')', '', $fieldClass));

		//remove end dash if one exists
		if (substr($fieldClass, -1) == "-")
			$fieldClass = substr($fieldClass, 0, (strlen($fieldClass) - 1));

		if ($fieldClass != "") {
			$fieldClass = "field-".$fieldClass;

			//replace double dashes with single dash
			$fieldClass = str_replace('--', '-', $fieldClass);

			if (isset($attributes['class']) && $attributes['class'] != "") {
				$attributes['class'] .= ' '.$fieldClass;
			} else {
				$attributes['class'] = $fieldClass;
			}
		}

		return $attributes;
	}

	/**
	 * Automatically set a "placeholder" attribute for a field.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return array
	 */
	protected function setFieldPlaceholder($name, $attributes = [])
	{
		$placeholder = Config::get('formation.field.autoPlaceholder');
		if ($placeholder && !isset($attributes['placeholder'])) {
			$namePlaceholder = $name;
			if (isset($this->labels[$name]) && $this->labels[$name] != "") {
				$namePlaceholder = $this->labels[$name];
			} else {
				$namePlaceholder = $this->nameToLabel($name);
			}

			if (substr($namePlaceholder, -1) == ":")
				$namePlaceholder = substr($namePlaceholder, 0, (strlen($namePlaceholder) - 1));

			$attributes['placeholder'] = $namePlaceholder;
		}
		return $attributes;
	}

	public function setFormRequest($formRequest)
	{
		if ($formRequest && method_exists($formRequest, 'rules'))
		{
			$this->formRequest = $formRequest;
		}
	}

	protected function addValidationAttributes($name, $type, $attributes = [])
	{
		static $rules;

		if ($this->formRequest && method_exists($this->formRequest, 'rules'))
		{
			if (is_null($rules))
				$rules = $this->formRequest->rules();

			if (isset($rules[$name]))
			{
				if (!is_array($rules[$name]))
					$rules[$name] = explode('|', $rules[$name]);

				foreach ($rules[$name] as $rule)
				{
					if ($rule == 'required')
					{
						$attributes[$rule] = $rule;
					}
					elseif (strpos($rule, 'regex:') === 0)
					{
						$attributes['pattern'] = str_replace('regex:', '', $rule);
					}
					elseif (in_array($rule, ['email', 'integer', 'url', 'date', 'time']))
					{
						if ($type != $rule)
							$attributes['pattern'] = $rule;
					}
				}
			}
		}
		return $attributes;
	}

	/**
	 * Build a list of HTML attributes from an array.
	 *
	 * @param  array   $attributes
	 * @return string
	 */
	public function attributes($attributes)
	{
		$html = [];

		foreach ((array) $attributes as $key => $value)
		{
			// For numeric keys, we will assume that the key and the value are the
			// same, as this will convert HTML attributes such as "required" that
			// may be specified as required="required", etc.
			if (is_numeric($key)) $key = $value;

			if ( ! is_null($value))
			{
				$html[] = $key.'="'.$this->entities($value).'"';
			}
		}

		return (count($html) > 0) ? ' '.implode(' ', $html) : '';
	}

	/**
	 * Convert HTML characters to entities.
	 *
	 * The encoding specified in the application configuration file will be used.
	 *
	 * @param  string  $value
	 * @return string
	 */
	public function entities($value)
	{
		return htmlentities($value, ENT_QUOTES, Config::get('formation.encoding'), false);
	}

	/**
	 * Create a field along with a label and error message (if one is set).
	 *
	 * @param  string  $name
	 * @param  mixed   $type
	 * @param  array   $attributes
	 * @return string
	 */
	public function field($name, $type = null, $attributes = [])
	{
		//set any field named "submit" to a "submit" field automatically and set it's type to attributes to
		//to simplify creation of "submit" fields with field() macro
		if ($name == "submit") {
			if (is_array($type)) {
				$name = null;
				$attributes = $type;
				$type = "submit";
			}

			$types = [
				'text',
				'search',
				'password',
				'url',
				'number',
				'date',
				'textarea',
				'hidden',
				'select',
				'checkbox',
				'radio',
				'checkbox-set',
				'radio-set',
				'file',
				'button',
				'submit',
				'email',
				'tel',
				'url',
			];

			if (!is_array($type) && !in_array($type, $types)) {
				$name = $type;
				$type = "submit";
				$attributes = [];
			}
		}

		//allow label to be set via attributes array (defaults to labels array and then to a label derived from the field's name)
		$fieldLabel = Config::get('formation.field.autoLabel');
		if (!is_null($name)) {
			$label = $this->nameToLabel($name);
		} else {
			$label = $name;
		}

		if (is_array($attributes) && array_key_exists('label', $attributes)) {
			$label = $attributes['label'];
			unset($attributes['label']);
			$fieldLabel = true;
		}
		if (is_null($label)) $fieldLabel = false;

		if (!is_array($attributes)) $attributes = [];

		//allow options for select, radio-set, and checkbox-set to be set via attributes array
		$options = [];
		if (isset($attributes['options'])) {
			$options = $attributes['options'];
			unset($attributes['options']);
		}

		///allow the null option ("Select a ...") for a select field to be set via attributes array
		$nullOption = null;
		if (isset($attributes['null-option'])) {
			$nullOption = $attributes['null-option'];
			unset($attributes['null-option']);
		}

		///allow the field's value to be set via attributes array
		$value = null;
		if (isset($attributes['value'])) {
			$value = $attributes['value'];
			unset($attributes['value']);
		}

		$error = null;
		if (isset($attributes['error'])) {
			$error = $attributes['error'];
			unset($attributes['error']);
		}

		$helptext = null;
		if (isset($attributes['helptext'])) {
			$helptext = $attributes['helptext'];
			unset($attributes['helptext']);
		}

		//set any field named "password" to a "password" field automatically; no type declaration required
		if (substr($name, 0, 8) == "password" && is_null($type)) $type = "password";

		//if type is still null, assume it to be a regular "text" field
		if (is_null($type)) $type = "text";

		//set attributes up for label and field (remove element-specific attributes from label and vice versa)
		$attributesLabel = [];
		foreach ($attributes as $key => $attribute) {
			if (substr($key, -6) == "-label") {
				$key = str_replace('-label', '', $key);
				$attributesLabel[$key] = $attribute;
			}
			if (($key == "id" || $key == "id-field") && !isset($attributes['for'])) {
				$attributesLabel['for'] = $attribute;
			}
		}

		$attributesField = [];
		foreach ($attributes as $key => $attribute) {
			if (substr($key, -6) != "-label" && substr($key, -16) != "-field-container") {
				$key = str_replace('-field', '', $key);
				$attributesField[$key] = $attribute;
			}
		}

		$html = $this->openFieldContainer($name, $type, $attributes);

		switch ($type) {
			case "text":
			case "email":
			case "tel":
			case "search":
			case "password":
			case "url":
			case "number":
			case "date":
				if ($fieldLabel) $html .= $this->label($name, $label, $attributesLabel);
				$html .= $this->helptext($helptext);
				$html .= $this->input($type, $name, $value, $attributesField) . "\n";
				break;
			case "textarea":
				if ($fieldLabel) $html .= $this->label($name, $label, $attributesLabel);
				$html .= $this->helptext($helptext);
				$html .= $this->textarea($name, $value, $attributesField);
				break;
			case "hidden":
				$html .= $this->hidden($name, $value, $attributesField);
				break;
			case "select":
				if ($fieldLabel) $html .= $this->label($name, $label, $attributesLabel);
				$html .= $this->helptext($helptext);
				$html .= $this->select($name, $options, $nullOption, $value, $attributesField);
				break;
			case "checkbox":
				if (is_null($value)) $value = 1;
				if (isset($attributesLabel['class'])) {
					$attributesLabel['class'] .= " checkbox";
				} else {
					$attributesLabel['class']  = "checkbox";
				}
				$html .= '<label>'.$this->checkbox($name, $value, false, $attributesField).' '.$label.'</label>';
				$html .= $this->helptext($helptext);
				break;
			case "radio":
				if (isset($attributesLabel['class'])) {
					$attributesLabel['class'] .= " radio";
				} else {
					$attributesLabel['class']  = "radio";
				}
				$html .= '<label>'.$this->radio($name, $value, false, $attributesField).' '.$label.'</label>';
				$html .= $this->helptext($helptext);
				break;
			case "checkbox-set":
				//for checkbox set, use options as array of checkbox names
				if ($fieldLabel) $html .= $this->label(null, $label, $attributesLabel);
				$html .= $this->helptext($helptext);
				$html .= $this->checkboxSet($options, $name, $attributesField);
				break;
			case "radio-set":
				if ($fieldLabel) $html .= $this->label(null, $label, $attributesLabel);
				$html .= $this->helptext($helptext);
				$html .= $this->radioSet($name, $options, null, $attributesField);
				break;
			case "file":
				if ($fieldLabel) $html .= $this->label($name, $label, $attributesLabel);
				$html .= $this->helptext($helptext);
				$html .= $this->file($name, $attributesField) . "\n";
				break;
			case "button":
				$html .= $this->button($label, $attributesField);
				break;
			case "submit":
				$html .= $this->submit($label, $attributesField);
				break;
		}

		$html .= $this->error($name, $type, $error, $attributesField);
		$html .= $this->closeFieldContainer();

		return $html;
	}

	/**
	 * Open a field container.
	 *
	 * @param  string  $name
	 * @param  mixed   $type
	 * @param  array   $attributes
	 * @return string
	 */
	public function openFieldContainer($name, $type = null, $attributes = [])
	{
		$attributesFieldContainer = [];
		foreach ($attributes as $key => $attribute) {
			if (substr($key, -16) == "-field-container") {
				$key = str_replace('-field-container', '', $key);
				$attributesFieldContainer[$key] = $attribute;
			}
		}
		if (!isset($attributesFieldContainer['class']) || $attributesFieldContainer['class'] == "") {
			$attributesFieldContainer['class'] = Config::get('formation.fieldContainer.class');
		} else {
			$attributesFieldContainer['class'] .= ' '.Config::get('formation.fieldContainer.class');
		}
		if (!isset($attributesFieldContainer['id'])) {
			$attributesFieldContainer['id'] = $this->id($name, $attributesFieldContainer).'-area';
		} else {
			if (is_null($attributesFieldContainer['id']) || !$attributesFieldContainer['id'])
				unset($attributesFieldContainer['id']);
		}

		if ($type == "checkbox") $attributesFieldContainer['class'] .= ' checkbox';
		if ($type == "radio")    $attributesFieldContainer['class'] .= ' radio';
		if ($type == "hidden")   $attributesFieldContainer['class'] .= ' hidden';

		$attributesFieldContainer = $this->addErrorClass($name, $attributesFieldContainer);

		return '<'.Config::get('formation.fieldContainer.element').$this->attributes($attributesFieldContainer).'>' . "\n";
	}

	/**
	 * Close a field container.
	 *
	 * @return string
	 */
	public function closeFieldContainer()
	{
		$html = "";

		if (Config::get('formation.fieldContainer.clear'))
			$html .= '<div class="clear"></div>' . "\n";

		$html .= '</'.Config::get('formation.fieldContainer.element').'>' . "\n";

		return $html;
	}

	/**
	 * Create an HTML input element.
	 *
	 * <code>
	 *		// Create a "text" input element named "email"
	 *		echo Form::input('text', 'email');
	 *
	 *		// Create an input element with a specified default value
	 *		echo Form::input('text', 'email', 'example@gmail.com');
	 * </code>
	 *
	 * @param  string  $type
	 * @param  string  $name
	 * @param  mixed   $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function input($type, $name, $value = null, $attributes = [])
	{
		//automatically set placeholder attribute if config option is set
		if (!in_array($type, ['hidden', 'checkbox', 'radio']))
			$attributes = $this->setFieldPlaceholder($name, $attributes);

		//add the field class if config option is set
		$attributes = $this->setFieldClass($name, $attributes, $type);

		//remove "placeholder" attribute if it is set to false
		if (isset($attributes['placeholder']) && !$attributes['placeholder'])
			unset($attributes['placeholder']);

		$name = (isset($attributes['name'])) ? $attributes['name'] : $name;
		//$attributes = $this->addErrorClass($name, $attributes);

		$attributes['id'] = $this->id($name, $attributes);

		if ($name == $this->spoofer || $name == "_token")
			unset($attributes['id']);

		if (is_null($value) && $type != "password") $value = $this->value($name);

		$name = $this->name($name);

		if ($type != "hidden")
		{
			$attributes = $this->addAccessKey($name, null, $attributes);
			$attributes = $this->addValidationAttributes($name, $type, $attributes);
		}

		$attributes = array_merge($attributes, compact('type', 'name', 'value'));

		return '<input'.$this->attributes($attributes).'>' . "\n";
	}

	/**
	 * Create an HTML text input element.
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function text($name, $value = null, $attributes = [])
	{
		return $this->input('text', $name, $value, $attributes);
	}

	/**
	 * Create an HTML password input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function password($name, $attributes = [])
	{
		return $this->input('password', $name, null, $attributes);
	}

	/**
	 * Create an HTML hidden input element.
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function hidden($name, $value = null, $attributes = [])
	{
		return $this->input('hidden', $name, $value, $attributes);
	}

	/**
	 * Create an HTML search input element.
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function search($name, $value = null, $attributes = [])
	{
		return $this->input('search', $name, $value, $attributes);
	}

	/**
	 * Create an HTML email input element.
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function email($name, $value = null, $attributes = [])
	{
		return $this->input('email', $name, $value, $attributes);
	}

	/**
	 * Create an HTML telephone input element.
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function telephone($name, $value = null, $attributes = [])
	{
		return $this->input('tel', $name, $value, $attributes);
	}

	/**
	 * Create an HTML URL input element.
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function url($name, $value = null, $attributes = [])
	{
		return $this->input('url', $name, $value, $attributes);
	}

	/**
	 * Create an HTML number input element.
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function number($name, $value = null, $attributes = [])
	{
		return $this->input('number', $name, $value, $attributes);
	}

	/**
	 * Create an HTML date input element.
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function date($name, $value = null, $attributes = [])
	{
		return $this->input('date', $name, $value, $attributes);
	}

	/**
	 * Create an HTML file input element.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function file($name, $attributes = [])
	{
		return $this->input('file', $name, null, $attributes);
	}

	/**
	 * Create an HTML textarea element.
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function textarea($name, $value = null, $attributes = [])
	{
		$attributes['name'] = $name;
		$attributes['id'] = $this->id($name, $attributes);

		//add the field class if config option is set
		$attributes = $this->setFieldClass($name, $attributes);

		//automatically set placeholder attribute if config option is set
		$attributes = $this->setFieldPlaceholder($name, $attributes);

		//$attributes = $this->addErrorClass($name, $attributes);

		if (is_null($value)) $value = $this->value($name);
		if (is_null($value)) $value = ''; //if value is still null, set it to an empty string

		$attributes['name'] = $this->name($attributes['name']);

		$attributes = $this->addAccessKey($name, null, $attributes);
		$attributes = $this->addValidationAttributes($name, 'text', $attributes);

		return '<textarea'.$this->attributes($attributes).'>'.$this->entities($value).'</textarea>' . "\n";
	}

	/**
	 * Create an HTML select element.
	 *
	 * <code>
	 *		// Create a HTML select element filled with options
	 *		echo Form::select('sizes', [('S' => 'Small', 'L' => 'Large']);
	 *
	 *		// Create a select element with a default selected value
	 *		echo Form::select('sizes', ['S' => 'Small', 'L' => 'Large'], 'Select a size', 'L');
	 * </code>
	 *
	 * @param  string  $name
	 * @param  array   $options
	 * @param  string  $nullOption
	 * @param  mixed   $selected
	 * @param  array   $attributes
	 * @return string
	 */
	public function select($name, $options = [], $nullOption = null, $selected = null, $attributes = [])
	{
		if (!isset($attributes['id'])) $attributes['id'] = $this->id($name, $attributes);
		$attributes['name'] = $name;
		//$attributes = $this->addErrorClass($name, $attributes);

		//add the field class if config option is set
		$attributes = $this->setFieldClass($name, $attributes);

		if (is_null($selected)) $selected = $this->value($name);

		$html = [];
		if (!is_null($nullOption)) {
			$html[] = $this->option('', $nullOption, $selected);

			$attributes['data-null-option'] = $nullOption;
		}

		foreach ($options as $value => $display) {
			$value = str_replace('[DUPLICATE]', '', $value); //allow the possibility of the same value appearing in the options array twice by appending "[DUPLICATE]" to its key

			if (is_array($display)) {
				$html[] = $this->optgroup($display, $value, $selected);
			} else {
				$html[] = $this->option($value, $display, $selected);
			}
		}

		$attributes['name'] = $this->name($attributes['name']);

		$attributes = $this->addAccessKey($name, null, $attributes);
		$attributes = $this->addValidationAttributes($name, 'select', $attributes);

		return '<select'.$this->attributes($attributes).'>'.implode("\n", $html). "\n" .'</select>' . "\n";
	}

	/**
	 * Create an HTML select element optgroup.
	 *
	 * @param  array   $options
	 * @param  string  $label
	 * @param  string  $selected
	 * @return string
	 */
	protected function optgroup($options, $label, $selected)
	{
		$html = [];

		foreach ($options as $value => $display) {
			$html[] = $this->option($value, $display, $selected);
		}

		return '<optgroup label="'.$this->entities($label).'">'.implode('', $html).'</optgroup>';
	}

	/**
	 * Create an HTML select element option.
	 *
	 * @param  string  $value
	 * @param  string  $display
	 * @param  string  $selected
	 * @return string
	 */
	protected function option($value, $display, $selected)
	{
		if (is_array($selected))
			$selected = (in_array($value, $selected)) ? 'selected' : null;
		else
			$selected = ((string) $value == (string) $selected) ? 'selected' : null;

		$attributes = [
			'value'    => $this->entities($value),
			'selected' => $selected,
		];

		return '<option'.$this->attributes($attributes).'>'.$this->entities($display).'</option>';
	}

	/**
	 * Create a set of select boxes for times.
	 *
	 * @param  string  $name
	 * @param  array   $options
	 * @param  string  $nullOption
	 * @param  string  $selected
	 * @param  array   $attributes
	 * @return string
	 */
	public function selectTime($namePrefix = 'time', $selected = null, $attributes = [])
	{
		$html = "";
		if ($namePrefix != "" && substr($namePrefix, -1) != "_") $namePrefix .= "_";

		//create hour field
		$hoursOptions = [];
		for ($h=0; $h <= 12; $h++) {
			$hour = sprintf('%02d', $h);
			if ($hour == 12) {
				$hoursOptions[$hour.'[DUPLICATE]'] = $hour;
			} else {
				if ($h == 0) $hour = 12;
				$hoursOptions[$hour] = $hour;
			}
		}
		$attributesHour = $attributes;
		if (isset($attributesHour['class'])) {
			$attributesHour['class'] .= " time time-hour";
		} else {
			$attributesHour['class'] = "time time-hour";
		}
		$html .= $this->select($namePrefix.'hour', $hoursOptions, null, null, $attributesHour);

		$html .= '<span class="time-hour-minutes-separator">:</span>' . "\n";

		//create minutes field
		$minutesOptions = [];
		for ($m=0; $m < 60; $m++) {
			$minute = sprintf('%02d', $m);
			$minutesOptions[$minute] = $minute;
		}
		$attributesMinutes = $attributes;
		if (isset($attributesMinutes['class'])) {
			$attributesMinutes['class'] .= " time time-minutes";
		} else {
			$attributesMinutes['class'] = "time time-minutes";
		}
		$html .= $this->select($namePrefix.'minutes', $minutesOptions, null, null, $attributesMinutes);

		//create meridiem field
		$meridiemOptions = $this->simpleOptions(['am', 'pm']);
		$attributesMeridiem = $attributes;
		if (isset($attributesMeridiem['class'])) {
			$attributesMeridiem['class'] .= " time time-meridiem";
		} else {
			$attributesMeridiem['class'] = "time time-meridiem";
		}
		$html .= $this->select($namePrefix.'meridiem', $meridiemOptions, null, null, $attributesMeridiem);

		return $html;
	}

	/**
	 * Create a set of HTML checkboxes.
	 *
	 * @param  array   $names
	 * @param  string  $namePrefix
	 * @param  array   $attributes
	 * @return string
	 */
	public function checkboxSet($names = [], $namePrefix = null, $attributes = [])
	{
		if (!empty($names) && (is_object($names) || is_array($names)))
		{
			if (is_object($names))
				$names = (array) $names;

			$containerAttributes = ['class' => 'checkbox-set'];

			foreach ($attributes as $attribute => $value)
			{
				//appending "-container" to attributes means they apply to the
				//"checkbox-set" container rather than to the checkboxes themselves
				if (substr($attribute, -10) == "-container")
				{
					if (str_replace('-container', '', $attribute) == "class")
						$containerAttributes['class'] .= ' '.$value;
					else
						$containerAttributes[str_replace('-container', '', $attribute)] = $value;

					unset($attributes[$attribute]);
				}
			}

			$containerAttributes = $this->addErrorClass('roles', $containerAttributes);
			$html = '<div'.$this->attributes($containerAttributes).'>';

			foreach ($names as $name => $display) {
				//if a simple array is used, automatically create the label from the name
				$associativeArray = true;
				if (isset($attributes['associative']))
				{
					if (!$attributes['associative'])
						$associativeArray = false;
				} else {
					if (is_numeric($name))
						$associativeArray = false;
				}

				if (!$associativeArray) {
					$name    = $display;
					$display = $this->nameToLabel($name);
				}

				if (isset($attributes['name-values']) && $attributes['name-values'])
					$value = $name;
				else
					$value = 1;

				$nameToCheck = $name;
				if (!is_null($namePrefix)) {
					if (substr($namePrefix, -1) == ".") {
						$name = $namePrefix . $name;
					} else {
						$nameToCheck = $namePrefix;
						$name = $namePrefix . '.('.$name.')';
					}
				}

				$valueToCheck = $this->value($nameToCheck);
				$checked      = false;

				if (is_array($valueToCheck) && in_array($value, $valueToCheck)) {
					$checked = true;
				} else if (is_bool($value) && $value == $this->value($nameToCheck, 'checkbox')) {
					$checked = true;
				} else if (is_string($value) && $value == $valueToCheck) {
					$checked = true;
				}

				//add selected class to list item if checkbox is checked to allow styling for selected checkboxes in set
				$subContainerAttributes = ['class' => 'checkbox'];
				if ($checked)
					$subContainerAttributes['class'] .= ' selected';

				$checkbox = '<div'.$this->attributes($subContainerAttributes).'>' . "\n";

				$checkboxAttributes       = $attributes;
				$checkboxAttributes['id'] = $this->id($name);

				if (isset($checkboxAttributes['associative'])) unset($checkboxAttributes['associative']);
				if (isset($checkboxAttributes['name-values'])) unset($checkboxAttributes['name-values']);

				$checkbox .= $this->checkbox($name, $value, $checked, $checkboxAttributes);
				$checkbox .= $this->label($name, $display, ['accesskey' => false]);
				$checkbox .= '</div>' . "\n";
				$html     .= $checkbox;
			}

			$html .= '</div>' . "\n";
			return $html;
		}
	}

	/**
	 * Create an HTML checkbox input element.
	 *
	 * <code>
	 *		// Create a checkbox element
	 *		echo Form::checkbox('terms', 'yes');
	 *
	 *		// Create a checkbox that is selected by default
	 *		echo Form::checkbox('terms', 'yes', true);
	 * </code>
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  bool    $checked
	 * @param  array   $attributes
	 * @return string
	 */
	public function checkbox($name, $value = 1, $checked = false, $attributes = [])
	{
		if ($value == $this->value($name))
			$checked = true;

		if (!isset($attributes['id']))
			$attributes['id'] = $this->id($name, $attributes);

		return $this->checkable('checkbox', $name, $value, $checked, $attributes);
	}

	/**
	 * Create a set of HTML radio buttons.
	 *
	 * @param  string  $name
	 * @param  array   $options
	 * @param  string  $selected
	 * @param  array   $attributes
	 * @return string
	 */
	public function radioSet($name, $options = [], $selected = null, $attributes = [])
	{
		if (!empty($options) && (is_object($options) || is_array($options)))
		{
			if (is_object($options))
				$options = (array) $options;

			$containerAttributes = ['class' => 'radio-set'];

			foreach ($attributes as $attribute => $value)
			{
				//appending "-container" to attributes means they apply to the
				//"radio-set" container rather than to the checkboxes themselves
				if (substr($attribute, -10) == "-container")
				{
					if (str_replace('-container', '', $attribute) == "class")
						$containerAttributes['class'] .= ' '.$value;
					else
						$containerAttributes[str_replace('-container', '', $attribute)] = $value;

					unset($attributes[$attribute]);
				}
			}

			$containerAttributes = $this->addErrorClass($name, $containerAttributes);
			$html                = '<div'.$this->attributes($containerAttributes).'>';

			$label    = $this->label($name); //set dummy label so ID can be created in line below
			$idPrefix = $this->id($name, $attributes);

			if (is_null($selected))
				$selected = $this->value($name);

			foreach ($options as $value => $display)
			{
				if ($selected === (string) $value)
					$checked = true;
				else
					$checked = false;

				//add selected class to list item if radio button is set to allow styling for selected radio buttons in set
				$subContainerAttributes = ['class' => 'radio'];
				if ($checked)
					$subContainerAttributes['class'] .= ' selected';

				$radioButton = '<div'.$this->attributes($subContainerAttributes).'>' . "\n";

				//append radio button value to the end of ID to prevent all radio buttons from having the same ID
				$idSuffix = str_replace('.', '-', str_replace(' ', '-', str_replace('_', '-', strtolower($value))));
				if ($idSuffix == "")
					$idSuffix = "blank";

				$attributes['id'] = $idPrefix.'-'.$idSuffix;

				if (is_array($display))
				{
					$displayHtml = array_shift($display);
					$displayHtml .= $this->helptext(array_shift($display), true);
				}
				else
					$displayHtml = $display;

				$radioButton .= '<label>'.$this->radio($name, $value, $checked, $attributes).' '.$displayHtml.'</label></div>' . "\n";
				$html        .= $radioButton;
			}

			$html .= '</div>' . "\n";
			return $html;
		}
	}

	/**
	 * Create an HTML radio button input element.
	 *
	 * <code>
	 *		// Create a radio button element
	 *		echo Form::radio('drinks', 'Milk');
	 *
	 *		// Create a radio button that is selected by default
	 *		echo Form::radio('drinks', 'Milk', true);
	 * </code>
	 *
	 * @param  string  $name
	 * @param  string  $value
	 * @param  bool    $checked
	 * @param  array   $attributes
	 * @return string
	 */
	public function radio($name, $value = null, $checked = false, $attributes = [])
	{
		if (is_null($value))
			$value = $name;

		if ((string) $value === $this->value($name))
			$checked = true;

		if (!isset($attributes['id']))
			$attributes['id'] = $this->id($name.'-'.strtolower($value), $attributes);

		return $this->checkable('radio', $name, $value, $checked, $attributes);
	}

	/**
	 * Create a checkable (checkbox or radio button) input element.
	 *
	 * @param  string  $type
	 * @param  string  $name
	 * @param  string  $value
	 * @param  bool    $checked
	 * @param  array   $attributes
	 * @return string
	 */
	protected function checkable($type, $name, $value, $checked, $attributes)
	{
		if ($checked)
			$attributes['checked'] = 'checked';

		return $this->input($type, $name, $value, $attributes);
	}

	/**
	 * Prepare an options array from a database object or other complex
	 * object/array for a select field, checkbox set, or radio button set.
	 *
	 * @param  array   $options
	 * @param  array   $vars
	 * @return array
	 */
	public function prepOptions($options = [], $vars = [])
	{
		$optionsFormatted = [];

		//turn Eloquent instances into an array
		$optionsArray = $options;
		if (isset($optionsArray[0]) && isset($optionsArray[0]->incrementing) && isset($optionsArray[0]->timestamps))
			$optionsArray = $options->toArray();

		if (is_string($vars) || (is_array($vars) && count($vars) > 0)) {
			foreach ($optionsArray as $key => $option) {

				//turn object into array
				$optionArray = $option;
				if (is_object($option))
					$optionArray = (array) $option;

				//set label and value according to specified variables
				if (is_string($vars)) {
					$label = $vars;
					$value = $vars;
				} else if (is_array($vars) && count($vars) == 1) {
					$label = $vars[0];
					$value = $vars[0];
				} else {
					$label = $vars[0];
					$value = $vars[1];
				}

				//check whether the value is a method
				preg_match('/\(\)/', $value, $functionMatch);
				if (isset($optionValue)) unset($optionValue);
				if (!empty($functionMatch)) { //value is a method of object; call it
					$function = str_replace('()', '', $value);
					$optionValue = $options[$key]->function();
				} else if (isset($optionArray[$value])) {
					$optionValue = $optionArray[$value];
				}

				//if a label and a value are set, add it to options array
				if (isset($optionArray[$label]) && isset($optionValue)) {
					$optionsFormatted[$optionArray[$label]] = $optionValue;
				}
			}
		}
		return $optionsFormatted;
	}

	/**
	 * Create an associative array from a simple array for a select field, checkbox set, or radio button set.
	 *
	 * @param  array   $options
	 * @return array
	 */
	public function simpleOptions($options = [])
	{
		$optionsFormatted = [];
		foreach ($options as $option) {
			$optionsFormatted[$option] = $option;
		}

		return $optionsFormatted;
	}

	/**
	 * Offset a simple array by 1 index to prevent any options from having an
	 * index (value) of 0 for a select field, checkbox set, or radio button set.
	 *
	 * @param  array   $options
	 * @return array
	 */
	public function offsetOptions($options = [])
	{
		$optionsFormatted = [];
		for ($o=0; $o < count($options); $o++) {
			$optionsFormatted[($o + 1)] = $options[$o];
		}

		return $optionsFormatted;
	}

	/**
	 * Create an options array of numbers within a specified range
	 * for a select field, checkbox set, or radio button set.
	 *
	 * @param  integer $start
	 * @param  integer $end
	 * @param  integer $increment
	 * @param  integer $decimals
	 * @return array
	 */
	public function numberOptions($start = 1, $end = 10, $increment = 1, $decimals = 0)
	{
		$options = [];
		if (is_numeric($start) && is_numeric($end)) {
			if ($start <= $end) {
				for ($o = $start; $o <= $end; $o += $increment) {
					if ($decimals) {
						$value = number_format($o, $decimals, '.', '');
					} else {
						$value = $o;
					}
					$options[$value] = $value;
				}
			} else {
				for ($o = $start; $o >= $end; $o -= $increment) {
					if ($decimals) {
						$value = number_format($o, $decimals, '.', '');
					} else {
						$value = $o;
					}
					$options[$value] = $value;
				}
			}
		}
		return $options;
	}

	/**
	 * Get an options array of countries.
	 *
	 * @return array
	 */
	public function countryOptions()
	{
		return $this->simpleOptions([
			'Canada', 'United States', 'Afghanistan', 'Albania', 'Algeria', 'American Samoa', 'Andorra', 'Angola', 'Anguilla', 'Antarctica', 'Antigua And Barbuda', 'Argentina', 'Armenia', 'Aruba',
			'Australia', 'Austria', 'Azerbaijan', 'Bahamas', 'Bahrain', 'Bangladesh', 'Barbados', 'Belarus', 'Belgium', 'Belize', 'Benin', 'Bermuda', 'Bhutan', 'Bolivia', 'Bosnia And Herzegowina',
		 	'Botswana', 'Bouvet Island', 'Brazil', 'British Indian Ocean Territory', 'Brunei Darussalam', 'Bulgaria', 'Burkina Faso', 'Burundi', 'Cambodia', 'Cameroon', 'Cape Verde', 'Cayman Islands',
		 	'Central African Republic', 'Chad', 'Chile', 'China', 'Christmas Island', 'Cocos (Keeling) Islands', 'Colombia', 'Comoros', 'Congo', 'Congo, The Democratic Republic Of The', 'Cook Islands',
		 	'Costa Rica', 'Cote D\'Ivoire', 'Croatia (Local Name: Hrvatska)', 'Cuba', 'Cyprus', 'Czech Republic', 'Denmark', 'Djibouti', 'Dominica', 'Dominican Republic', 'East Timor', 'Ecuador','Egypt',
			'El Salvador', 'Equatorial Guinea', 'Eritrea', 'Estonia', 'Ethiopia', 'Falkland Islands (Malvinas)', 'Faroe Islands', 'Fiji', 'Finland', 'France', 'France, Metropolitan', 'French Guiana',
		 	'French Polynesia', 'French Southern Territories', 'Gabon', 'Gambia', 'Georgia', 'Germany', 'Ghana', 'Gibraltar', 'Greece', 'Greenland', 'Grenada', 'Guadeloupe', 'Guam', 'Guatemala','Guinea',
		 	'Guinea-Bissau', 'Guyana', 'Haiti', 'Heard And Mc Donald Islands', 'Holy See (Vatican City State)', 'Honduras', 'Hong Kong', 'Hungary', 'Iceland', 'India', 'Indonesia', 'Iran', 'Iraq', 'Ireland',
		 	'Israel', 'Italy', 'Jamaica', 'Japan', 'Jordan', 'Kazakhstan', 'Kenya', 'Kiribati', 'Korea, Democratic People\'S Republic Of', 'Korea, Republic Of', 'Kuwait', 'Kyrgyzstan',
		 	'Lao People\'S Democratic Republic', 'Latvia', 'Lebanon', 'Lesotho', 'Liberia', 'Libyan Arab Jamahiriya', 'Liechtenstein', 'Lithuania', 'Luxembourg', 'Macau',
		 	'Macedonia, Former Yugoslav Republic Of', 'Madagascar', 'Malawi', 'Malaysia', 'Maldives', 'Mali', 'Malta', 'Marshall Islands', 'Martinique', 'Mauritania', 'Mauritius', 'Mayotte', 'Mexico',
		 	'Micronesia, Federated States Of', 'Moldova, Republic Of', 'Monaco', 'Mongolia', 'Montserrat', 'Morocco', 'Mozambique', 'Myanmar', 'Namibia', 'Nauru', 'Nepal', 'Netherlands',
		 	'Netherlands Antilles', 'New Caledonia', 'New Zealand', 'Nicaragua', 'Niger', 'Nigeria', 'Niue', 'Norfolk Island', 'Northern Mariana Islands', 'Norway', 'Oman', 'Pakistan', 'Palau', 'Panama',
		 	'Papua New Guinea', 'Paraguay', 'Peru','Philippines', 'Pitcairn', 'Poland', 'Portugal', 'Puerto Rico', 'Qatar', 'Reunion', 'Romania', 'Russian Federation', 'Rwanda', 'Saint Kitts And Nevis',
		 	'Saint Lucia','Saint Vincent And The Grenadines', 'Samoa', 'San Marino', 'Sao Tome And Principe', 'Saudi Arabia', 'Senegal', 'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia (Slovak Republic)',
		 	'Slovenia', 'Solomon Islands', 'Somalia', 'South Africa', 'South Georgia, South Sandwich Islands', 'Spain', 'Sri Lanka', 'St. Helena', 'St. Pierre And Miquelon', 'Sudan', 'Suriname',
		 	'Svalbard And Jan Mayen Islands', 'Swaziland', 'Sweden', 'Switzerland', 'Syrian Arab Republic', 'Taiwan', 'Tajikistan', 'Tanzania, United Republic Of', 'Thailand', 'Togo', 'Tokelau', 'Tonga',
		 	'Trinidad And Tobago', 'Tunisia', 'Turkey', 'Turkmenistan', 'Turks And Caicos Islands', 'Tuvalu', 'Uganda', 'Ukraine', 'United Arab Emirates', 'United Kingdom',
		 	'United States Minor Outlying Islands', 'Uruguay', 'Uzbekistan', 'Vanuatu', 'Venezuela', 'Viet Nam', 'Virgin Islands (British)', 'Virgin Islands (U.S.)', 'Wallis And Futuna Islands',
		 	'Western Sahara', 'Yemen', 'Yugoslavia', 'Zambia', 'Zimbabwe',
		]);
	}

	/**
	 * Get an options array of Canadian provinces.
	 *
	 * @param  bool    $useAbbrev
	 * @return array
	 */
	public function provinceOptions($useAbbrev = true)
	{
		$provinces = [
			'AB' => 'Alberta',
			'BC' => 'British Columbia',
			'MB' => 'Manitoba',
			'NB' => 'New Brunswick',
			'NL' => 'Newfoundland',
			'NT' => 'Northwest Territories',
			'NS' => 'Nova Scotia',
			'NU' => 'Nunavut',
			'ON' => 'Ontario',
			'PE' => 'Prince Edward Island',
			'QC' => 'Quebec',
			'SK' => 'Saskatchewan',
			'YT' => 'Yukon Territory',
		];

		if ($useAbbrev)
			return $provinces;
		else
			return $this->simpleOptions(array_values($provinces)); //remove abbreviation keys
	}

	/**
	 * Get an options array of US states.
	 *
	 * @param  bool    $useAbbrev
	 * @return array
	 */
	public function stateOptions($useAbbrev = true)
	{
		$states = [
			'AL' => 'Alabama',
			'AK' => 'Alaska',
			'AZ' => 'Arizona',
			'AR' => 'Arkansas',
			'CA' => 'California',
			'CO' => 'Colorado',
			'CT' => 'Connecticut',
			'DE' => 'Delaware',
			'DC' => 'District of Columbia',
			'FL' => 'Florida',
			'GA' => 'Georgia',
			'HI' => 'Hawaii',
			'ID' => 'Idaho',
			'IL' => 'Illinois',
			'IN' => 'Indiana',
			'IA' => 'Iowa',
			'KS' => 'Kansas',
			'KY' => 'Kentucky',
			'LA' => 'Louisiana',
			'ME' => 'Maine',
			'MD' => 'Maryland',
			'MA' => 'Massachusetts',
			'MI' => 'Michigan',
			'MN' => 'Minnesota',
			'MS' => 'Mississippi',
			'MO' => 'Missouri',
			'MT' => 'Montana',
			'NE' => 'Nebraska',
			'NV' => 'Nevada',
			'NH' => 'New Hampshire',
			'NJ' => 'New Jersey',
			'NM' => 'New Mexico',
			'NY' => 'New York',
			'NC' => 'North Carolina',
			'ND' => 'North Dakota',
			'OH' => 'Ohio',
			'OK' => 'Oklahoma',
			'OR' => 'Oregon',
			'PA' => 'Pennsylvania',
			'PR' => 'Puerto Rico',
			'RI' => 'Rhode Island',
			'SC' => 'South Carolina',
			'SD' => 'South Dakota',
			'TN' => 'Tennessee',
			'TX' => 'Texas',
			'UT' => 'Utah',
			'VT' => 'Vermont',
			'VA' => 'Virginia',
			'VI' => 'Virgin Islands',
			'WA' => 'Washington',
			'WV' => 'West Virginia',
			'WI' => 'Wisconsin',
			'WY' => 'Wyoming',
		];

		if ($useAbbrev)
			return $states;
		else
			return $this->simpleOptions(array_values($states)); //remove abbreviation keys
	}

	/**
	 * Get an options array of times.
	 *
	 * @param  string  $minutes
	 * @param  bool    $useAbbrev
	 * @return array
	 */
	public function timeOptions($minutes = 'half')
	{
		$times = [];
		$minutesOptions = ['00'];
		switch ($minutes) {
			case "full":
				$minutesOptions = ['00']; break;
			case "half":
				$minutesOptions = ['00', '30']; break;
			case "quarter":
				$minutesOptions = ['00', '15', '30', '45']; break;
			case "all":
				$minutesOptions = [];
				for ($m=0; $m < 60; $m++) {
					$minutesOptions[] = sprintf('%02d', $m);
				}
				break;
		}

		for ($h=0; $h < 24; $h++) {
			$hour = sprintf('%02d', $h);
			if ($h < 12) { $meridiem = "am"; } else { $meridiem = "pm"; }
			if ($h == 0) $hour = 12;
			if ($h > 12) {
				$hour = sprintf('%02d', ($hour - 12));
			}
			foreach ($minutesOptions as $minutes) {
				$times[sprintf('%02d', $h).':'.$minutes.':00'] = $hour.':'.$minutes.$meridiem;
			}
		}
		return $times;
	}

	/**
	 * Create an options array of months. You may use an integer to go a number of months back from your start month
	 * or you may use a date to go back or forward to a specific date. If the end month is later than the start month,
	 * the select options will go from earliest to latest. If the end month is earlier than the start month, the select
	 * options will go from latest to earliest. If an integer is used as the end month, use a negative number to go back
	 * from the start month. Setting $endDate to true will use the last day of the month instead of the first day.
	 *
	 * @param  mixed   $start
	 * @param  mixed   $end
	 * @param  boolean $endDate
	 * @param  string  $format
	 * @return array
	 */
	public function monthOptions($start = 'current', $end = -12, $endDate = false, $format = 'F Y')
	{
		//prepare start & end months
		if ($start == "current" || is_null($start) || !is_string($start)) $start = date('Y-m-01');
		if (is_int($end)) {
			$startMid = date('Y-m-15', strtotime($start)); //get mid-day of month to prevent long months or short months from producing incorrect month values
			if ($end > 0) {
				$ascending = true;
				$end       = date('Y-m-01', strtotime($startMid.' +'.$end.' months'));
			} else {
				$ascending = false;
				$end       = date('Y-m-01', strtotime($startMid.' -'.abs($end).' months'));
			}
		} else {
			if ($end == "current") $end = date('Y-m-01');
			if (strtotime($end) > strtotime($start)) {
				$ascending = true;
			} else {
				$ascending = false;
			}
		}

		//create list of months
		$options = [];
		$month   = $start;
		if ($ascending) {
			while (strtotime($month) <= strtotime($end)) {
				$monthMid = date('Y-m-15', strtotime($month));
				if ($endDate) {
					$date = $this->lastDayOfMonth($month);
				} else {
					$date = $month;
				}

				$options[$date] = date($format, strtotime($date));
				$month = date('Y-m-01', strtotime($monthMid.' +1 month'));
			}
		} else {
			while (strtotime($month) >= strtotime($end)) {
				$monthMid = date('Y-m-15', strtotime($month));
				if ($endDate) {
					$date = $this->lastDayOfMonth($month);
				} else {
					$date = $month;
				}

				$options[$date] = date($format, strtotime($date));
				$month = date('Y-m-01', strtotime($monthMid.' -1 month'));
			}
		}
		return $options;
	}

	/**
	 * Get the last day of the month. You can use the second argument to format the date (example: "F j, Y").
	 *
	 * @param  string  $date
	 * @param  mixed   $format
	 * @return string
	 */
	private function lastDayOfMonth($date = 'current', $format = false)
	{
		if ($date == "current") {
			$date = date('Y-m-d');
		} else {
			$date = date('Y-m-d', strtotime($date));
			$originalMonth = substr($date, 5, 2);
		}

		$year   = substr($date, 0, 4);
		$month  = substr($date, 5, 2);
		$day    = substr($date, 8, 2);
		$result = "";

		//prevent invalid dates having wrong month assigned (June 31 = July, etc...)
		if (isset($originalMonth) && $month != $originalMonth)
			$month = $originalMonth;

		if (in_array($month, ['01', '03', '05', '07', '08', '10', '12'])) {
			$lastDay = 31;
		} else if (in_array($month, ['04', '06', '09', '11'])) {
			$lastDay = 30;
		} else if ($month == "02") {
			if (($year/4) == round($year/4)) {
				if (($year/100) == round($year/100))
				{
					if (($year/400) == round($year/400))
						$lastDay = 29;
					else
						$lastDay = 28;
				} else {
					$lastDay = 29;
				}
			} else {
				$lastDay = 28;
			}
		}

		$result = $year.'-'.$month.'-'.$lastDay;

		if ($format)
			$result = $this->date($result, $format);

		return $result;
	}

	/**
	 * Create a set of boolean options (Yes/No, On/Off, Up/Down...)
	 * You may pass a string like "Yes/No" or an array with just two options.
	 *
	 * @param  mixed   $options
	 * @param  boolean $startWithOne
	 * @return array
	 */
	public function booleanOptions($options = ['Yes', 'No'], $startWithOne = true)
	{
		if (is_string($options)) $options = explode('/', $options); //allow options to be set as a string like "Yes/No"
		if (!isset($options[1])) $options[1] = "";

		if ($startWithOne) {
			return [
				1 => $options[0],
				0 => $options[1],
			];
		} else {
			return [
				0 => $options[0],
				1 => $options[1],
			];
		}
	}

	/**
	 * Get all error messages.
	 *
	 * @return array
	 */
	public function getErrors()
	{
		if (empty($this->errors)) {
			foreach ($this->validationFields as $fieldName) {
				$error = $this->errorMessage($fieldName);
				if ($error)
					$this->errors[$fieldName] = $error;
			}
		}

		return $this->errors;
	}

	/**
	 * Get all error messages.
	 *
	 * @return array
	 */
	public function getError($key)
	{
		if (empty($this->errors)) {
			foreach ($this->validationFields as $fieldName) {
				$error = $this->errorMessage($fieldName);
				if ($error)
					$this->errors[$fieldName] = $error;
			}
		}

		return $this->errors;
	}

	/**
	 * Set error messages from session data.
	 *
	 * @param  string  $errors
	 * @return array
	 */
	public function setErrors($session = 'errors')
	{
		$errors = Session::get($session);

		if (is_a($errors, 'Illuminate\Support\ViewErrorBag'))
		{
			if ($errors && $errors->count())
			{
				$bag = $errors->getBag('default');
				foreach ($bag->keys() as $key)
				{
					$this->errors[$key] = $bag->first($key);
				}
			}
		} else {
			$this->errors = $errors;
		}
		return $this->errors;
	}

	/**
	 * Reset error messages.
	 *
	 * @param  string  $errors
	 * @return array
	 */
	public function resetErrors($session = 'errors')
	{
		if ($session)
			Session::forget($session);

		$this->errors = [];
	}

	/**
	 * Add an error class to an HTML attributes array if a validation error exists for the specified form field.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return array
	 */
	public function addErrorClass($name, $attributes = [])
	{
		if ($this->errorMessage($name)) { //an error exists; add the error class
			if (!isset($attributes['class']))
				$attributes['class'] = $this->getErrorClass();
			else
				$attributes['class'] .= " ".$this->getErrorClass();
		}
		return $attributes;
	}

	/**
	 * Add an error class to an HTML attributes array if a validation error exists for the specified form field.
	 *
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return array
	 */
	public function getErrorClass()
	{
		return Config::get('formation.error.class');
	}

	protected function getDefaultError($name, $type, $attributes)
	{
		if ($this->defaultErrors)
		{
			$attributes = $this->addValidationAttributes($name, $type, $attributes);
			$required = isset($attributes["required"]);
			$type = str_replace('-set', '', $type);

			$search[] = "$name.$type";
			if ($required) $search[] = "$name.required";
			$search[] = $name;
			if ($required) $search[] = "$type.required";
			$search[] = $type;
			if ($required) $search[] = "required";

			foreach ($search as $error)
			{
				if (isset($this->defaultErrors[$error]))
					return $this->defaultErrors[$error];
			}
		}
		return '';
	}

	public function error($name, $type = 'text', $error = '', $attributesField = array())
	{
		$htmlError = '';
		if (Config::get('formation.fieldContainer.error') && !Config::get('formation.error.typeLabelTooltip'))
			$htmlError = $this->getErrorTag($name) . "\n";
		if (!trim($htmlError))
		{
			if (!$error)
				$error = $this->getDefaultError($name, $type, $attributesField);
			$htmlError = $this->getErrorTag($name, true, false, $error) . "\n";
		}

		return $htmlError;
	}

	/**
	 * Create error div for validation error if it exists for specified form field.
	 *
	 * @param  string  $name
	 * @param  boolean $alwaysExists
	 * @param  mixed   $replacementFieldName
	 * @param  mixed   $customMessage
	 * @return string
	 */
	protected function getErrorTag($name, $alwaysExists = false, $replacementFieldName = false, $customMessage = null)
	{
		if (substr($name, -1) == ".") $name = substr($name, 0, (strlen($name) - 1));

		if ($alwaysExists)
			$attr = ' id="'.$this->id($name).'-error"';
		else
			$attr = "";

		$message = $this->errorMessage($name, $replacementFieldName);

		if (!is_null($customMessage))
			$message = $customMessage;

		$errorElement = Config::get('formation.error.element');

		if ($message && $message != "") {
			return '<'.$errorElement.' class="error"'.$attr.'>'.$message.'</'.$errorElement.'>';
		} else {
			if ($alwaysExists)
				return '<'.$errorElement.' class="error"'.$attr.' style="display: none;"></'.$errorElement.'>';
		}
	}

	/**
	 * Get validation error message if it exists for specified form field. Modified to work with array fields.
	 *
	 * @param  string  $name
	 * @param  mixed   $replacementFieldName
	 * @param  boolean $ignoreIcon
	 * @return string
	 */
	public function errorMessage($name, $replacementFieldName = false, $ignoreIcon = false)
	{
		$errorMessage = false;

		//replace field name in error message with label if it exists
		$name = str_replace('(', '', str_replace(')', '', $name));
		$nameFormatted = $name;

		$specialReplacementNames = ['LOWERCASE', 'UPPERCASE', 'UPPERCASE-WORDS'];

		if ($replacementFieldName && is_string($replacementFieldName) && $replacementFieldName != ""
		&& !in_array($replacementFieldName, $specialReplacementNames)) {
			$nameFormatted = $replacementFieldName;
		} else {
			if (isset($this->labels[$name]) && $this->labels[$name] != "")
				$nameFormatted = $this->labels[$name];
			else
				$nameFormatted = $this->nameToLabel($nameFormatted);

			if (substr($nameFormatted, -1) == ":")
				$nameFormatted = substr($nameFormatted, 0, (strlen($nameFormatted) - 1));

			$nameFormatted = $this->formatReplacementName($nameFormatted, $replacementFieldName);
		}

		if ($nameFormatted == strip_tags($nameFormatted))
			$nameFormatted = $this->entities($nameFormatted);

		//return error message if it already exists
		if (isset($this->errors[$name]))
			$errorMessage = str_replace($this->nameToLabel($name), $nameFormatted, $this->errors[$name]);

		//cycle through all validation instances to allow the ability to get error messages in root fields
		//as well as field arrays like "field[array]" (passed to errorMessage in the form of "field.array")
		foreach ($this->validation as $fieldName => $validation) {
			$valid = $validation->passes();

			if ($validation->messages()) {
				$messages = $validation->messages();
				$nameArray = explode('.', $name);
				if (count($nameArray) < 2) {
					if ($_POST && $fieldName == "root" && $messages->first($name) != "") {
						$this->errors[$name] = str_replace(str_replace('_', ' ', $name), $nameFormatted, $messages->first($name));
						$errorMessage = $this->errors[$name];
					}
				} else {
					$last =	$nameArray[(count($nameArray) - 1)];
					$first = str_replace('.'.$nameArray[(count($nameArray) - 1)], '', $name);

					if ($replacementFieldName && is_string($replacementFieldName) && $replacementFieldName != ""
					&& !in_array($replacementFieldName, $specialReplacementNames)) {
						$nameFormatted = $replacementFieldName;
					} else {
						if ($nameFormatted == $name)
							$nameFormatted = $this->entities(ucwords($last));

						if (substr($nameFormatted, -1) == ":")
							$nameFormatted = substr($nameFormatted, 0, (strlen($nameFormatted) - 2));

						$nameFormatted = $this->formatReplacementName($nameFormatted, $replacementFieldName);
					}

					if ($_POST && $fieldName == $first && $messages->first($last) != "") {
						$this->errors[$name] = str_replace(str_replace('_', ' ', $last), $nameFormatted, $messages->first($last));
						$errorMessage = $this->errors[$name];
					}
				}
			}
		}

		if ($errorMessage && !$ignoreIcon) {
			$errorIcon = Config::get('formation.error.icon');
			if ($errorIcon) {
				if (!preg_match("/glyphicon/", $errorMessage))
					$errorMessage = '<span class="glyphicon glyphicon-'.$errorIcon.'"></span>&nbsp; '.$errorMessage;
			}
		}

		return $errorMessage;
	}

	/**
	 * Format replacement name for error messages.
	 *
	 * @param  string  $name
	 * @param  mixed   $replacementName
	 * @return string
	 */
	private function formatReplacementName($name, $replacementName) {
		if ($replacementName == "LOWERCASE")
			$name = strtolower($name);

		if ($replacementName == "UPPERCASE")
			$name = strtoupper($name);

		if ($replacementName == "UPPERCASE-WORDS")
			$name = ucwords(strtolower($name));

		return $name;
	}

	/**
	 * Get JSON encoded errors for formation.js.
	 *
	 * @param  string  $errors
	 * @return string
	 */
	public function getJsonErrors($session = 'errors')
	{
		return str_replace('\\"', '\\\"', json_encode($this->setErrors($session)));
	}

	/**
	 * Get JSON encoded errors for formation.js.
	 *
	 * @param  string  $errors
	 * @return string
	 */
	public function getJsonErrorSettings($session = 'errors')
	{
		$errorSettings = $this->formatSettingsForJs(Config::get('formation.error'));
		return json_encode($errorSettings);
	}

	/**
	 * Format settings array for Javascript.
	 *
	 * @param  array   $settings
	 * @return array
	 */
	private function formatSettingsForJs($settings) {
		if (is_array($settings)) {
			foreach ($settings as $setting => $value) {
				$settingOriginal = $setting;

				if ($setting == "class")
					$setting = "classAttribute";

				$setting = $this->dashedToCamelCase($setting);

				if ($setting != $settingOriginal && isset($settings[$settingOriginal]))
					unset($settings[$settingOriginal]);

				$settings[$setting] = $this->formatSettingsForJs($value);
			}
		}
		return $settings;
	}

	/**
	 * Turn a dash formatted string into a camel case formatted string.
	 *
	 * @param  string  $string
	 * @return string
	 */
	public function dashedToCamelCase($string) {
		$string    = str_replace(' ', '', ucwords(str_replace('-', ' ', $string)));
		$string[0] = strtolower($string[0]);

		return $string;
	}

	/**
	 * Turn an underscore formatted string into a camel case formatted string.
	 *
	 * @param  string  $string
	 * @return string
	 */
	public function underscoredToCamelCase($string) {
		$string    = str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
		$string[0] = strtolower($string[0]);

		return $string;
	}

	/**
	 * Turn a camel case formatted string into an underscore formatted string.
	 *
	 * @param  string  $string
	 * @return string
	 */
	public function camelCaseToUnderscore($string) {
		return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $string));
	}

	/**
	 * Get the validators array.
	 *
	 */
	public function getValidation()
	{
		return $this->validation;
	}

	/**
	 * Create an HTML submit input element.
	 *
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function submit($value = 'Submit', $attributes = [])
	{
		return $this->input('submit', null, $value, $attributes);
	}

	/**
	 * Create an HTML reset input element.
	 *
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function reset($value = null, $attributes = [])
	{
		return $this->input('reset', null, $value, $attributes);
	}

	/**
	 * Create an HTML image input element.
	 *
	 * <code>
	 *		// Create an image input element
	 *		echo Form::image('img/submit.png');
	 * </code>
	 *
	 * @param  string  $url
	 * @param  string  $name
	 * @param  array   $attributes
	 * @return string
	 */
	public function image($url, $name = null, $attributes = [])
	{
		$attributes['src'] = URL::toAsset($url);

		return $this->input('image', $name, null, $attributes);
	}

	/**
	 * Create an HTML button element.
	 *
	 * @param  string  $value
	 * @param  array   $attributes
	 * @return string
	 */
	public function button($value = null, $attributes = [])
	{
		if (!isset($attributes['class']))
			$attributes['class'] = 'btn btn-default';
		else
			$attributes['class'] .= ' btn btn-default';

		if ($value == strip_tags($value))
			$value = $this->entities($value);

		return '<button'.$this->attributes($attributes).'>'.$value.'</button>' . "\n";
	}

	/**
	 * Create a label for a submit function based on a resource controller URL.
	 *
	 * @param  mixed   $itemName
	 * @param  mixed   $update
	 * @param  mixed   $icon
	 * @return string
	 */
	public function submitResource($itemName = null, $update = null, $icon = null)
	{
		//if null, check config button icon config setting
		if (is_null($icon))
			$icon = Config::get('formation.autoButtonIcon');

		if (is_null($update))
			$update = $this->updateResource();

		if ($update) {
			$label = 'Update';
			if (is_bool($icon) && $icon)
				$icon = 'ok';
		} else {
			$label = 'Create';
			if (is_bool($icon) && $icon)
				$icon = 'plus';
		}

		//add icon code
		if (is_string($icon) && $icon != "")
			$label = '[ICON: '.$icon.']'.$label;

		if (!is_null($itemName) && $itemName != "")
			$label .= ' '.$itemName;

		return $label;
	}

	/**
	 * Get the status create / update status from the resource controller URL.
	 *
	 * @param  mixed   $route
	 * @return bool
	 */
	public function updateResource($route = null)
	{
		$route = $this->route($route);

		//set method based on route
		if (substr($route[0], -5) == ".edit")
			return true;
		else
			return false;
	}

	/**
	 * Get the date format for populating date fields.
	 *
	 * @return string
	 */
	public function getDateFormat()
	{
		return Config::get('formation.dateFormat');
	}

	/**
	 * Get the date-time format for populating date-time fields.
	 *
	 * @return string
	 */
	public function getDateTimeFormat()
	{
		return Config::get('formation.dateTimeFormat');
	}

	/**
	 * Get the appliction.encoding without needing to request it from Config::get() each time.
	 *
	 * @return string
	 */
	protected function encoding()
	{
		return $this->encoding ?: $this->encoding = Config::get('site.encoding');
	}

}
