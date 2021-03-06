<?php

namespace Statamic\Tags\Taxonomy;

use Closure;
use Illuminate\Support\Carbon;
use Statamic\Facades;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Support\Arr;
use Statamic\Tags\Concerns;
use Statamic\Taxonomies\TermCollection;

class Terms
{
    use Concerns\QueriesConditions,
        Concerns\QueriesScopes,
        Concerns\QueriesOrderBys,
        Concerns\GetsQueryResults;

    protected $ignoredParams = ['as'];
    protected $parameters;
    protected $taxonomies;
    protected $collections;

    public function __construct($parameters)
    {
        $this->parseParameters($parameters);
    }

    public function get()
    {
        try {
            $query = $this->query();
        } catch (NoResultsExpected $exception) {
            return new TermCollection;
        }

        $terms = $this->results($query);

        // If we can infer which collection is being targeted, we'll add it so
        // that the term URLs resolve to their collection equivalents.
        // eg. /blog/tags/tag instead of just /tags/tag
        if ($this->collections->count() === 1) {
            $terms->each->collection($this->collections[0]);
        }

        return $terms;
    }

    public function count()
    {
        try {
            return $this->query()->count();
        } catch (NoResultsExpected $exception) {
            return 0;
        }
    }

    protected function query()
    {
        $query = Term::query()
            ->whereIn('taxonomy', $this->taxonomies->map->handle()->all());

        if ($this->collections) {
            $query->whereIn('collections', $this->collections->map->handle()->all());
        }

        $this->queryConditions($query);
        $this->queryScopes($query);
        $this->queryOrderBys($query);

        return $query;
    }

    protected function parseParameters($params)
    {
        $this->parameters = Arr::except($params->all(), $this->ignoredParams);
        $this->taxonomies = $this->parseTaxonomies();
        $this->orderBys = $this->parseOrderBys();
        $this->collections = $this->parseCollections();
    }

    protected function parseTaxonomies()
    {
        $from = Arr::getFirst($this->parameters, ['from', 'in', 'folder', 'use', 'taxonomy']);
        $not = Arr::getFirst($this->parameters, ['not_from', 'not_in', 'not_folder', 'dont_use', 'not_taxonomy']);

        $taxonomies = $from === '*'
            ? collect(Taxonomy::handles())
            : collect(explode('|', $from));

        $excludedTaxonomies = collect(explode('|', $not))->filter();

        return $taxonomies
            ->diff($excludedTaxonomies)
            ->map(function ($handle) {
                $taxonomy = Taxonomy::findByHandle($handle);
                throw_unless($taxonomy, new \Statamic\Exceptions\TaxonomyNotFoundException($handle));
                return $taxonomy;
            })
            ->values();
    }

    protected function parseCollections()
    {
        $collections = Arr::getFirst($this->parameters, ['collection', 'collections']);

        if (! $collections) {
            return collect();
        }

        return collect(explode('|', $collections))
            ->map(function ($handle) {
                $collection = Collection::findByHandle($handle);
                throw_unless($collection, new \Statamic\Exceptions\CollectionNotFoundException("Collection [{$handle}] does not exist."));
                return $collection;
            })
            ->values();
    }

    protected function defaultOrderBy()
    {
        return 'title:asc';
    }
}
