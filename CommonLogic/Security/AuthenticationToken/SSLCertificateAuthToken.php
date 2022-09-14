<?php
namespace exface\UrlDataConnector\CommonLogic\Security\AuthenticationToken;

use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Interfaces\Facades\FacadeInterface;

/**
 * This token contains an SSL certificate (as file path or plain text) and a passphrase
 * 
 * @author Andrej Kabachnik
 *
 */
class SSLCertificateAuthToken implements AuthenticationTokenInterface
{
    private $facade = null;
    
    private $certificate = null;
    
    private $certificatePath = null;
    
    private $passphrase = null;
    
    /**
     *
     * @param string $username
     * @param string $password
     * @param FacadeInterface $facade
     */
    public function __construct(string $certificatePath = null, string $certificate = null, string $passphrase = null, FacadeInterface $facade = null)
    {
        $this->facade = $facade;
        $this->certificate = $certificate;
        $this->certificatePath = $certificatePath;
        $this->passphrase = $passphrase;
    }
    
    /**
     * 
     * @return string|NULL
     */
    public function getCertificate() : ?string
    {
        return $this->certificate;
    }
    
    /**
     * 
     * @return string|NULL
     */
    public function getCertificatePath() : ?string
    {
        return $this->certificatePath;
    }
    
    /**
     * 
     * @return string|NULL
     */
    public function getPassphrase(): ?string
    {
        return $this->passphrase;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\AuthenticationTokenInterface::getFacade()
     */
    public function getFacade(): ?FacadeInterface
    {
        return $this->facade;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\AuthenticationTokenInterface::getUsername()
     */
    public function getUsername() : ?string
    {
        return null;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\AuthenticationTokenInterface::isAnonymous()
     */
    public function isAnonymous() : bool
    {
        return false;
    }
}