<?php
namespace exface\UrlDataConnector\DataConnectors\Authentication;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\Exceptions\InvalidArgumentException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Interfaces\Selectors\UserSelectorInterface;
use exface\Core\Interfaces\Security\PasswordAuthenticationTokenInterface;
use exface\UrlDataConnector\CommonLogic\AbstractHttpAuthenticationProvider;
use exface\Core\Exceptions\Security\AuthenticationFailedError;
use exface\Core\DataTypes\FilePathDataType;
use exface\UrlDataConnector\DataTypes\SSLCertificateFormatDataType;
use exface\UrlDataConnector\CommonLogic\Security\AuthenticationToken\SSLCertificateAuthToken;
use exface\Core\Exceptions\Security\AuthenticatorConfigError;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP authentication based on SSL certificates and TLS
 * 
 * The certificate may either be 
 * - stored on the local file system in the PEM or PKCS#12 (.pfx) format - e.g. in the `data/.certs` folder
 * - pasted into the login form in PEM format - in this case it will be stored in the `.certs` folder of
 * the current user automatically.
 * 
 * ## Examples
 * 
 * To use a certificate file:
 * 
 * ```
 *  {
 *      "class": "\\exface\\UrlDataConnector\\DataConnectors\\Authentication\\TLSCertificateAuth",
 *      "certificate_path": "data/.certs/mycert.pfx",
 *      "passphrase": ""  
 *  }
 *  
 * ```
 * 
 * To let users paste their certificates:
 * 
 * ```
 *  {
 *      "class": "\\exface\\UrlDataConnector\\DataConnectors\\Authentication\\TLSCertificateAuth",
 *      "authentication_url": "secure/url",
 *      "authentication_method": "GET"  
 *  }
 *  
 * ```
 * 
 * @author Andrej Kabachnik
 *
 */
class TLSCertificateAuth extends AbstractHttpAuthenticationProvider
{    
    private $certificatePath = null;
    
    private $certificateType = null;
    
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
    public function setAuthenticationUrl(string $string) : TLSCertificateAuth
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
    public function setAuthenticationRequestMethod(string $string) : TLSCertificateAuth
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
    protected function getCertificatePath() : string
    {
        if ($this->certificatePath === null) {
            throw new AuthenticationFailedError($this->getConnection(), 'Please provide a valid SSL certificate!');
        }
        return $this->certificatePath;
    }
    
    /**
     * Path to the PEM certificate relative to the current installation folder
     * 
     * @uxon-property certificate_path
     * @uxon-type string
     * @uxon-required true
     * @uxon-template data/.certs/cert.pem
     * 
     * @param string $value
     * @return TLSCertificateAuth
     */
    public function setCertificatePath(string $value) : TLSCertificateAuth
    {
        $this->certificatePath = $value;
        return $this;
    }
    
    /**
     * 
     * @return string
     */
    protected function getCertificateType() : string
    {
        return $this->certificateType ?? SSLCertificateFormatDataType::isFilePKCS12($this->getCertificatePath()) ? SSLCertificateFormatDataType::PKCS12 : SSLCertificateFormatDataType::PEM;
    }
    
