<?php namespace Aquanode\Formation;

use Illuminate\Database\Eloquent\Model as Eloquent;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;

use Aquanode\Formation\Formation as Form;

use Regulus\TetraText\TetraText as Format;

class BaseModel extends Eloquent {

	/**
	 * The special typed fields for the model.
	 *
	 * @var    array
	 */
	protected static $types = array();

	/**
	 * The foreign key for the model.
	 *
	 * @var    string
	 */
	protected $foreignKey = null;

	/**
	 * Get the default foreign key name for the model.
	 *
	 * @return string
	 */
	public function getForeignKey()
	{
		if (!is_null($this->foreignKey))
			return $this->foreignKey;
		else
			return snake_case(class_basename($this)).'_id';
	}

	/**
	 * Get the special typed fields for the model.
	 *
	 * @return array
	 */
	public function getFieldTypes()
	{
		return static::$types;
	}

	/**
	 * The default values for the model.
	 *
	 * @return array
	 */
	public static function defaults()
	{
		return array();
	}

	/**
	 * Get the validation rules used by the model.
	 *
	 * @param  mixed    $id
	 * @return string
	 */
	public static function validationRules($id = null)
	{
		return array();
	}

	/**
	 * Set the validation rules for the model.
	 *
	 * @return string
	 */
	public function setValidationRules()
	{
		Form::setValidationRules(static::validationRules((int) $this->id));
	}

	/**
	 * Get the formatted values for populating a form.
	 *
	 * @param  array    $relations
	 * @return string
	 */
	public function getFormattedValues($relations = array())
	{
		foreach ($this->getFieldTypes() as $field => $type) {
			if (isset($this->{$field})) {
				$value = $this->{$field};
				$this->{$field} = static::formatValue($value, $type);
			}
		}

		foreach ($relations as $relation) {
			if ($this->{$relation}) {
				foreach ($this->{$relation} as &$item) {
					foreach ($item->getFieldTypes() as $field => $type) {
						if (isset($item->{$field})) {
							$value = $item->{$field};
							$item->{$field.'_formatted'} = static::formatValue($value, $type);
						}
					}
				}
			}
		}

		return $this;
	}

	/**
	 * Get the formatted values for populating a form.
	 *
	 * @param  string   $value
	 * @param  string   $type
	 * @return string
	 */
	private static function formatValue($value, $type)
	{
		switch ($type) {
			case "date":      $value = ($value != "0000-00-00" ? date(Form::getDateFormat(), strtotime($value)) : ""); break;
			case "date-time": $value = ($value != "0000-00-00 00:00:00" ? date(Form::getDateTimeFormat(), strtotime($value)) : ""); break;
		}

		return $value;
	}

	/**
	 * Set the default values for the model for a new item.
	 *
	 * @param  mixed    $prefix
	 * @return array
	 */
	public static function setDefaultsForNew($prefix = null)
	{
		return Form::setDefaults(static::defaults(), array(), $prefix);
	}

	/**
	 * Add a prefix to the default values if one is set.
	 *
	 * @param  array    $defaults
	 * @param  mixed    $prefix
	 * @return array
	 */
	public static function addPrefixToDefaults($defaults = array(), $prefix = null)
	{
		if (is_string($prefix) && $prefix != "")
			$prefix .= ".";

		$defaultsFormatted = array();
		foreach ($defaults as $field => $value) {
			$defaultsFormatted[$prefix.$field] = $value;
		}

		return $defaultsFormatted;
	}

	/**
	 * Set the default values for the model.
	 *
	 * @param  array    $relations
	 * @param  mixed    $prefix
	 * @return array
	 */
	public function setDefaults($relations = array(), $prefix = null)
	{
		return Form::setDefaults($this->getFormattedValues($relations), $relations, $prefix);
	}

