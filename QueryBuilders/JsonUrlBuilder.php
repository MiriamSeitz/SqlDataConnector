<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\QueryBuilderException;
use exface\Core\CommonLogic\DataSheets\DataColumn;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use exface\Core\Exceptions\Model\MetaAttributeNotFoundError;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\DataSources\DataQueryResultDataInterface;
use exface\Core\CommonLogic\DataQueries\DataQueryResultData;
use Psr\Http\Message\RequestInterface;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\Core\CommonLogic\QueryBuilder\QueryPartValue;
use exface\Core\DataTypes\BooleanDataType;

/**
 * This is a query builder for JSON-based REST APIs.
 * 
 * In addition to the logic of the AbstractUrlBuilder, the JsonUrlBuilder will
 * parse the responses and create request bodies as JSON.
 * 
 * # Syntax of data addresses
 * 
 * Concider the following example result structure of a library web service:
 * 
 * ```
 * [
 *  {
 *      "title": "Harray Potter and the Order of the Phoenix",
 *      "authors": [
 *          {
 *              "name": "J.K. Rowling"
 *          }
 *      ],
 *      "publisher": {
 *          "address": {
 *              "country_code": "UK"
 *          }
 *      },
 *      "scancodes": [
 *          {
 *              "type": "ean8",
 *              "code": "123456789213"
 *          }
 *      ]
 *  },
 *  {
 *      "title": "Harray Potter and the Prisoner of Azkaban",
 *      "authors": [
 *          {
 *              "name": "J.K. Rowling"
 *          }
 *      ],
 *      "publisher": {
 *          "address": {
 *              "country_code": "UK"
 *          }
 *      },
 *      "scancodes": [
 *          {
 *              "type": "ean8",
 *              "code": "123456789245"
 *          }
 *      ]
 *  }
 * ]
 * 
 * ```
 * 
 * - `title` will populate it's column with book titles (e.g. "Harray Potter and the Order of the Phoenix" in the first row.
 * - `publisher/address/country_code` will put "UK" in the first row
 * - `authors[1]/name` will get the name of the first author
 * - `scancodes[type=ean8]/code` will get code value from the scancode with type "ean8".
 *
 * @see AbstractUrlBuilder for basic configuration
 * @see HtmlUrlBuilder for a generic HTML-parser
 * @see XmlUrlBuilder for XML-based APIs
 * 
 * @author Andrej Kabachnik
 *        
 */
class JsonUrlBuilder extends AbstractUrlBuilder
{

    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::create()
     */
    public function create(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        // Create JSON objects from value query parts
        $json_objects = $this->buildRequestBodyObjects(static::OPERATION_CREATE);
        
        $insert_ids = array();
        $uidAlias = $this->getMainObject()->getUidAttributeAlias();
        $data_path = $this->getMainObject()->getDataAddressProperty('create_request_data_path');
        foreach ($json_objects as $obj) {
            $request = $this->buildRequestPutPostDelete(static::OPERATION_CREATE, $obj, $data_path);
            $query = new Psr7DataQuery($request);
                        
            $result = $this->parseResponse($data_connection->query($query));
            if (is_array($result)) {
                $result_data = $this->findRowData($result, $data_path);
            }
            $insert_ids[] = [$uidAlias => $this->findFieldInData($this->buildDataAddressForAttribute($this->getMainObject()->getUidAttribute()), $result_data)];
        }
        
        return new DataQueryResultData($insert_ids, count($insert_ids), false);
    }
    
    protected function buildRequestPutPostDelete(string $operation, $jsonObject, string $dataPath = null) : RequestInterface
    {
        $uri = $this->buildDataAddressForObject($this->getMainObject(), $operation);
        $uri = $this->replacePlaceholdersInUrl($uri);
        
        $json = new \stdClass();
        if ($dataPath) {
            $level = & $json;
            foreach ($this->dataPathSplit($dataPath) as $step) {
                $level->$step = new \stdClass();
                $level = & $level->$step;
            }
            $level = $jsonObject;
        } else {
            $json = $jsonObject;
        }
        
        $request = new Request($this->getHttpMethod($operation), $uri, ['Content-Type' => 'application/json'], $this->encodeBody($json));
        
        return $request;
    }
    
    /**
     * 
     * @param \stdClass|array|string $serializableData
     * @return string
     */
    protected function encodeBody($serializableData) : string
    {
        return json_encode($serializableData, JSON_NUMERIC_CHECK);
    }
    
