<?php

namespace Callismart\DBPrism\Query\QueryIntents;

use Callismart\DBPrism\Query\SQLBuilder;
use Callismart\DBPrism\Query\SQLBuilderStrategyTrait;
use Override;

class CompoundQueryIntent implements QueryIntentInterface {
    use SupportsUnionsTrait, SQLBuilderStrategyTrait;

    /**
     * Collection of union.
     * 
     * @var UnionPayload[]
     */
    protected array $unions = [];

    protected QueryIntentInterface $primary_intent;
    
    protected SQLBuilder $builder;

    public function __construct( 
        QueryIntentInterface $primary, 
        QueryIntentInterface $next, 
        string $operator, 
        SQLBuilder $builder 
    ) {
        $this->primary_intent = $primary;
        $this->builder        = $builder;
        $this->unions[]       = new UnionPayload( $next, $operator );
        
        // Update the orchestrator's state target tracking pointer.
        $this->builder->set_active_intent( $this );
        $this->builder->set_type( 'COMPOUND SELECT' );
    }

    public function union( QueryIntentInterface $intent ) : static {
        $this->unions[] = new UnionPayload( $intent, 'UNION' );
        return $this;
    }

    public function union_all( QueryIntentInterface $intent ) : static {
        $this->unions[] = new UnionPayload( $intent, 'UNION ALL' );
        return $this;
    }

    public function get_primary() : QueryIntentInterface {
        return $this->primary_intent;
    }

    /**
     * Get the union payload.
     * 
     * @return UnionPayload[]
     */
    public function get_unions() : array {
        return $this->unions;
    }

    public function get_bindings() : array {
        // Sequentially collect parameter bindings from top to bottom
        $bindings = $this->primary_intent->get_bindings();
        
        foreach ( $this->unions as $union ) {
            $bindings = array_merge( $bindings, $union->intent->get_bindings() );
        }
        
        return $bindings;
    }

    /**
     * {@inheritdoc}
     */
    public function build() : string {
        return $this->builder->build();
    }

    /**
     * {@inheritdoc}
     */
    public function new_instance(): static {
        throw new \Exception( 'Not implemented' );
    }


}