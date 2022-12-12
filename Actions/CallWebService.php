<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use GuzzleHttp\Psr7\Request;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\Actions\iCallService;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Factories\DataSheetFactory;
use Psr\Http\Message\ResponseInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\DataSources\DataSourceInterface;
use exface\Core\Factories\DataSourceFactory;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\CommonLogic\Constants\Icons;
use exface\UrlDataConnector\Exceptions\HttpConnectorRequestError;
use exface\Core\Interfaces\Exceptions\AuthenticationExceptionInterface;
use exface\Core\Exceptions\Security\AuthenticationFailedError;
use exface\UrlDataConnector\DataConnectors\HttpConnector;

/**
 * Calls a web service using parameters to fill placeholders in the URL and body of the HTTP request.
 * 
 * This action will send an HTTP request for every row of the input data. The action model allows 
 * to customize common HTTP request properties: `url`, `method`, `body`, `headers`.
 * 
 * The `url` and the `body` can be templates with placeholders. These will be automatically treated as
 * action parameters and will get filled with input data when the action is performed. Placeholders
 * must match column names here! 
 * 
 * Alternatively, you can use `parameters` and let the action generate URL params and body automatically. 
 * Parameters are much more flexible than simple placeholders because they can have data types, default
 * values, required flags, etc. However, only certian body content types can be generated automatically: 
 * `application/json` and `application/x-www-form-urlencoded`.
 * 
 * You can also mix both approaches: define a parameter with the name of a placehodler an you will will
 * be able to control the data type of the placeholder, etc.
 * 
 * ## Parameters
 * 
 * Each parameter defines a possible input value of the action. Parameters have unique names and always
 * belong to one of these groups: `url` parameters and `body` parameters. The name of a parameter must
 * match a column name (it is not always the same as an attribute alias!) in the actions input.
 * 
 * If the `group` of a parameter is ommitted, it will depend on the request method: parameters of 
 * GET-requests are treated as URL-parameters, while POST-parameters will be placed in the body.
 * 
 * In contrast to placehodlers, parameters allow customization like setting a data type, being required
 * and optional, etc.
 * 
 * ## Placeholders
 * 
 * Placeholders can be used anywhere in the URL or the body. If there is no parameter with the same
 * name defined, the placeholder will be treated as a simple string parameter of the respective group.
 * 
 * You may say, placeholders are a short and explicit way to define parameters.
 * 
 * Complex request bodies may require both: placeholders and parameters with the same name.
 * 
 * ## Action result
 * 
 * The result of `CallWebservice` consists of a messsage and a data sheet. The data sheet is based
 * on the actions object and will be empty by default. However, more specialized actions like
 * `CallOData2Operation` may also yield meaningful data.
 * 
 * In the most generic case, you can use the following action properties to extract a result message
 * from the HTTP response:
 * 
 * - `result_message_pattern` - a regular expression to extract the result message from
 * the response - see examples below.
 * - `result_message_text` - a text or a static formula (e.g. `=TRANSLATE()`) to be
 * displayed if no errors occur. 
 * - If `result_message_text` and `result_message_pattern` are both specified, the static
 * text will be prepended to the extracted result. This is usefull for web services, that
 * respond with pure data - e.g. an importer serves, that returns the number of items imported.
 * 
 * ## Error messages
 * 
 * Similarly, you can make make the action look for error messages in the HTTP response
 * if the web service produces informative.
 * 
 * - `error_message_pattern` - a regular expression to find the error message (this will
 * make this error message visible to the users!)
 * - `error_code_pattern` - a regular expression to find the error code (this will
 * make this error code visible to the users!)
 * 
 * ## Examples
 * 
 * ### Simple GET-request with placeholders 
 * 
 * The service returns the following JSON if successfull: `{"result": "Everything OK"}`.
 * 
 * ```
 *  {
 *      "url": "http://url.toyouservice.com/service?param1=[#param1_data_column#]",
 *      "result_message_pattern": "/\"result":"(?<message>[^"]*)\"/i"
 *  }
 * 
 * ```
 * 
 * The placeholder `[#param1_attribute_alias#]` in the URL will be automatically
 * transformed into a required service parameter, so we don't need to define any
 * `parameters` manually. When the action is performed, the system will look for
 * a data column named `param1_data_column` and use it's values to replace the
 * placeholder. If no such column is there, an error will be raised. 
 * 
 * The `result_message_pattern` will be used to extract the success message from 
 * the response body (i.e. "Everything OK"), that will be shown to the user once 
 * the service responds.
 * 
 * ### GET-request with typed and optional parameters
 * 
 * If you need optional URL parameters or require type checking, you can use the
 * `parameters` property of the action to add detailed information about each
 * parameter: in particular, it's data type.
 * 
 * Compared to the first example, the URL here does not have any placeholders.
 * Instead, there is the parameter `param1`, which will produce `&param1=...`
 * in the URL. The value will be expected in the input data column named `param1`.
 * You can use an `input_mapper` in the action's configuration to map a column
 * with a different name to `param1`.
 * 
 * The second parameter is optional and will only be appended to the URL if
 * the input data contains a matching column with non-empty values.
 * 
 * ```
 * {
 *  "url": "http://url.toyouservice.com/service",
 *  "result_message_pattern": "/\"result":"(?<message>[^"]*)\"/i",
 *  "parameters": [
 *      {
 *          "name": "param1",
 *          "required": true,
 *          "data_type": {
 *              "alias": "exface.Core.Integer"
 *          }
 *      },{
 *          "name": "mode",
 *          "data_type": {
 *              "alias": "exface.Core.GenericStringEnum",
 *              "values": {
 *                  "mode1": "Mode 1",
 *                  "mode2": "Mode 2"
 *              }
 *          }
 *      }
 *  ]
 * }
 * 
 * ```
 * 
 * You can even mix placeholders and explicitly defined parameters. In this case, if no parameter
 * name matches a placeholder's name, a new simple string parameter will be generated
 * automatically.
 * 
 * ### POST-request with a JSON body-template
 * 
 * Similarly to URLs in GET-requests, placeholders can be used in the body of a POST request. 
 * 
 * The following code shows a POST-version of the first GET-example above.
 * 
 * ```
 *  {
 *      "url": "http://url.toyouservice.com/service",
 *      "result_message_pattern": "/\"result":"(?<message>[^"]*)\"/i",
 *      "method": "POST",
 *      "content_type": "application/json",
 *      "body": "{"data": {\"param1\": \"[#param1_data_column#]\"}}"
 *  }
 * 
 * ```
 * 
 * Note the extra `content_type` property: this is the same as setting a `Content-Type` header in the
 * request. Most web services will require such a header, so it is a good idea to set it in the action's 
 * configuration. You can also use the `headers` property for even more customization.
 * 
 * The more detailed `parameters` definition can be used with templated POST requests too - just make sure,
 * the placeholder names in the template match parameter names. However, placeholders, that are not in the 
 * `parameters` list will be ignored here because the action cannot know where to put the in the template.
 * 
 * ### POST-request with parameters and a generated form data body
 * 
 * An alternative to the use of `body` templates is to have the body generated from parameters. This only
 * works for content types `application/x-www-form-urlencoded` and `application/json`. In this
 * case you can define required and optional parameters and the correspoinding fields of the body will
 * appear accordingly.
 * 
 * POST requests may have placeholders in the body and in the URL at the same time. The corresponding parameters
 * will belong to respective groups `url` and `body` then. Thus, you can explicitly control, which part of
 * the request a parameter is meant for.
 * 
 * ```
 * {
 *  "url": "http://url.toyouservice.com/[#endpoint#]",
 *  "result_message_pattern": "\"result":"(?<message>[^"]*)\"",
 *  "content_type": "application/x-www-form-urlencoded",
 *  "parameters": [
 *      {
 *          "name": "endpoint",
 *          "group": "url"
 *      },{
 *          "name": "param1",
 *          "group": "body",
 *          "required": true,
 *          "data_type": {
 *              "alias": "exface.Core.Integer"
 *          }
 *      },{
 *          "name": "mode",
 *          "group": "body",
 *          "data_type": {
 *              "alias": "exface.Core.GenericStringEnum",
 *              "values": {
 *                  "mode1": "Mode 1",
 *                  "mode2": "Mode 2"
 *              }
 *          }
 *      }
 *  ]
 * }
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 *
 */