    /**
     * 
     * @param string $operation
     * @return \stdClass[]
     */
    protected function buildRequestBodyObjects(string $operation) : array
    {
        $json_objects = array();
        foreach ($this->getValues() as $qpart) {
            // Ignore values, that do not belong to attributes
            try {
                $attr = $qpart->getAttribute();
            } catch (MetaAttributeNotFoundError $e) {
                continue;
            }
            
            // Ignore values of related attributes
            if (! $attr->getRelationPath()->isEmpty()){
                $this->getWorkbench()->getLogger()->notice('JsonUrlBuilder cannot perform create-operations on related attributes: skipping "' . $attr->getAliasWithRelationPath() . '" of object "' . $this->getMainObject()->getAliasWithNamespace() . '"!');
                continue;
            }
            
            if ($json_attr = $this->buildDataAddressForAttribute($attr, $operation)) {
                foreach ($qpart->getValues() as $row => $val) {
                    if (! $json_objects[$row]) {
                        $json_objects[$row] = new \stdClass();
                    }
                    if (! is_null($val) && $val !== '') {
                        $json_objects[$row]->$json_attr = $this->buildRequestBodyValue($qpart, $val);
                    }
                }
            }
        }
        
        return $json_objects;
    }
    
    /**
     * 
     * @param QueryPartValue $qpart
     * @param mixed $value
     * @return string
     */
    protected function buildRequestBodyValue(QueryPartValue $qpart, $value) : string
    {
        return $value;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::update()
     */
    public function update(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {        
        // Create JSON objects from value query parts
        $json_objects = $this->buildRequestBodyObjects(static::OPERATION_UPDATE);
        
        $insert_ids = array();
        $uidAlias = $this->getMainObject()->getUidAttributeAlias();
        $data_path = $this->getMainObject()->getDataAddressProperty('update_request_data_path');
        foreach ($json_objects as $obj) {
            $request = $this->buildRequestPutPostDelete(static::OPERATION_UPDATE, $obj, $data_path);
            $query = new Psr7DataQuery($request);
            
            $result = $this->parseResponse($data_connection->query($query));
            if (is_array($result)) {
                $result_data = $this->findRowData($result, $data_path);
            }
            $insert_ids[] = [$uidAlias => $this->findFieldInData($this->buildDataAddressForAttribute($this->getMainObject()->getUidAttribute()), $result_data)];
        }
        
        return new DataQueryResultData([], count($insert_ids), false);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildResultRows()
     */
    protected function buildResultRows($parsed_data, Psr7DataQuery $query)
    {
        $result_rows = array();
        
        $rows = $this->findRowData($parsed_data, $this->buildPathToResponseRows($query));
        
        $useUidForRowNumber = $this->getUseUidsAsRowNumbers();
        if (! empty($rows)) {
            if (is_array($rows)) {
                if (BooleanDataType::cast($this->getMainObject()->getDataAddressProperty('response_use_raw_data')) === true) {
                    return $rows;
                }
                foreach ($rows as $row) {
                    $result_row = array();
                    /* @var $qpart \exface\Core\CommonLogic\QueryBuilder\QueryPartSelect */
                    foreach ($this->getAttributes() as $qpart) {
                        $val = $row;
                        if ($path = $qpart->getDataAddress()) {
                            foreach ($this->dataPathSplit($path) as $step) {
                                if ($cond_start = strpos($step, '[')) {
                                    if (substr($step, - 1) != ']')
                                        throw new QueryBuilderException('Invalid conditional selector in attribute "' . $qpart->getAlias() . '": "' . $step . '"!');
                                    $cond = explode('=', substr($step, $cond_start + 1, - 1));
                                    if ($val = $val[substr($step, 0, $cond_start)]) {
                                        foreach ($val as $v) {
                                            if ($v[$cond[0]] == $cond[1]) {
                                                $val = $v;
                                                break;
                                            }
                                        }
                                    }
                                } else {
                                    $val = $val[$step];
                                }
                            }
                            
                            // Check if the value is still an array and an aggregator must be applied
                            if (is_array($val)) {
                                $val = DataColumn::aggregateValues($val, $qpart->getAggregator());
                            }
                            $result_row[$qpart->getColumnKey()] = $val;
                        }
                    }
                    if ($useUidForRowNumber === true) {
                        $result_rows[$result_row[$this->getMainObject()->getUidAttributeAlias()]] = $result_row;
                    } else {
                        $result_rows[] = $result_row;
                    }
                }
            }
        }
        
        if ($useUidForRowNumber === true) {
            $result_rows = array_values($result_rows);
        }
        
        return $result_rows;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::findRowData()
     * 
     * @param array $parsed_data
     * @param string $path
     * 
     * @return array
     */
    protected function findRowData($parsed_data, $path)
    {
        // Get the actual data
        if ($path) {
            // If a path could be determined, follow it
            // $rows = $parsed_data[$path];
            $rows = $this->findFieldInData($path, $parsed_data);
            
            // If it is a UID-request and the data is an assotiative array, it probably represents one single row, so wrap it in an
            // array to make it compatible to the logic of fetching multiple rows
            // TODO make work with any request_split_filter, not just the UID
            if ($this->getRequestSplitFilter() && $this->getRequestSplitFilter()->getAttribute()->isUidForObject() && count(array_filter(array_keys($rows), 'is_string'))) {
                $rows = array(
                    $rows
                );
            }
        } else {
            // If no path specified, try to find the data automatically
            if (count(array_filter(array_keys($parsed_data), 'is_string'))) {
                // If data is an assotiative array, it is most likely to represent one single row
                $rows = array(
                    $parsed_data
                );
            } else {
                // If the data is a sequential array with numeric keys, it is most likely to represent multiple rows
                $rows = $parsed_data;
            }
        }
        
        return $rows;
    }

    /**
     * Converts a data path string to an array (e.g.
     * issue/status/id to [issue, status, id]
     *
     * @param string $string            
     */
    protected function dataPathSplit($string)
    {
        return explode('/', $string);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::findFieldInData()
     */
    protected function findFieldInData($data_address, $data)
    {
        $val = (array) $data;
        if ($data_address === '/') {
            return $data;
        }
        foreach ($this->dataPathSplit($data_address) as $step) {
            if ($cond_start = strpos($step, '[')) {
                if (substr($step, - 1) != ']')
                    throw new QueryBuilderException('Invalid conditional selector in attribute "' . $qpart->getAlias() . '": "' . $step . '"!');
                $cond = explode('=', substr($step, $cond_start + 1, - 1));
                if ($val = $val[substr($step, 0, $cond_start)]) {
                    foreach ($val as $v) {
                        if ($v[$cond[0]] == $cond[1]) {
                            $val = $v;
                            break;
                        }
                    }
                }
            } else {
                $val = $val[$step];
            }
        }
        return $val;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::delete()
     */
    public function delete(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        $method = $this->getHttpMethod(static::OPERATION_DELETE);
        $errorPrefix = 'Cannot delete "' . $this->getMainObject()->getName() . '" (' . $this->getMainObject()->getAliasWithNamespace() . '): ';
        
        
        if ($this->getMainObject()->hasUidAttribute() === true) {
            $uidAttr = $this->getMainObject()->getUidAttribute();
        } else {
            throw new QueryBuilderException($errorPrefix . 'Cannot delete objects without UID attributes via JSON URL builder!');
        }
        
        $uidFilterCallback = function(QueryPartFilter $filter) use ($uidAttr) {
            return $filter->getAttribute()->getDataAddress() === $uidAttr->getDataAddress();
        };
        $uidFilters = $this->getFilters()->getFilters($uidFilterCallback);
        
        if (empty($uidFilters) === true) {
            throw new QueryBuilderException($errorPrefix . 'Deletes are only possible when filtering over UID attributes!');
        }
        
        $cnt = 0;
        if (count($uidFilters) === 1) {
            $uidFilter = $uidFilters[0];
            if ($uidFilter->getComparator() !== ComparatorDataType::IN && $uidFilter->getComparator() !== ComparatorDataType::IS && $uidFilter->getComparator() !== ComparatorDataType::EQUALS) {
                throw new QueryBuilderException($errorPrefix . 'Cannot delete with a filter "' . $uidFilter->getCondition()->toString() . '"');
            }
            
            if (is_array($uidFilter->getCompareValue())) {
                $uids = $uidFilter->getCompareValue();
            } else {
                $uids = explode($uidAttr->getValueListDelimiter(), $uidFilter->getCompareValue());
            }
            
            $urlTpl = $this->buildDataAddressForObject($this->getMainObject(), $method);
            if (count($uids) === 1) {
                $url = $this->replacePlaceholdersInUrl($urlTpl);
                $request = new Request($method, $url);
                $data_connection->query(new Psr7DataQuery($request));
                $cnt++;
            } else {
                foreach ($uids as $uid) {
                    $url = StringDataType::replacePlaceholders($urlTpl, [$uidFilter->getAlias() => $this->buildUrlFilterValue($uidFilter, $uid)]);
                    $request = new Request($method, $url);
                    $data_connection->query(new Psr7DataQuery($request));
                    $cnt++;
                }
            }
        } else {
            throw new QueryBuilderException($errorPrefix . 'Cannot delete from OData source if multiple filters over the UID attribute are used!');
        }
        
        return new DataQueryResultData([], $cnt, true, $cnt);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::parseResponse()
     */
    protected function parseResponse(Psr7DataQuery $query)
    {
        return json_decode($query->getResponse()->getBody(), true);
    }
}
?>