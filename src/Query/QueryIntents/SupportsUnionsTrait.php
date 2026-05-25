<?php

namespace Callismart\DBPrism\Query\QueryIntents;

use Callismart\DBPrism\Query\QueryIntents\CompoundQueryIntent;
use Callismart\DBPrism\Query\QueryIntents\QueryIntentInterface;
use Callismart\DBPrism\Query\SQLBuilder;

trait SupportsUnionsTrait {
    protected SQLBuilder $builder;
    public function union( QueryIntentInterface $intent ) : CompoundQueryIntent {
        return new CompoundQueryIntent( $this, $intent, 'UNION', $this->builder );
    }

    public function union_all( QueryIntentInterface $intent ) : CompoundQueryIntent {
        return new CompoundQueryIntent( $this, $intent, 'UNION ALL', $this->builder );
    }
}