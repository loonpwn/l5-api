<?php

namespace Specialtactics\L5Api\Transformers;

use League\Fractal\TransformerAbstract;
use Specialtactics\L5Api\APIBoilerplate;
use Specialtactics\L5Api\Models\RestfulModel;

class RestfulTransformer extends TransformerAbstract
{
    /**
     * @var RestfulModel The model to be transformed
     */
    protected $model = null;

    /**
     * Transform an object into a jsonable array
     *
     * @param Object $model
     * @return array
     * @throws \Exception
     */
    public function transform(Object $object)
    {
        if ($object instanceof RestfulModel) {
            $transformed = $this->transformRestfulModel($object);
        }

        else if ($object instanceof \stdClass) {
            $transformed = $this->transformStdClass($object);
        }

        else {
            throw new \Exception('Unexpected object type encountered in transformer');
        }

        return $transformed;
    }

    /**
     * Transform an arbitrary stdClass
     *
     * @param \stdClass $object
     * @return array
     */
    public function transformStdClass($object) {

        $transformed = (array)$object;

        /**
         * Transform all keys to CamelCase, recursively
         */
        $transformed = $this->formatCase($transformed);

        return $transformed;
    }

    /**
     * Transform an eloquent object into a jsonable array
     *
     * @param RestfulModel $model
     * @return array
     */
    public function transformRestfulModel(RestfulModel $model) {
        $this->model = $model;

        // Begin the transformation!
        $transformed = $model->toArray();

        /**
         * Filter out attributes we don't want to expose to the API
         */
        $filterOutAttributes = $this->getFilteredOutAttributes();

        $transformed = array_filter($transformed, function ($key) use ($filterOutAttributes) {
            return ! in_array($key, $filterOutAttributes);
        }, ARRAY_FILTER_USE_KEY);

        /**
         * Format all dates as Iso8601 strings, this includes the created_at and updated_at columns
         */
        foreach ($model->getDates() as $dateColumn) {
            if (!empty($model->$dateColumn) && !in_array($dateColumn, $filterOutAttributes)) {
                $transformed[$dateColumn] = $model->$dateColumn->toIso8601String();
            }
        }

        /**
         * Primary Key transformation - all PKs to be called "id"
         */
        $transformed = array_merge(
            ['id' => $model->getKey()],
            $transformed
        );
        unset($transformed[$model->getKeyName()]);

        /**
         * Transform all keys to CamelCase, recursively
         */
        $transformed = $this->formatCase($transformed);

        /**
         * Get the relations for this object and transform them
         */
        $transformed = $this->transformRelations($transformed);

        return $transformed;
    }

    /**
     * Formats case of the input array or scalar to desired case
     *
     * @param array|mixed $input
     * @return array $transformed
     */
    protected function formatCase($input) {
        $caseFormat = APIBoilerplate::getResponseCaseType();

        if ($caseFormat == APIBoilerplate::CAMEL_CASE) {
            if (is_array($input)) {
                $transformed = camel_case_array_keys($input);
            } else {
                $transformed = camel_case($input);
            }
        } else if ($caseFormat == APIBoilerplate::SNAKE_CASE) {
            if (is_array($input)) {
                $transformed = snake_case_array_keys($input);
            } else {
                $transformed = snake_case($input);
            }
        }

        return $transformed;
    }

	/**
	 * Filter out some attributes immediately
	 *
	 * Some attributes we never want to expose to an API consumer, for security and separation of concerns reasons
	 * Feel free to override this function as necessary
	 *
	 * @return array Array of attributes to filter out
	 */
	protected function getFilteredOutAttributes() {
		return collect($this->model->getAttributes())
			->keys()
			->diff($this->model::$allowedFields)
			->toArray();
	}

    /**
     * Do relation transformations
     *
     * @param array $transformed
     * @return array $transformed
     */
    protected function transformRelations(array $transformed) {
        // Iterate through all relations
        foreach ($this->model->getRelations() as $relationKey => $relation) {

            // Skip Pivot
            if ($relation instanceof \Illuminate\Database\Eloquent\Relations\Pivot) {
                continue;
            }

            // Transform Collection
            else if ($relation instanceof \Illuminate\Database\Eloquent\Collection) {
                if (count($relation->getIterator()) > 0) {

                    $relationModel = $relation->first();
                    $relationTransformer = $relationModel::getTransformer();

                    // Transform related model collection
                    if ($this->model->$relationKey) {
                        $transformedRelationKey = $this->formatCase($relationKey);

                        // Create empty array for relation
                        $transformed[$transformedRelationKey] = [];

                        foreach ($relation->getIterator() as $key => $relatedModel) {
                            // Replace the related models with their transformed selves
                            $transformedRelatedModel = $relationTransformer->transform($relatedModel);

                            // We don't really care about pivot information at this stage
                            if (isset($transformedRelatedModel['pivot'])) {
                                unset($transformedRelatedModel['pivot']);
                            }

                            // Add transformed model to relation array
                            $transformed[$transformedRelationKey][] = $transformedRelatedModel;
                        }
                    }
                }
            }

            // Transformed related model
            else if ($relation instanceof RestfulModel) {
                // Get transformer of relation model
                $relationTransformer = $relation::getTransformer();

                if ($this->model->$relationKey) {
                    $transformed[$this->formatCase($relationKey)] = $relationTransformer->transform($this->model->$relationKey);
                }
            }
        }

        return $transformed;
    }
}
