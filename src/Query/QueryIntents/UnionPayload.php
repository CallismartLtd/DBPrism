<?php

namespace Callismart\DBPrism\Query\QueryIntents;

class UnionPayload {
    public function __construct(
        public QueryIntentInterface $intent,
        public string $operator // 'UNION' or 'UNION ALL'
    ) {}
}