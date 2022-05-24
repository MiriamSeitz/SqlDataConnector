<?php
namespace exface\UrlDataConnector;

use exface\Core\CommonLogic\DataQueries\AbstractDataQuery;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\StreamInterface;
use exface\Core\Widgets\DebugMessage;
use exface\Core\Factories\WidgetFactory;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Interfaces\WorkbenchInterface;
use Psr\Http\Message\MessageInterface;
use exface\Core\Exceptions\RuntimeException;

class Psr7DataQuery extends AbstractDataQuery
{

    private $request;

    private $response;
    
    private $fixedUrl = false;
    
    /**
     * Returns a fully instantiated data query with a PSR-7 request.
     * This is a shortcut for "new Psr7DataQuery(new Request)".
     *
     * @param string $method            
     * @param string|UriInterface $uri            
     * @param array $headers            
     * @param string|StreamInterface $body            
     * @param string $version            
     * @return \exface\UrlDataConnector\Psr7DataQuery
     */
    public static function createRequest($method, $uri, array $headers = [], $body = null, $version = '1.1')
    {
        $request = new Request($method, $uri, $headers, $body, $version);
        return new self($request);
    }

    /**
     * Wraps a PSR-7 request in a data query, which can be used with the HttpDataConnector
     *
     * @param RequestInterface $request            
     */
    public function __construct(RequestInterface $request)
    {
        $this->setRequest($request);
    }

    /**
     *
     * @return \Psr\Http\Message\RequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     *
     * @param RequestInterface $value            
     * @return \exface\UrlDataConnector\Psr7DataQuery
     */
    public function setRequest(RequestInterface $value)
    {
        $this->request = $value;
        return $this;
    }

    /**
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     *
     * @param ResponseInterface $value            
     * @return \exface\UrlDataConnector\Psr7DataQuery
     */
    public function setResponse(ResponseInterface $value)
    {
        $this->response = $value;
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\DataQueries\AbstractDataQuery::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget)
    {
        $page = $debug_widget->getPage();
        
        // Request
        $request_tab = $debug_widget->createTab();
        $request_tab->setCaption('Data-Request');
        try {
            $url = $this->getRequest()->getUri()->__toString();
        } catch (\Throwable $e) {
            $url = 'Unavailable: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
        }
        $request_widget = WidgetFactory::create($page, 'Markdown', $request_tab);
        $request_widget->setValue(<<<MD
## Request URL

[{$url}]({$url})

## Request headers

{$this->generateRequestHeaders()}

## Request body

{$this->generateMessageBody($this->getRequest())}

MD);
        $request_widget->setWidth('100%');
        $request_tab->addWidget($request_widget);
        $debug_widget->addTab($request_tab);
        
        // Response
        $response_tab = $debug_widget->createTab();
        $response_tab->setCaption('Data-Response');
        
        $response_widget = WidgetFactory::create($page, 'Markdown', $response_tab);
        $response_widget->setValue(<<<MD
## Response headers

{$this->generateResponseHeaders()}

## Response body
                
{$this->generateMessageBody($this->getResponse())}

MD);
        $response_widget->setWidth('100%');
        $response_tab->addWidget($response_widget);
        $debug_widget->addTab($response_tab);
        
        return $debug_widget;
    }

    // TODO Translations
    
    /**
     * Generates a HTML-representation of the request-headers.
     * 
     * @param WorkbenchInterface $workbench
     * @return string
     */
    protected function generateRequestHeaders() : string
    {
        if ($this->getRequest() !== null) {
            $requestHeaders = $this->getRequest()->getMethod() . ' ' . $this->getRequest()->getRequestTarget() . ' HTTP/' . $this->getRequest()->getProtocolVersion() . PHP_EOL . PHP_EOL;
            $requestHeaders .= $this->generateMessageHeaders($this->getRequest());
        } else {
            $requestHeaders = 'No HTTP message.';
        }
        
        return $requestHeaders;
    }
    
