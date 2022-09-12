<?php
namespace exface\UrlDataConnector\DataConnectors\Authentication;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\CommonLogic\Security\AuthenticationToken\UsernamePasswordAuthToken;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\DataSources\DataConnectionConfigurationError;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Interfaces\Selectors\UserSelectorInterface;
use exface\Core\Interfaces\Security\PasswordAuthenticationTokenInterface;
use exface\UrlDataConnector\DataConnectors\HttpConnector;
use exface\UrlDataConnector\CommonLogic\AbstractHttpAuthenticationProvider;
use exface\Core\Exceptions\Security\AuthenticationFailedError;
use exface\Core\CommonLogic\Model\Expression;
use exface\Core\Factories\ExpressionFactory;

/**
 * HTTP authentication based on TLS certificates in PEM format
 * 
 * The certificate MUST be stored on the local file system - e.g. in the `data/` folder.
 * 
 * @author Andrej Kabachnik
 *
 */
class TLSCertificateAuth extends AbstractHttpAuthenticationProvider
{    
    private $pemPath = null;
    
    private $passphrase = null;
    
    /**
     *
     * @var ?string
     */
    private $authentication_url = null;
    
    /**
     *
     * @var string
     */
    private $authentication_request_method = 'GET';
    
    /**
     * Set the authentication url.
     *
     * @uxon-property authentication_url
     * @uxon-type string
     *
     * @param string $string
     * @return HttpBasicAuth
     */
    public function setAuthenticationUrl(string $string) : HttpCertAuth
    {
        $this->authentication_url = $string;
        return $this;
    }
    
    protected function getAuthenticationUrl() : ?string
    {
        return $this->authentication_url;
    }
    
    /**
     * Set the authentication request method. Default is 'GET'.
     *
     * @uxon-property authentication_request_method
     * @uxon-type [GET,POST,CONNECT,HEAD,OPTIONS]
     *
     * @param string $string
     * @return HttpBasicAuth
     */
    public function setAuthenticationRequestMethod(string $string) : HttpCertAuth
    {
        $this->authentication_request_method = $string;
        return $this;
    }
    
    /**
     * Returns the HTTP method for a dedicated authentication request (GET by default).
     * 
     * @return string|NULL
     */
    protected function getAuthenticationRequestMethod() : ?string
    {
        return $this->authentication_request_method;
    }
    
    /**
     * 
     * @return string
     */
    protected function getPemPath() : string
    {
        return $this->pemPath;
    }
    
    /**
     * Path to the PEM certificate relative to the current installation folder
     * 
     * @uxon-property pem_path
     * @uxon-type string
     * @uxon-required true
     * @uxon-template docs/.cert/
     * 
     * @param string $value
     * @return TLSCertificateAuth
     */
    public function setPemPath(string $value) : HttpCertAuth
    {
        $this->pemPath = $value;
        return $this;
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getPassphrase() : ?string
    {
        return $this->passphrase;
    }
    
    /**
     * Passphrased/password for the certificate if required
     * 
     * @uxon-property passphrase
     * @uxon-type password
     * 
     * @param string $value
     * @return TLSCertificateAuth
     */
    public function setPassphrase(string $value) : HttpCertAuth
    {
        $this->passphrase = $value;
        return $this;
    }
    
    /**
     * Returns the Guzzle options array for a dedicated authentication request.
     * 
     * E.g. ["cert" => ["path", "password"]] for basic HTTP authentication
     * 
     * @param array $defaultOptions
     * @param PasswordAuthenticationTokenInterface $token
     * @return array
     */
    protected function getAuthenticationRequestOptions(array $defaultOptions, PasswordAuthenticationTokenInterface $token) : array
    {
        $options = $defaultOptions;
        
        // Basic authentication
        $options['cert'] = [
            $this->getPemPath(),
            $this->getPassphrase()
        ];
        
        return $options;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\AuthenticationProviderInterface::authenticate()
     */
    public function authenticate(AuthenticationTokenInterface $token) : AuthenticationTokenInterface
    {
        if (! $token instanceof UsernamePasswordAuthToken) {
            throw new InvalidArgumentException('Invalid token class "' . get_class($token) . '" for authentication via data connection "' . $this->getAliasWithNamespace() . '" - only "UsernamePasswordAuthToken" and derivatives supported!');
        }
        
        $url = $this->getAuthenticationUrl() ?? $this->getConnection()->getUrl();
        if (! $url) {
            throw new DataConnectionConfigurationError($this, "Cannot perform authentication in data connection '{$this->getName()}'! Either provide authentication_url or a general url in the connection configuration.");
        }
        
        $password = $token->getPassword();
        $passwordRequired = $this->isPasswordRequired();
        if ($passwordRequired && ($password === null || $password === '')) {
            throw new AuthenticationFailedError($this->getConnection(), 'No username/password provided for data connection ' . $this->getConnection()->getName() . '!');
        }
        
        // Disable password requirement to let the auth-request pass through.
        $this->setPasswordRequired(false);
        try {
            $request = new Request($this->getAuthenticationRequestMethod(), $url);
            $this->getConnection()->sendRequest($request, $this->getAuthenticationRequestOptions([], $token));
        } catch (\Throwable $e) {
            throw $e;
        }
        $this->setPasswordRequired($passwordRequired);
        
        return $token;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface::getCredentialsUxon()
     */
    public function getCredentialsUxon(AuthenticationTokenInterface $authenticatedToken) : UxonObject
    {
        return new UxonObject([
            'authentication' => [
                'class' => '\\' . get_class($this),
                $this->getPemPath(),
                $this->getPassphrase()
            ]
        ]);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface::getDefaultRequestOptions()
     */
    public function getDefaultRequestOptions(array $defaultOptions): array
    {
        $options = $defaultOptions;
        
        $options['auth'] = [
            $this->getPemPath(),
            $this->getPassphrase()
        ];
        
        return $options;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\AuthenticationProviderInterface::createLoginWidget()
     */
    public function createLoginWidget(iContainOtherWidgets $container): iContainOtherWidgets
    {
        return $container;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface::signRequest()
     */
    public function signRequest(RequestInterface $request): RequestInterface
    {
        return $request;
    }

}