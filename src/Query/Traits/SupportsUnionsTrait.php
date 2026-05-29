<?php

namespace Callismart\DBPrism\Query\Traits;

use Callismart\DBPrism\Query\QueryIntents\CompoundQueryIntent;
use Callismart\DBPrism\Query\QueryIntents\QueryIntentInterface;
use Callismart\DBPrism\Query\SQLBuilder;

trait SupportsUnionsTrait {
    /**
     * The orchestrating builder factory instance.
     *
     * @var SQLBuilder $builder
     */
    protected SQLBuilder $builder;
    
    public function union( QueryIntentInterface $intent ) : CompoundQueryIntent {
        return new CompoundQueryIntent( $this, $intent, 'UNION', $this->builder );
    }

    public function union_all( QueryIntentInterface $intent ) : CompoundQueryIntent {
        return new CompoundQueryIntent( $this, $intent, 'UNION ALL', $this->builder );
    }
}