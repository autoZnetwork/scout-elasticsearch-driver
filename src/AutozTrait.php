<?php

namespace ScoutElastic;

use ReflectionMethod;

trait AutozTrait
{
    /**
     * Create a result collection of models from plain arrays.
     *
     * @param  array  $items
     * @param  array  $meta
     * @return AutozResultCollection
     */
    public static function hydrateElasticResult($items, $meta = [])
    {
        $instance = new static;

        $items = array_map(function ($item) use ($instance) {
            return $instance->newFromHitBuilder($item);
        }, $items);

        return new AutozResultCollection($items, $meta);
    }

    /**
     * New From Hit Builder
     *
     * Variation on newFromBuilder. Instead, takes
     *
     * @param array $hit
     *
     * @return static
     */
    public function newFromHitBuilder($hit = array())
    {
        $keyName = $this->getKeyName();
        $attributes = $hit['_source'];

        if (isset($hit['_id'])) {
            $attributes[$keyName] = is_numeric($hit['_id']) ? intval($hit['_id']) : $hit['_id'];
        }

        // add fields to attributes
        if (isset($hit['fields'])) {
            foreach ($hit['fields'] as $key => $value) {
                $attributes[$key] = $value;
            }
        }

        $instance = $this::newFromBuilderRecursive($this, $attributes);

        // in addition to setting the attributes from the index, we will set the score as well
        $instance->documentScore = $hit['_score'];

        // this is now a model created from an Elasticsearch document
        $instance->isDocument = true;

        // set our document version if set
        if (isset($hit['_version'])) {
            $instance->documentVersion = $hit['_version'];
        }

        return $instance;
    }

    /**
     * Create a new model instance that is existing recursive.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array  $attributes
     * @param  \Illuminate\Database\Eloquent\Relations\Relation  $parentRelation
     * @return static
     */
    public static function newFromBuilderRecursive($model, array $attributes = [], $parentRelation = null)
    {
        $instance = $model->newInstance([], $exists = true);

        $instance->setRawAttributes((array)$attributes, $sync = true);

        // Load relations recursive
        static::loadRelationsAttributesRecursive($instance);
        // Load pivot
        static::loadPivotAttribute($instance, $parentRelation);

        return $instance;
    }

    /**
     * Get the relations attributes from a model.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     */
    public static function loadRelationsAttributesRecursive($model)
    {
        $attributes = $model->getAttributes();

        foreach ($attributes as $key => $value) {
            if (method_exists($model, $key)) {
                $reflection_method = new ReflectionMethod($model, $key);

                // Check if method class has or inherits Illuminate\Database\Eloquent\Model
                if(!static::isClassInClass("Illuminate\Database\Eloquent\Model", $reflection_method->class)) {
                    $relation = $model->$key();

                    if ($relation instanceof Relation) {
                        // Check if the relation field is single model or collections
                        if (is_null($value) === true || !static::isMultiLevelArray($value)) {
                            $value = [$value];
                        }

                        $models = static::hydrateRecursive($relation->getModel(), $value, $relation);

                        // Unset attribute before match relation
                        unset($model[$key]);
                        $relation->match([$model], $models, $key);
                    }
                }
            }
        }
    }

    /**
     * Get the pivot attribute from a model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Eloquent\Relations\Relation  $parentRelation
     */
    public static function loadPivotAttribute($model, $parentRelation = null)
    {
        $attributes = $model->getAttributes();

        foreach ($attributes as $key => $value) {
            if ($key === 'pivot') {
                unset($model[$key]);
                $pivot = $parentRelation->newExistingPivot($value);
                $model->setRelation($key, $pivot);
            }
        }
    }

    /**
     * Create a collection of models from plain arrays recursive.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @param  \Illuminate\Database\Eloquent\Relations\Relation $parentRelation
     * @param  array $items
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function hydrateRecursive($model, array $items, $parentRelation = null)
    {
        $instance = $model;

        $items = array_map(function ($item) use ($instance, $parentRelation) {
            // Convert all null relations into empty arrays
            $item = $item ?: [];

            return static::newFromBuilderRecursive($instance, $item, $parentRelation);
        }, $items);

        return $instance->newCollection($items);
    }

    /**
     * Check if an array is multi-level array like [[id], [id], [id]].
     *
     * For detect if a relation field is single model or collections.
     *
     * @param  array  $array
     * @return boolean
     */
    private static function isMultiLevelArray(array $array)
    {
        foreach ($array as $key => $value) {
            if (!is_array($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $classNeedle
     * @param string $classHaystack
     * @return bool
     *
     * @param $classNeedle
     * @param $classHaystack
     * @return bool
     * @throws \ReflectionException
     */
    private static function isClassInClass($classNeedle, $classHaystack)
    {
        // check for the same
        if ($classNeedle == $classHaystack) {
            return true;
        }

        // Check for parent
        $classHaystackReflected = new \ReflectionClass($classHaystack);

        while ($parent = $classHaystackReflected->getParentClass()) {
            /**
             * @var \ReflectionClass $parent
             */
            if ($parent->getName() == $classNeedle) {
                return true;
            }

            $classHaystackReflected = $parent;
        }

        return false;
    }
}