    /**
     * The format of the SSL certificate provided - in case auto-detection does not work properly
     * 
     * @uxon-property certificate_type
     * @uxon-type [PKCS#12,PEM]
     * 
     * @param string $value
     * @return TLSCertificateAuth
     */
    public function setCertificateType(string $value) : TLSCertificateAuth
    {
        $this->certificateType = SSLCertificateFormatDataType::cast($value);
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
    public function setPassphrase(string $value) : TLSCertificateAuth
    {
        $this->passphrase = $value;
        return $this;
    }
    
    /**
     * Returns the Guzzle options array for a dedicated authentication request.
     * 
     * E.g. ["cert" => ["path", "password"]] 
     * 
     * @param array $defaultOptions
     * @param PasswordAuthenticationTokenInterface $token
     * @return array
     */
    protected function getAuthenticationRequestOptions(array $defaultOptions, SSLCertificateAuthToken $token) : array
    {
        $options = $defaultOptions;
        
        // Basic authentication
        $options['cert'] = [
            $token->getCertificatePath(),
            $token->getPassphrase()
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
        if (! $token instanceof SSLCertificateAuthToken) {
            throw new InvalidArgumentException('Invalid token class "' . get_class($token) . '" for authentication via data connection "' . $this->getAliasWithNamespace() . '" - only "UsernamePasswordAuthToken" and derivatives supported!');
        }
        
        $url = $this->getAuthenticationUrl() ?? $this->getConnection()->getUrl();
        if (! $url) {
            throw new AuthenticatorConfigError($this, "Cannot perform authentication in data connection '{$this->getName()}'! Either provide authentication_url or a general url in the connection configuration.");
        }
        
        if (null !== $cert = $token->getCertificate()) {
            if (null === $certPath = $token->getCertificatePath()) {
                $userToken = $this->getWorkbench()->getSecurity()->getAuthenticatedToken();
                $dir = $userToken->isAnonymous() ? 'data/.certs' : $this->getWorkbench()->getContext()->getScopeUser()->getUserDataFolderAbsolutePath() . DIRECTORY_SEPARATOR . '.certs';
                $filename = FilePathDataType::sanitizeFilename(str_replace('.', '_', $this->getConnection()->getAliasWithNamespace())) . '.pem';
                $certPath = $dir . DIRECTORY_SEPARATOR . $filename;
            } 
            file_put_contents($certPath, $cert);
            $token = new SSLCertificateAuthToken(
                $certPath,
                null,
                $token->getPassphrase(),
                $token->getFacade()
            );
        }
        $certPathBkp = $this->certificatePath;
        $this->setCertificatePath($certPath);
        try {
            $request = new Request($this->getAuthenticationRequestMethod(), $url);
            $this->getConnection()->sendRequest($request, $this->getAuthenticationRequestOptions([], $token));
        } catch (\Throwable $e) {
            if ($certPathBkp !== null) {
                $this->setCertificatePath($certPathBkp);
            }
            unlink($token->getCertificatePath());
            throw $e;
        }
        
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
                'certificate_path' => $authenticatedToken->getCertificatePath(),
                'passphrase' => $authenticatedToken->getPassphrase()
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
        
        $options['cert'] = [
            $this->getPemCertPath(),
            $this->getPassphrase()
        ];
        
        return $options;
    }
    
    /**
     * 
     * @throws AuthenticationFailedError
     * @return string
     */
    protected function getPemCertPath() : string
    {
        $certPath = $this->getCertificatePath();
        if (FilePathDataType::isRelative($certPath)) {
            $certPath = FilePathDataType::join([$this->getWorkbench()->getInstallationPath(), $certPath]);
        }
        $mtime = filemtime($certPath);
        if ($mtime === false) {
            throw new AuthenticationFailedError($this->getConnection(), 'SSL Certificate "' . $this->getCertificatePath() . '" not found!');
        }
        if ($this->getCertificateType() === SSLCertificateFormatDataType::PKCS12) {
            $dir = FilePathDataType::findFolderPath($certPath);
            $pemPath = FilePathDataType::join([$dir, FilePathDataType::findFileName($certPath) . '_' . $mtime . '.pem']);
            if (! file_exists($pemPath)) {
                file_put_contents($pemPath, SSLCertificateFormatDataType::convertPfxToPem(file_get_contents($certPath), $this->getPassphrase()));
            }
        } else {
            $pemPath = $certPath;
        }
        return $pemPath;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\AuthenticationProviderInterface::createLoginWidget()
     */
    public function createLoginWidget(iContainOtherWidgets $loginForm, bool $saveCredentials = true, UserSelectorInterface $credentialsOwner = null) : iContainOtherWidgets
    {
        $loginForm->addWidget(WidgetFactory::createFromUxonInParent($loginForm, new UxonObject([
            'widget_type' => 'InputText',
            'data_column_name' => 'CERTIFICATE',
            'required' => true,
            'caption' => 'Certificate in PEM format',
            'hint' => 'Paste the contents of the certificate file in PEM format here'
        ])), 0);
        $loginForm->addWidget(WidgetFactory::createFromUxonInParent($loginForm, new UxonObject([
            'widget_type' => 'InputPassword',
            'data_column_name' => 'PASSPHRASE',
            'caption' => 'Passphrase'
        ])), 1);
        $loginForm->addWidget(WidgetFactory::createFromUxonInParent($loginForm, new UxonObject([
            'attribute_alias' => 'AUTH_TOKEN_CLASS',
            'value' => '\\' . SSLCertificateAuthToken::class,
            'widget_type' => 'InputHidden'
        ])));
        
        return $loginForm;
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
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\CommonLogic\AbstractHttpAuthenticationProvider::isResponseUnauthenticated()
     */
    public function isResponseUnauthenticated(ResponseInterface $response) : bool
    {
        return parent::isResponseUnauthenticated($response) || $response->getStatusCode() == 403;
    }
}