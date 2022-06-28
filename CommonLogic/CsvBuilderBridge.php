<?php
namespace exface\UrlDataConnector\CommonLogic;

use exface\Core\QueryBuilders\CsvBuilder;
use exface\Core\Interfaces\Selectors\QueryBuilderSelectorInterface;
use exface\Core\Interfaces\DataSources\DataQueryInterface;

class CsvBuilderBridge extends CsvBuilder
{
    private $query = null;
    
    public function __construct(QueryBuilderSelectorInterface $selector, DataQueryInterface $query)
    {
        parent::__construct($selector);
        $this->query = $query;
    }
    
    protected function buildQuery()
    {
        return $this->query;
    }
}