class CallWebService extends AbstractAction implements iCallService 
{
    const PARAMETER_GROUP_BODY = 'body';
    
    const PARAMETER_GROUP_URL = 'url';
    
    /**
     * @var ServiceParameterInterface[]
     */
    private $parameters = [];
    
    /**
     * @var bool
     */
    private $parametersGeneratedFromPlaceholders = false;
    
    /**
     * @var string|NULL
     */
    private $url = null;
    
    /**
     * @var string|NULL
     */
    private $method = null;
    
    /**
     * Array of HTTP headers with lowercased header names
     * 
     * @var string[]
     */
    private $headers = [];
    
    private $contentType = null;
    
    /**
     * @var string|NULL
     */
    private $body = null;
    
    /**
     * @var string|DataSourceInterface|NULL
     */
    private $dataSource = null;

    /**
     * @var string|NULL
     */
    private $resultMessagePattern = null;
    
    private $errorMessagePattern = null;
    
    private $errorCodePattern = null;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Actions\ShowWidget::init()
     */
    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::COGS);
    }

    /**
     * 
     * @return string|NULL
     */
    protected function getUrl() : ?string
    {
        return $this->url;
    }

    /**
     * The URL to call: absolute or relative to the data source - supports [#placeholders#].
     * 
     * Any `parameters` with group `url` will be appended to the URL automatically. If there
     * are parameters without a group, they will be treated as URL parameters for request
     * methods, that typically do not have a body - e.g. `GET` and `OPTIONS`.
     * 
     * @uxon-property url
     * @uxon-type uri
     * 
     * @param string $url
     * @return CallWebService
     */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * 
     * @return string
     */
    protected function getMethod($default = 'GET') : string
    {
        return $this->method ?? $default;
    }

    /**
     * The HTTP method: GET, POST, etc.
     * 
     * @uxon-property method
     * @uxon-type [GET,POST,PUT,PATCH,DELETE,OPTIONS,HEAD,TRACE]
     * @uxon-default GET
     * 
     * @param string
     */
    public function setMethod(string $method) : CallWebService
    {
        $this->method = $method;
        return $this;
    }

    /**
     * 
     * @return array
     */
    protected function getHeaders() : array
    {
        return $this->headers;
    }
    
    /**
     * 
     * @return array
     */
    protected function buildHeaders() : array
    {
        $headers = $this->getHeaders();
        
        if ($this->getContentType() !== null) {
            $headers['content-type'] = $this->getContentType();
        }
        
        return $headers;
    }

    /**
     * Special HTTP headers to be sent: these headers will override the defaults of the data source.
     * 
     * @uxon-property headers
     * @uxon-type object
     * @uxon-template {"Content-Type": ""}
     * 
     * @param UxonObject|array $uxon_or_array
     */
    public function setHeaders($uxon_or_array) : CallWebService
    {
        if ($uxon_or_array instanceof UxonObject) {
            $this->headers = $uxon_or_array->toArray(CASE_LOWER);
        } elseif (is_array($uxon_or_array)) {
            $this->headers = $uxon_or_array;
        } else {
            throw new ActionConfigurationError($this, 'Invalid format for headers property of action ' . $this->getAliasWithNamespace() . ': expecting UXON or PHP array, ' . gettype($uxon_or_array) . ' received.');
        }
        return $this;
    }
    
    /**
     * Populates the request body with parameters from a given row by replaces body placeholders 
     * (if a body-template was specified) or creating a body according to the content type.
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @return string
     */
    protected function buildBody(DataSheetInterface $data, int $rowNr) : string
    {
        $body = $this->getBody();
        
        if ($body === null) {
            if ($this->getDefaultParameterGroup() === self::PARAMETER_GROUP_BODY) {
                return $this->buildBodyFromParameters($data, $rowNr);
            } else {
                return '';
            }
        }
        
        $placeholders = StringDataType::findPlaceholders($body);
        if (empty($placeholders) === true) {
            return $body;
        }
        
        $requiredParams = [];
        foreach ($placeholders as $ph) {
            $requiredParams[] = $this->getParameter($ph);
        }
        
        $phValues = [];
        foreach ($requiredParams as $param) {
            $name = $param->getName();
            $val = $data->getCellValue($name, $rowNr);
            $val = $this->prepareParamValue($param, $val) ?? '';
            $phValues[$name] = $val;
        }
        
        return StringDataType::replacePlaceholders($body, $phValues);
    }
    
    /**
     * Returns the request body built from service parameters according to the content type.
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @return string
     */
    protected function buildBodyFromParameters(DataSheetInterface $data, int $rowNr) : string
    {
        $str = '';
        $contentType = $this->getContentType();
        switch (true) {
            case stripos($contentType, 'json') !== false:
                $params = [];
                foreach ($this->getParameters() as $param) {
                    if ($param->getGroup($this->getDefaultParameterGroup()) !== self::PARAMETER_GROUP_BODY) {
                        continue;
                    }
                    $name = $param->getName();
                    $val = $data->getCellValue($name, $rowNr);
                    $val = $this->prepareParamValue($param, $val) ?? '';
                    $params[$name] = $val;
                }
                $str = json_encode($params);
                break;
            case strcasecmp($contentType, 'application/x-www-form-urlencoded') === 0:
                foreach ($this->getParameters() as $param) {
                    if ($param->getGroup($this->getDefaultParameterGroup()) !== self::PARAMETER_GROUP_BODY) {
                        continue;
                    }
                    $name = $param->getName();
                    $val = $data->getCellValue($name, $rowNr);
                    $val = $this->prepareParamValue($param, $val) ?? '';
                    $str .= '&' . urlencode($name) . '=' . urlencode($val);
                }
                break;
        }
        return $str;
    }

    /**
     * 
     * @return string
     */
    protected function getBody() : ?string
    {
        return $this->body;
    }

    /**
     * The body of the HTTP request - [#placeholders#] are supported.
     * 
     * If no body template is specified, the body will be generated automatically for
     * content types `application/json` and `application/x-www-form-urlencoded` - this
     * autogenerated body will contain all parameters, that belong to the `body` group.
     * If there are parameters without a group, they will be treated as URL parameters 
     * for request methods, that typically do not have a body - e.g. `GET` and `OPTIONS`.
     * 
     * @uxon-property body
     * @uxon-type string
     * 
     * @param string $body
     * @return $this;
     */
    public function setBody($body) : CallWebService
    {
        $this->body = $body;
        return $this;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction): ResultInterface
    {
        $input = $this->getInputDataSheet($task);
        
        $resultData = DataSheetFactory::createFromObject($this->getResultObject());
        $resultData->setAutoCount(false);
        
        $rowCnt = $input->countRows();
        if ($rowCnt === 0 && $this->getInputRowsMin() === 0) {
            $rowCnt = 1;
        }
        
        // Make sure all required parameters are present in the data
        $input = $this->getDataWithParams($input, $this->getParameters());  
        
        // Call the webservice for every row in the input data.
        $httpConnection = $this->getDataConnection();
        for ($i = 0; $i < $rowCnt; $i++) {
            $request = new Request($this->getMethod(), $this->buildUrl($input, $i), $this->buildHeaders(), $this->buildBody($input, $i));
            $query = new Psr7DataQuery($request);
            // Perform the query regularly via URL connector
            $response = $httpConnection->query($query)->getResponse();
        }
        
        $resultData = $this->parseResponse($response, $resultData);
        $resultData->setCounterForRowsInDataSource($resultData->countRows());
        
        // If the input and the result are based on the same meta object, we can (and should!)
        // apply filters and sorters of the input to the result. Indeed, having the same object
        // merely means, we need to fill the sheet with data, which, of course, should adhere
        // to its settings.
        if ($input->getMetaObject()->is($resultData->getMetaObject())) {
            if ($input->getFilters()->isEmpty(true) === false) {
                $resultData = $resultData->extract($input->getFilters());
            }
            if ($input->hasSorters() === true) {
                $resultData->sort($input->getSorters());
            }
        }
        
        if ($this->getResultMessageText() && $this->getResultMessagePattern()) {
            $respMessage = $this->getResultMessageText() . $this->getMessageFromResponse($response);
        } else {
            $respMessage = $this->getResultMessageText() ?? $this->getMessageFromResponse($response);
        }
        
        if ($respMessage === null || $respMessage === '') {
            $respMessage = $this->getWorkbench()->getApp('exface.UrlDataConnector')->getTranslator()->translate('ACTION.CALLWEBSERVICE.DONE');
        }
        
        return ResultFactory::createDataResult($task, $resultData, $respMessage);
    }
    
    /**
     * 
     * @return HttpConnectionInterface
     */
    protected function getDataConnection() : HttpConnectionInterface
    {
        if ($this->dataSource !== null) {
            if (! $this->dataSource instanceof DataSourceInterface) {
                $this->dataSource = DataSourceFactory::createFromModel($this->getWorkbench(), $this->dataSource);
            }
            $conn = $this->dataSource->getConnection();
        } else {
            $conn = $this->getMetaObject()->getDataConnection();
        }
        // If changes to the connection config are needed, clone the connection before
        // applying them!
        if ($this->errorMessagePattern !== null || $this->errorCodePattern !== null) {
            if (! ($conn instanceof HttpConnector)) {
                throw new ActionConfigurationError($this, 'Cannot use a custom `error_message_pattern` or `error_code_pattern` with data connection "' . $conn->getAliasWithNamespace() . '"!');
            }
            $conn = clone($conn);
            if ($this->errorMessagePattern !== null) {
                $conn->setErrorTextPattern($this->errorMessagePattern);
            }
            if ($this->errorCodePattern !== null) {
                $conn->setErrorCodePattern($this->errorCodePattern);
            }
        }
        return $conn;
    }
    
    /**
     * Use this the connector of this data source to call the web service.
     * 
     * If the data source is not specified directly via `data_source_alias`, the data source
     * of the action's meta object will be used.
     * 
     * @uxon-property data_source_alias
     * @uxon-type metamodel:data_source
     * 
     * @param string $idOrAlias
     * @return CallWebService
     */
    public function setDataSourceAlias(string $idOrAlias) : CallWebService
    {
        $this->dataSource = $idOrAlias;
        return $this;
    }
    
    /**
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @return string
     */
    protected function buildUrl(DataSheetInterface $data, int $rowNr) : string
    {
        $url = $this->getUrl() ?? '';
        $params = '';
        $urlPlaceholders = StringDataType::findPlaceholders($url);
        
        $urlPhValues = [];
        foreach ($this->getParameters() as $param) {
            if ($param->getGroup($this->getDefaultParameterGroup()) !== null && $param->getGroup($this->getDefaultParameterGroup()) !== self::PARAMETER_GROUP_URL) {
                continue;
            }
            $name = $param->getName();
            $val = $data->getCellValue($name, $rowNr);
            $val = $this->prepareParamValue($param, $val) ?? '';
            if (in_array($param->getName(), $urlPlaceholders) === true) {
                $urlPhValues[$name] = $val;
            } else {
                $params .= '&' . urlencode($name) . '=' . urlencode($val);
            }
        }
        if (empty($urlPhValues) === false) {
            $url = StringDataType::replacePlaceholders($url, $urlPhValues);
        }
        
        return $url . (strpos($url, '?') === false ? '?' : '') . $params;
    }
    
    /**
     * 
     * @param DataSheetInterface $data
     * @return DataSheetInterface
     */
    protected function getDataWithParams(DataSheetInterface $data, array $parameters) : DataSheetInterface
    {
        foreach ($parameters as $param) {
            if (! $data->getColumns()->get($param->getName())) {
                if ($data->getMetaObject()->hasAttribute($param->getName()) === true) {
                    if ($data->hasUidColumn(true) === true) {
                        $attr = $data->getMetaObject()->getAttribute($param->getName());
                        $data->getColumns()->addFromAttribute($attr);
                    }
                }
            }
        }
        if ($data->isFresh() === false && $data->hasUidColumn(true)) {
            $data->getFilters()->addConditionFromColumnValues($data->getUidColumn());
            $data->dataRead();
        }
        return $data;
    }
    
    /**
     * 
     * @param ServiceParameterInterface $parameter
     * @param mixed $val
     * @return mixed
     */
    protected function prepareParamValue(ServiceParameterInterface $parameter, $val)
    {
        if ($parameter->hasDefaultValue() === true && $val === null) {
            $val = $parameter->getDefaultValue();
        }
        
        if ($parameter->isRequired() && $parameter->getDataType()->isValueEmpty($val)) {
            throw new ActionInputMissingError($this, 'Value of required parameter "' . $parameter->getName() . '" not set! Please include the corresponding column in the input data or use an input_mapper!', '75C7YOQ');
        }
        
        return $parameter->getDataType()->parse($val);
    }
    
    /**
     *
     * @return ServiceParameterInterface[]
     */
    public function getParameters(string $group = null) : array
    {
        if ($this->parametersGeneratedFromPlaceholders === false) {
            $this->parametersGeneratedFromPlaceholders = true;
            $bodyPhs = StringDataType::findPlaceholders($this->getBody());
            $urlPhs = StringDataType::findPlaceholders($this->getUrl());
            $phs = array_merge($urlPhs, $bodyPhs);
            foreach ($phs as $ph) {
                try {
                    $this->getParameter($ph);
                } catch (ActionInputMissingError $e) {
                    $this->parameters[] = new ServiceParameter($this, new UxonObject([
                        "name" => $ph,
                        "required" => true, 
                        "group" => in_array($ph, $urlPhs) ? self::PARAMETER_GROUP_URL : self::PARAMETER_GROUP_BODY 
                    ]));
                }
            }
        }
        return $this->parameters;
    }
    
    /**
     * Defines parameters supported by the service.
     *
     * @uxon-property parameters
     * @uxon-type \exface\Core\CommonLogic\Actions\ServiceParameter[]
     * @uxon-template [{"name": ""}]
     *
     * @param UxonObject $value
     * @return CallWebService
     */
    public function setParameters(UxonObject $uxon) : CallWebService
    {
        foreach ($uxon as $paramUxon) {
            $this->parameters[] = new ServiceParameter($this, $paramUxon);
        }
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCallService::getParameter()
     */
    public function getParameter(string $name) : ServiceParameterInterface
    {
        foreach ($this->getParameters() as $arg) {
            if ($arg->getName() === $name) {
                return $arg;
            }
        }
        throw new ActionInputMissingError($this, 'Parameter "' . $name . '" not found in action "' . $this->getAliasWithNamespace() . '"!');
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCallService::getServiceName()
     */
    public function getServiceName() : string
    {
        return $this->getUrl();
    }
    
    /**
     * 
     * @param ResponseInterface $response
     * @param DataSheetInterface $resultData
     * @return DataSheetInterface
     */
    protected function parseResponse(ResponseInterface $response, DataSheetInterface $resultData) : DataSheetInterface
    {
        return $resultData;
    }
    
    /**
     * 
     * @return MetaObjectInterface
     */
    protected function getResultObject() : MetaObjectInterface
    {
        if ($this->hasResultObjectRestriction()) {
            return $this->getResultObjectExpected();
        }
        return $this->getMetaObject();
    }
    
    /**
     *
     * @return string|NULL
     */
    protected function getResultMessagePattern() : ?string
    {
        return $this->resultMessagePattern;
    }
    
    /**
     * A regular expression to retrieve the result message from the body - the first match is returned or one explicitly named "message".
     * 
     * Extracts a result message from the response body.
     * 
     * For example, if the web service would return the following JSON
     * `{"result": "Everything OK"}`, you could use this regex to get the
     * message: `/"result":"(?<message>[^"]*)"/`.
     * 
     * @uxon-property result_message_pattern
     * @uxon-type string
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setResultMessagePattern(string $value) : CallWebService
    {
        $this->resultMessagePattern = $value;
        return $this;
    }
    
    /**
     * 
     * @param ResponseInterface $response
     * @return string|NULL
     */
    protected function getMessageFromResponse(ResponseInterface $response) : ?string
    {
        $body = $response->getBody()->__toString();
        if ($this->getResultMessagePattern() === null) {
            return $body;
        }
        
        $matches = [];
        preg_match($this->getResultMessagePattern(), $body, $matches);
        
        if (empty($matches)) {
            return null;
        }
        $msg = $matches['message'] ?? $matches[1];
        //remove escaping characters
        $msg = stripcslashes($msg);
        return $msg;
    }
    
    /**
     * 
     * @return string
     */
    public function getContentType() : ?string
    {
        return $this->contentType ?? ($this->headers['content-type'] ?? null);
    }
    
    /**
     * Set the content type for the request.
     * 
     * @uxon-property content_type
     * @uxon-type [application/x-www-form-urlencoded,application/json,text/plain,application/xml]
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setContentType(string $value) : CallWebService
    {
        $this->contentType = trim($value);
        return $this;
    }
    
    /**
     * Use a regular expression to extract messages from error responses - the first match is returned or one explicitly named "message".
     * 
     * This works the same, as `error_text_pattern` of an `HttpConnector`, but allows
     * to override the configuration for this single action.
     * 
     * @uxon-property error_message_pattern
     * @uxon-type string
     * @uxon-template /"error":"([^"]*)"/
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setErrorMessagePattern(string $value) : CallWebService
    {
        $this->errorMessagePattern = $value;
        return $this;
    }
    
    /**
     * Use a regular expression to extract error codes from error responses - the first match is returned or one explicitly named "code".
     * 
     * This works the same, as `error_code_pattern` of an `HttpConnector`, but allows
     * to override the configuration for this single action.
     * 
     * @uxon-property error_code_pattern
     * @uxon-type string
     * @uxon-template /"errorCode":"([^"]*)"/
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setErrorCodePattern(string $value) : CallWebService
    {
        $this->errorMessageCode = $value;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::getEffects()
     */
    public function getEffects() : array
    {
        return array_merge(parent::getEffects(), $this->getEffectsFromModel());
    }
    
    /**
     * 
     * @return string
     */
    protected function getDefaultParameterGroup() : string
    {
        $m = mb_strtoupper($this->getMethod());
        return $m === 'GET' || $m === 'OPTIONS' ? self::PARAMETER_GROUP_URL : self::PARAMETER_GROUP_BODY;
    }
}