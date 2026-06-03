<?php
declare( strict_types=1 );

namespace Callismart\DBPrism\Utils;

use Callismart\DBPrism\Query\Traits\QueryCriteriaTrait;

/**
 * Class CaseExpression
 * 
 * Canonical DTO tracking branches and fallback payloads for an ANSI CASE expression.
 * Reuses QueryCriteriaTrait strictly as an internal sandboxed engine to track
 * independent logical conditions and bound parameters for WHEN clauses.
 * 
 * @package Callismart\DBPrism
 */
class CaseExpression {
    
    use QueryCriteriaTrait;

    /**
     * Tracked collection of conditional branches.
     * 
     * @var array<array{criteria: static, then_value: mixed}>
     */
    protected array $branches = [];

    /**
     * The fallback value for the ELSE clause.
     * 
     * @var mixed
     */
    protected mixed $else_value = null;

    /**
     * @var string|null The column alias for the final expression.
     */
    protected ?string $alias = null;

    /**
     * Assign a column alias to the resulting case expression value.
     * 
     * @param string $alias
     * @return static
     */
    public function as( string $alias ) : static {
        $this->alias = $alias;
        return $this;
    }

    /**
     * Add a conditional branch evaluation statement.
     * 
     * @param callable $condition  Closure receiving an isolated CaseExpression sandbox to apply criteria.
     * @param mixed    $then_value The literal value or expression returned if the criteria matches.
     * @return static
     */
    public function when( callable $condition, mixed $then_value ) : static {
        $branch_context = new static();

        $condition( $branch_context );

        $this->branches[] = [
            'criteria'   => $branch_context, 
            'then_value' => $then_value
        ];

        return $this;
    }

    /**
     * Define the fallback value for the matching sequence.
     * 
     * @param mixed $value
     * @return static
     */
    public function else( mixed $value ) : static {
        $this->else_value = $value;
        return $this;
    }

    /**
     * Retrieve all registered branches.
     * 
     * @return array<array{criteria: static, then_value: mixed}>
     */
    public function get_branches() : array {
        return $this->branches;
    }

    /**
     * Retrieve the fallback ELSE value.
     * 
     * @return mixed
     */
    public function get_else() : mixed {
        return $this->else_value;
    }

    /**
     * Get the assigned column alias for this case expression, if any.
     * 
     * @return string|null
     */
    public function get_alias() : ?string {
        return $this->alias;
    }
}