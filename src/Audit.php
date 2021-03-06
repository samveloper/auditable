<?php

namespace Samveloper\Auditable;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Support\Facades\Log;

/**
 * Audit.
 * Base model to allow for audit history on
 * any model that extends this model
 */
class Audit extends Eloquent
{
    /**
     * @var string
     */
    public $table = 'audits';

    /**
     * @var array
     */
    protected $auditFormattedFields = [];

    /**
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    /**
     * Auditable.
     * Grab the audit history for the model that is calling
     *
     * @return array audit history
     */
    public function auditable()
    {
        return $this->morphTo();
    }

    /**
     * Field Name
     * Returns the field that was updated, in the case that it's a foreign key
     * denoted by a suffix of "_id", then "_id" is simply stripped
     *
     * @return string field
     */
    public function fieldName()
    {
        if ($formatted = $this->formatFieldName($this->key)) {
            return $formatted;
        } elseif (strpos($this->key, '_id')) {
            return str_replace('_id', '', $this->key);
        } else {
            return $this->key;
        }
    }

    /**
     * Format field name.
     * Allow overrides for field names.
     *
     * @param $key
     * @return bool
     */
    private function formatFieldName($key)
    {
        $related_model = $this->auditable_type;
        $related_model = new $related_model;
        $auditFormattedFieldNames = $related_model->getAuditFormattedFieldNames();

        if (isset($auditFormattedFieldNames[$key])) {
            return $auditFormattedFieldNames[$key];
        }

        return false;
    }

    /**
     * Old Value.
     * Grab the old value of the field, if it was a foreign key
     * attempt to get an identifying name for the model.
     *
     * @return string old value
     */
    public function oldValue()
    {
        return $this->getValue('old');
    }


    /**
     * New Value.
     * Grab the new value of the field, if it was a foreign key
     * attempt to get an identifying name for the model.
     *
     * @return string old value
     */
    public function newValue()
    {
        return $this->getValue('new');
    }


    /**
     * Responsible for actually doing the grunt work for getting the
     * old or new value for the audit.
     *
     * @param  string $which old or new
     * @return string value
     */
    private function getValue($which = 'new')
    {
        $which_value = $which . '_value';

        // First find the main model that was updated
        $main_model = $this->auditable_type;
        // Load it, WITH the related model
        if (class_exists($main_model)) {
            $main_model = new $main_model;

            try {
                if (strpos($this->key, '_id')) {
                    $related_model = str_replace('_id', '', $this->key);

                    // Now we can find out the namespace of of related model
                    if (!method_exists($main_model, $related_model)) {
                        $related_model = camel_case($related_model); // for cases like published_status_id
                        if (!method_exists($main_model, $related_model)) {
                            throw new \Exception('Relation ' . $related_model . ' does not exist for ' . $main_model);
                        }
                    }
                    $related_class = $main_model->$related_model()->getRelated();

                    // Finally, now that we know the namespace of the related model
                    // we can load it, to find the information we so desire
                    $item = $related_class::find($this->$which_value);

                    if (is_null($this->$which_value) || $this->$which_value == '') {
                        $item = new $related_class;

                        return $item->getAuditNullString();
                    }
                    if (!$item) {
                        $item = new $related_class;

                        return $this->format($this->key, $item->getAuditUnknownString());
                    }

                    // see if there's an available mutator
                    $mutator = 'get' . studly_case($this->key) . 'Attribute';
                    if (method_exists($item, $mutator)) {
                        return $this->format($item->$mutator($this->key), $item->identifiableName());
                    }

                    return $this->format($this->key, $item->identifiableName());
                }
            } catch (\Exception $e) {
                // Just a fail-safe, in the case the data setup isn't as expected
                // Nothing to do here.
                Log::info('Auditable: ' . $e);
            }

            // if there was an issue
            // or, if it's a normal value

            $mutator = 'get' . studly_case($this->key) . 'Attribute';
            if (method_exists($main_model, $mutator)) {
                return $this->format($this->key, $main_model->$mutator($this->$which_value));
            }
        }

        return $this->format($this->key, $this->$which_value);
    }

    /**
     * User Responsible.
     *
     * @return User user responsible for the change
     */
    public function userResponsible()
    {
        if (empty($this->user_id)) {
            return false;
        }
        if (class_exists($class = '\Cartalyst\Sentry\Facades\Laravel\Sentry')
            || class_exists($class = '\Cartalyst\Sentinel\Laravel\Facades\Sentinel')
        ) {
            return $class::findUserById($this->user_id);
        } else {
            $user_model = app('config')->get('auth.model');

            if (empty($user_model)) {
                $user_model = app('config')->get('auth.providers.users.model');
                if (empty($user_model)) {
                    return false;
                }
            }
            if (!class_exists($user_model)) {
                return false;
            }
            return $user_model::find($this->user_id);
        }
    }

    /**
     * Returns the object we have the history of
     *
     * @return Object|false
     */
    public function historyOf()
    {
        if (class_exists($class = $this->auditable_type)) {
            return $class::find($this->auditable_id);
        }

        return false;
    }

    /*
     * Examples:
    array(
        'public' => 'boolean:Yes|No',
        'minimum'  => 'string:Min: %s'
    )
     */
    /**
     * Format the value according to the $auditFormattedFields array.
     *
     * @param  $key
     * @param  $value
     * @return string formatted value
     */
    public function format($key, $value)
    {
        $related_model = $this->auditable_type;
        $related_model = new $related_model;
        $auditFormattedFields = $related_model->getAuditFormattedFields();

        if (isset($auditFormattedFields[$key])) {
            return FieldFormatter::format($key, $value, $auditFormattedFields);
        } else {
            return $value;
        }
    }
}