    /**
     * Generates a HTML-representation of the response-headers.
     * 
     * @param WorkbenchInterface $workbench
     * @return string
     */
    protected function generateResponseHeaders() : string
    {
        if (!is_null($this->getResponse())) {
            $responseHeaders = 'HTTP/' . $this->getResponse()->getProtocolVersion() . ' ' . $this->getResponse()->getStatusCode() . ' ' . $this->getResponse()->getReasonPhrase() . PHP_EOL . PHP_EOL;
            $responseHeaders .= $this->generateMessageHeaders($this->getResponse());
        } else {
            $responseHeaders = 'No HTTP message.';
        }
        
        return $responseHeaders;
    }

    /**
     * Generates a HTML-representation of the request or response headers.
     * 
     * @return string
     */
    protected function generateMessageHeaders(MessageInterface $message = null) : string
    {
        if (! is_null($message)) {
            try {
                $messageHeaders  = "| Header | Value |" . PHP_EOL;
                $messageHeaders .= "| ------ | ----- |" . PHP_EOL;
                foreach ($message->getHeaders() as $header => $values) {
                    foreach ($values as $value) {
                        if (strcasecmp($header, 'Authorization') === 0) {
                            $value = '***';
                        }
                        $messageHeaders .= "| $header | $value |" . PHP_EOL;
                    }
                }
            } catch (\Throwable $e) {
                $messageHeaders = 'Error reading message headers: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
            }
        } else {
            $messageHeaders = 'No HTTP message.';
        }
        
        return $messageHeaders;
    }

    /**
     * Generates a HTML-representation of the request or response body.
     * 
     * @param MessageInterface $message
     * @return string
     */
    protected function generateMessageBody(MessageInterface $message = null) : string
    {
        if (! is_null($message)) {
            try {
                if (is_null($bodySize = $message->getBody()->getSize()) || $bodySize > 1048576) {
                    // Groesse des Bodies unbekannt oder groesser 1Mb.
                    $messageBody = 'Message body is too big to display.';
                } else {
                    $contentType = mb_strtolower($message->getHeader('Content-Type')[0]);
                    
                    switch (true) {
                        case stripos($contentType, 'json') !== false:
                            $jsonPrettified = json_encode(json_decode($message->getBody()->__toString()), JSON_PRETTY_PRINT);
                            $messageBody = <<<MD
                            
```json
{$jsonPrettified}
```
MD;
                            break;
                        case stripos($contentType, 'xml') !== false:
                            $domxml = new \DOMDocument();
                            $domxml->preserveWhiteSpace = false;
                            $domxml->formatOutput = true;
                            $domxml->loadXML($message->getBody());
                            $messageBody = <<<MD

```xml
{$domxml->saveXML()}
```
MD;
                            break;
                        case stripos($contentType, 'html') !== false:
                            $indenter = new \Gajus\Dindent\Indenter();
                            $messageBody = <<<MD
                            
```html
{$indenter->indent($message->getBody())}
```
MD;
                            break;
                        default:
                            $messageBody = <<<MD
                            
```
{$message->getBody()->__toString()}
```
MD;
                            break;
                    }
                }
            } catch (\Throwable $e) {
                $messageBody = 'Error reading message body: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
            }
        } else {
            $messageBody = 'Message empty.';
        }
        
        return $messageBody;
    }
    /**
     * @return boolean
     */
    public function isUriFixed()
    {
        return $this->fixedUrl;
    }

    /**
     * @param boolean $fixedUrl
     * @return Psr7DataQuery
     */
    public function setUriFixed($true_or_false)
    {
        $this->fixedUrl = BooleanDataType::cast($true_or_false);
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\DataQueries\AbstractDataQuery::toString()
     */
    public function toString($prettify = true)
    {
        return $this->getRequest()->getUri()->__toString();
    }

}