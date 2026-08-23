<?php
namespace Callismart\DBPrism\Adapters;

/**
 * Postgres Adapter extending the generic PDO Adapter.
 */
class PostgresAdapter extends PdoAdapter {

    public function get_driver() : string {
        return 'pgsql';
    }

    /**
     * {@inheritdoc}
     */
    public function build_dsn(): string {
        return $this->build_pgsql_dsn();
    }
}