	/**
	 * Save the input data to the model.
	 *
	 * @param  mixed    $input
	 * @param  boolean  $new
	 * @return void
	 */
	public function saveData($input = null, $new = false)
	{
		if (is_null($input))
			$input = Input::all();

		foreach ($this->getFieldTypes() as $field => $type) {
			if (isset($input[$field])) {
				switch ($type) {
					case "date":        $input[$field] = ($input[$field] != "" ? date('Y-m-d', strtotime($input[$field])) : "0000-00-00"); break;
					case "date-time":   $input[$field] = ($input[$field] != "" ? date('Y-m-d H:i:s', strtotime($input[$field])) : "0000-00-00 00:00:00"); break;
					case "slug":        $input[$field] = Format::slug($input[$field]); break;
					case "unique-slug": $input[$field] = Format::uniqueSlug($input[$field], $this->table, $field, $this->id); break;
				}
			}
		}

		foreach ($input as $field => $value) {
			if (is_array($value)) {
				$fieldCamelCase = Form::underscoredToCamelCase($field);
				if (isset($this->{$fieldCamelCase})) {
					$this->saveRelationData($value, $fieldCamelCase);
				}
				//var_dump(get_class($this->{$fieldCamelCase}()->getModel())); exit;
			}
		}

		foreach ($this->relations as $models) {
			foreach (\Illuminate\Database\Eloquent\Collection::make($models) as $model) {
				var_dump($model->toArray());
			}
			//var_dump($models);
			//var_dump('x');
			//echo '<br />';
		}

		$this->fill($input);
		$this->save();
	}

	public function saveRelationData($input, $modelMethod)
	{
		$idsSaved        = array();
		$items           = $this->{$modelMethod};
		$model           = get_class($this->{$modelMethod}()->getModel());
		$formattedSuffix = Form::getFormattedFieldSuffix();
		$pivotTimestamps = Config::get('formation::pivotTimestamps');

		//create or update relatied items
		foreach ($input as $index => $itemData) {
			if (isset($itemData['id']) && $itemData['id'] > 0 && $itemData['id'] != "")
				$new = false;
			else
				$new = true;

			$found = false;
			if (!$new) {
				foreach ($items as $item) {
					if ((int) $itemData['id'] == (int) $item->id) {
						$found = true;

						//remove formatted fields from item to prevent errors in saving data
						foreach ($item->toArray() as $field => $value) {
							if (substr($field, -(strlen($formattedSuffix))) == $formattedSuffix)
								unset($item->{$field});
						}

						//save data
						$item->fill($itemData)->save();

						//save pivot data
						if (isset($itemData['pivot'])) {
							$pivotTable = $this->{$modelMethod}()->getTable();
							$pivotKeys  = array(
								$this->foreignKey => $this->id,
								$item->foreignKey => $item->id,
							);

							$pivotData = array_merge($itemData['pivot'], $pivotKeys);

							//set updated timestamp
							if ($pivotTimestamps) {
								$timestamp = date('Y-m-d H:i:s');
								$pivotData['updated_at'] = $timestamp;
							}

							//attempt to select pivot record by both keys
							$pivotItem = DB::table($pivotTable);
							foreach ($pivotKeys as $key => $id) {
								$pivotItem->where($key, $id);
							}

							//if id exists, add it to where clause and unset it
							if (isset($pivotData['id'])) {
								$pivotItem->where('id', $pivotData['id']);
								unset($pivotData['id']);
							}

							//attempt to update and if it doesn't work, insert a new record
							if (!$pivotItem->update($pivotData)) {
								if ($pivotTimestamps)
									$pivotData['created_at'] = $timestamp;

								DB::table($pivotTable)->insert($pivotData);
							}
						}

						if (!in_array((int) $item->id, $idsSaved))
							$idsSaved[] = (int) $item->id;
					}
				}
			}

			if ($new || !$found) {
				$item = new $model;
				$item->fill($itemData)->save();

				if (!in_array((int) $item->id, $idsSaved))
					$idsSaved[] = (int) $item->id;
			}
		}

		//remove any items no longer present in input data
		foreach ($items as $item) {
			if (!in_array((int) $item->id, $idsSaved))
				$item->delete();
		}
	}

	/**
	 * Create a model item and save the input data to the model.
	 *
	 * @param  mixed    $input
	 * @return object
	 */
	public static function createNew($input = null)
	{
		$item = new static;
		$item->saveData($input, true);

		return $item;
	}

	/**
	 * Gets the model by a field other than its ID.
	 *
	 * @param  string   $field
	 * @param  string   $value
	 * @param  array    $relations
	 * @return object
	 */
	public static function findBy($field = 'slug', $value, $relations = array())
	{
		$item = new static;

		foreach ($relations as $relation) {
			$item = $item->with($relation);
		}

		return $item->where($field, $value)->first();
	}

	/**
	 * Gets the model by its slug.
	 *
	 * @param  string   $slug
	 * @param  array    $relations
	 * @return object
	 */
	public static function findBySlug($slug, $relations = array())
	{
		return static::findBy('slug', $slug, $relations);
	}

}