<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\DataSources\DataQueryResultDataInterface;
use exface\Core\QueryBuilders\Traits\CsvBuilderTrait;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\DataTypes\IntegerDataType;

/**
 * This is a query builder for CSV-based web services.
 * 
 * ## Data source configuration
 * 
 * To access CSV files via URL, create a data source with this query builder and a connection with the `HttpConnector`.
 * 
 * ## Object data addresses
 * 
 * Object data addresses are URLs to the CSV files. Relative URLs can be used, if a
 * base URL is defined in the data connection.
 * 
 * Additionally all placeholders supported by URL builders are available. See `AbstractUrlBuilder`
 * for more details.
 * 
 * - `[#<attribute_alias>#]` - URLs in object data addresses can include placeholders.
 * These are basically required filters, that must be part of the URL a opposed to
 * regular optional filters based on URL parameters.
 * 
 * ## Attribute data addresses
 * 
 * Attributes are either values from the CSV:
 * 
 * - CSV column number starting with 0 - e.g. `0` for the first column, '1' for the second, etc.
 * - `~row_number` for the current row number (starting with 0, EXCLUDING header rows)
 * 
 * Additionally all placeholders supported by URL builders are available. See `AbstractUrlBuilder`
 * for more details.
 * 
 * - `[#~urlplaceholder:<placeholder_name>#]` - the current value of a placeholder
 * used in the URL (= data address of the object). For example, if the object has
 * `https://www.github.com/[#vendor#]/` as data address, an attribute can have
 * the placeholder `[#~urlplaceholder:vendor#]` as data address, which will be
 * replace by the same value. Thus, our attribute will get the value `exface` in
 * a query to `https://www.github.com/exface/`. This feature is very usefull in
 * web services, that do not return values of URL parameters in their response.
 * 
 * - `[#~urlparam:<parameter>#]` - the value of an URL parameter in the current request.
 * E.g. in a URL `http://mydomain/resource?param1=val1&param2=val2` the placeholders
 * `[~urlparam:param1]` and `[~urlparam:param2]` can be used to get `val1` and `val2`
 * respectively. This is similar to `[#~urlplaceholder:<placeholder_name>#]`, but
 * can be used for any URL parameters - even those produced by filters, etc. On the
 * other hand, `[#~urlplaceholder:<placeholder_name>#]` can be used to get any placeholder
 * used, not only query parameters following the `?`.
 * 
 * ## Examples
 * 
 * ### Read multiple CSV by file names
 * 
 * Assume, you have a server with multiple CSV files available at `http://myserver.com/data/`: `file1.csv`, 
 * `file2.csv`, etc. with a common structure. You can read all the files into a single data sheet with an
 * IN-filter containing the required file names using the following configuration
 * 
 * - object data address: `http://myserver.com/data/[#filename#].csv`
 * - attributes
 *      - Filename
 *          - Alias: `filename`
 *          - Data address: `[#~urlplaceholder:filename#]`
 *          - Data address options: `{"filter_locally":false,"filter_remote_split_value_lists":true}`
 *      - First CSV column
 *          - Alias: `col1`
 *          - Data address: `0`
 *          
 * Note the placeholder `[#filename#]` in the URL - it will be replaced by the value(s) of the filter over
 * the attribute `filename` making filtering over filename mandatory. Since the CSV file itself does not
 * contain its filename, the attribute `filename` also uses the value of the mandatory filter. However,
 * we need to disable local filtering (because the remote data will not contain any values!) and also
 * tell the query builder to fetch each file with an individual request in case there are multiple values
 * passed through the filter.
 *
 * @see AbstractUrlBuilder for common URL configuration
 * 
 * @author Andrej Kabachnik
 *        
 */
class CsvUrlBuilder extends AbstractUrlBuilder
{
    use CsvBuilderTrait;
    
    /**
     * Delimiter between row values - defaults to `,` (comma)
     *
     * @uxon-property csv_delimiter
     * @uxon-target object
     * @uxon-type string
     * @uxon-default ,
     */
    const DAP_CSV_DELIMITER = 'csv_delimiter';
    
    /**
     * Enclosing character for strings - defaults to `"` (double quotes)
     *
     * @uxon-property csv_enclosure
     * @uxon-target object
     * @uxon-type string
     * @uxon-default '
     */
    const DAP_CSV_ENCLOSURE = 'csv_enclosure';
    
    /**
     * Specifies if the number of rows used as headers - defaults to 0
     *
     * @uxon-property csv_header_rows
     * @uxon-target object
     * @uxon-type int
     * @uxon-default 0
     */
    const DAP_CSV_HEADER_ROWS = 'csv_header_rows';
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::read()
     */
    public function read(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        $this->connection = $data_connection;
        return parent::read($data_connection);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildResultRows()
     */
    protected function buildResultRows($parsed_data, Psr7DataQuery $query)
    {
        $result = $this->readCsv($query, [], true);
        $resultRows = $result->getResultRows();
        $resultRows = $this->buildResultRowsResolvePlaceholders($resultRows, $query);
        return $resultRows;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::getHttpHeaders()
     */
    protected function getHttpHeaders(string $operation) : array
    {
        return ['Accept' => 'text/csv'];
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::parseResponse()
     */
    protected function parseResponse(Psr7DataQuery $query)
    {
        return $query->getResponse()->getBody()->__toString();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::findRowCounter()
     */
    protected function findRowCounter($data, Psr7DataQuery $query)
    {
        return $this->countCsvRows($query);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::findFieldInData()
     */
    protected function findFieldInData($data_address, $data)
    {
        return $data;
    }
    
    /**
     *
     * @see CsvBuilderTrait::getDelimiter()
     */
    protected function getDelimiter(MetaObjectInterface $object) : string
    {
        return $object->getDataAddressProperty(self::DAP_CSV_DELIMITER) ?? ',';
    }
    
    /**
     *
     * @see CsvBuilderTrait::getEnclosure()
     */
    protected function getEnclosure(MetaObjectInterface $object) : string
    {
        return $object->getDataAddressProperty(self::DAP_CSV_ENCLOSURE) ?? '"';
    }
    
    /**
     *
     * @see CsvBuilderTrait::getHeaderRowsNumber()
     */
    protected function getHeaderRowsNumber(MetaObjectInterface $object) : int
    {
        return IntegerDataType::cast($object->getDataAddressProperty(self::DAP_CSV_HEADER_ROWS)) ?? 0;
    }
}