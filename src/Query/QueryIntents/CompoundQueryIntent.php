<?php
/**
 * Compound Query Intent class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\QueryIntents
 * @since 0.2.0
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\QueryIntents;

use Callismart\DBPrism\Query\SQLBuilder;
use Callismart\DBPrism\Query\Traits\SupportsUnionsTrait;
use Callismart\DBPrism\Query\Traits\SQLBuilderStrategyTrait;
use Callismart\DBPrism\Query\Traits\SupportsOrderingTrait;
use Callismart\DBPrism\Query\Traits\SupportsSlicingTrait;

/**
 * Represents an intent to execute combined structural relational set operations.
 * * This class orchestrates the horizontal and vertical stacking of independent
 * selection intents using standard SQL set operators like UNION and UNION ALL.
 *
 * @since 0.2.0
 */
class CompoundQueryIntent implements QueryIntentInterface {
    use SupportsUnionsTrait, SQLBuilderStrategyTrait,
        SupportsSlicingTrait, SupportsOrderingTrait;

    /**
     * Collection of subsequent stacked compound dataset components.
     *
     * @var UnionPayload[]
     */
    protected array $unions = [];

    /**
     * The primary root query context (the anchor expression).
     *
     * @var QueryIntentInterface
     */
    protected QueryIntentInterface $primary_intent;
    
    /**
     * The orchestrating builder factory instance.
     *
     * @var SQLBuilder
     */
    protected SQLBuilder $builder;

    /**
     * Construct a compound context wrapping two starting operations.
     * 
     * @param QueryIntentInterface $primary  The initial root dataset expression.
     * @param QueryIntentInterface $next     The secondary query block to stack.
     * @param string               $operator The target relational operator (e.g., 'UNION').
     * @param SQLBuilder           $builder  The parent state coordinator.
     */
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

    /**
     * Stack a selection intent using a distinctive, unique-filtered UNION set comparison.
     *
     * @param QueryIntentInterface $intent
     * @return static
     */
    public function union( QueryIntentInterface $intent ) : static {
        $this->unions[] = new UnionPayload( $intent, 'UNION' );
        return $this;
    }

    /**
     * Stack a selection intent using a high-performance, non-filtered UNION ALL comparison.
     *
     * @param QueryIntentInterface $intent
     * @return static
     */
    public function union_all( QueryIntentInterface $intent ) : static {
        $this->unions[] = new UnionPayload( $intent, 'UNION ALL' );
        return $this;
    }

    /**
     * Retrieve the initial anchor query intent component.
     *
     * @return QueryIntentInterface
     */
    public function get_primary() : QueryIntentInterface {
        return $this->primary_intent;
    }

    /**
     * Retrieve all subsequent grouped stacked datasets payload definitions.
     *
     * @return UnionPayload[]
     */
    public function get_unions() : array {
        return $this->unions;
    }

    /**
     * Retrieve all accumulated parameter bindings sequentially across the entire compound query stack.
     *
     * @return array Sequential positional parameter parameter values.
     */
    public function get_bindings() : array {
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
     *
     * @throws \BadMethodCallException Compound query environments cannot be dynamically cloned without explicit boundaries.
     */
    public function new_instance() : static {
        throw new \BadMethodCallException( sprintf( 'The %s context cannot be duplicated as an isolated fresh instance.', self::class ) );
    }
}