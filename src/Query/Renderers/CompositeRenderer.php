<?php
namespace Callismart\DBPrism\Query\Renderers;

/**
 * Composite Renderer Contract.
 */
interface CompositeRenderer {

    /**
     * Render UNION / UNION ALL query.
     *
     * @param \Callismart\DBPrism\Query\SQLBuilder[] $queries
     * @param string       $type
     * @param array        $intent
     *
     * @return string
     */
    public function render_union(
        array $queries,
        string $type,
        array $intent
    ) : string;
}