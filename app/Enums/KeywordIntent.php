<?php

namespace App\Enums;

/**
 * Search intent on a keyword/spoke — tagged by the SAME classifier call that does silo/spoke
 * classification (one extra output field, no new pass). Drives the longtail routing rule:
 * a supporting keyword's home is a page fold (transactional/commercial) or the silo's blog
 * target queue (informational). One keyword, one home — never both.
 */
enum KeywordIntent: string
{
    /** Hire/buy intent — "sump pump installation near me". */
    case Transactional = 'transactional';

    /** Evaluating — "best battery backup sump pump", "cost of…". */
    case Commercial = 'commercial';

    /** Learning — "why is my basement wet in spring". */
    case Informational = 'informational';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
