<?php
namespace exface\UrlDataConnector\DataTypes;

use exface\Core\Exceptions\DataTypes\DataTypeCastingError;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;

/**
 * Data type for SSL certificate formats like PEM (`.pem`, `.crt`, etc.), PKCS#12 (`.pfx`, `.p12`), PKCS#7 and DER.
 * 
 * @author Andrej Kabachnik
 *
 */
class SSLCertificateFormatDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;
    
    const PKCS12 = 'PKCS#12';
    
    const PKCS7 = 'PKCS#7';
    
    const PEM = 'PEM';
    
    const DER = 'DER';
    
    private $labels = [];
    
    /**
     * Decodes a (binary) string in PKCS#12 format into the plain-text PEM format
     * 
     * @param string $pkcs12Content
     * @param string $passphrase
     * @return string
     */
    public static function convertPfxToPem(string $pkcs12Content, string $passphrase = null) : string
    {
        $res = static::readPkcs12($pkcs12Content, $passphrase);        
        return ($res['pkey'] ?? '') . ($res['cert'] ?? '') . implode('', $res['extracerts'] ?? []);
    }
    
    /**
     * 
     * @param string $pkcs12
     * @param string $passphrase
     * @throws InvalidArgumentException
     * @return array
     */
    protected static function readPkcs12(string $pkcs12, string $passphrase = null) : array
    {
        $res = [];
        $openSSL = openssl_pkcs12_read($pkcs12, $res, $passphrase);
        if(! $openSSL) {
            throw new DataTypeCastingError("Cannot read PKCS#12 certificate: " . openssl_error_string());
        }
        return $res;
    }
    
    /**
     * Returns TRUE if the given string is a valid PEM format (quick ckeck for BEGIN/END keywords without parsing!)
     *  
     * @param string $value
     * @return bool
     */
    public static function isValuePEM(string $value) : bool
    {
        return stripos($value, '-----BEGIN CERTIFICATE-----') !== false && stripos($value, '-----END CERTIFICATE-----') > 0;
    }
    
    /**
     * Returns TRUE if the given path contains a PKCS#12 certificate.
     * 
     * By default, this method only checks for a valid file extension. If $checkContents is set
     * to TRUE, it will also attempt to parse the contents. This only works for absolute paths
     * though!
     * 
     * @param string $path
     * @param bool $checkContents
     * @return bool
     */
    public static function isFilePKCS12(string $path, bool $checkContents = false) : bool
    {
        if ($checkContents) {
            try {
                $result = static::readPkcs12(file_get_contents($path));                
            } catch (\Throwable $e) {
                $result = false;
            }
        } else {
            $ext = mb_strtolower(FilePathDataType::findExtension($path));
            $result = ($ext === 'pfx' || $ext === 'p12');
        }
        return $result;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getLabels()
     */
    public function getLabels()
    {
        if (empty($this->labels)) {            
            foreach (static::getValuesStatic() as $val) {
                $this->labels[$val] = $val;
            }
        }
        
        return $this->labels;
    }
}