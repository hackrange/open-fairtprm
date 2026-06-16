<?php
/**
 * SAML 2.0 Service Provider Handler
 *
 * Lightweight SAML SP implementation using PHP's built-in DOMDocument + OpenSSL.
 * Handles AuthnRequest generation, Response validation, metadata generation,
 * and Single Logout. The IdP does the heavy cryptographic lifting; this SP
 * consumes and validates SAML responses.
 */

class SAMLHandler {
    private $db;
    private $config;
    private $enabled;

    // IdP settings
    private $idpEntityId;
    private $idpSsoUrl;
    private $idpSloUrl;
    private $idpCertificate;

    // SP settings
    private $spEntityId;
    private $spAcsUrl;
    private $spSloUrl;

    // Attribute mapping
    private $attributeMapping;
    private $groupAttribute;

    // Clock skew tolerance in seconds
    private const CLOCK_SKEW = 120;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadConfig();
    }

    private function loadConfig() {
        $row = $this->db->fetchOne('SELECT * FROM saml_config LIMIT 1');
        if (!$row) {
            $this->enabled = false;
            return;
        }

        $this->enabled        = (bool)$row['is_enabled'];
        $this->idpEntityId    = $row['idp_entity_id'] ?? '';
        $this->idpSsoUrl      = $row['idp_sso_url'] ?? '';
        $this->idpSloUrl      = $row['idp_slo_url'] ?? '';
        $this->idpCertificate = $row['idp_certificate'] ?? '';
        $this->spEntityId     = $row['sp_entity_id'] ?? '';
        $this->spAcsUrl       = $row['sp_acs_url'] ?? '';
        $this->spSloUrl       = $row['sp_slo_url'] ?? '';
        $this->groupAttribute = $row['group_attribute'] ?? 'groups';

        $this->attributeMapping = [];
        if (!empty($row['attribute_mapping'])) {
            $decoded = json_decode($row['attribute_mapping'], true);
            if (is_array($decoded)) {
                $this->attributeMapping = $decoded;
            }
        }
    }

    public function isEnabled() {
        return $this->enabled
            && !empty($this->idpEntityId)
            && !empty($this->idpSsoUrl)
            && !empty($this->idpCertificate)
            && !empty($this->spEntityId)
            && !empty($this->spAcsUrl);
    }

    /**
     * Build a SAML AuthnRequest XML string and return the request ID.
     */
    public function buildAuthnRequest() {
        $id = '_' . bin2hex(random_bytes(16));
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');

        $xml = <<<XML
<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="{$id}"
    Version="2.0"
    IssueInstant="{$issueInstant}"
    Destination="{$this->idpSsoUrl}"
    AssertionConsumerServiceURL="{$this->spAcsUrl}"
    ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">
    <saml:Issuer>{$this->spEntityId}</saml:Issuer>
    <samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress" AllowCreate="true"/>
</samlp:AuthnRequest>
XML;

        return ['id' => $id, 'xml' => $xml];
    }

    /**
     * Get the full redirect URL for SP-initiated SSO.
     */
    public function getLoginUrl($relayState = '') {
        $request = $this->buildAuthnRequest();

        // Store request ID in session for InResponseTo validation
        $session = Session::getInstance();
        $session->set('saml_request_id', $request['id']);

        // Deflate + base64 encode for HTTP-Redirect binding
        $deflated = gzdeflate($request['xml']);
        $encoded = base64_encode($deflated);

        $params = ['SAMLRequest' => $encoded];
        if (!empty($relayState)) {
            $params['RelayState'] = $relayState;
        }

        $separator = (strpos($this->idpSsoUrl, '?') === false) ? '?' : '&';
        return $this->idpSsoUrl . $separator . http_build_query($params);
    }

    /**
     * Process a SAML Response from the IdP.
     * Returns extracted user attributes on success, throws on failure.
     *
     * @param string $samlResponse Base64-encoded SAMLResponse from POST
     * @return array ['nameId' => string, 'attributes' => array, 'sessionIndex' => string|null]
     * @throws Exception on validation failure
     */
    public function processResponse($samlResponse) {
        $xml = base64_decode($samlResponse, true);
        if ($xml === false) {
            throw new Exception('Invalid base64 in SAMLResponse');
        }

        // Signature, digest, XML Signature Wrapping (XSW), Conditions, Audience,
        // Destination and InResponseTo validation are delegated to the vetted
        // onelogin/php-saml library (backed by robrichards/xmlseclibs). The prior
        // hand-rolled DOM/OpenSSL validator was vulnerable to signature wrapping:
        // it verified the signature over one element but read identity from a
        // different (attacker-controlled) element. The library binds the two.
        if (!class_exists('OneLogin\\Saml2\\Response')) {
            $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }
        if (!class_exists('OneLogin\\Saml2\\Response')) {
            throw new Exception('SAML library (onelogin/php-saml) is not installed');
        }

        $settings = new \OneLogin\Saml2\Settings($this->buildOneLoginSettings(), false);

        // Honour reverse-proxy headers so Destination/ACS-URL checks compare against
        // the externally visible HTTPS URL, not the container-internal http:8080.
        \OneLogin\Saml2\Utils::setProxyVars(true);

        $response = new \OneLogin\Saml2\Response($settings, $samlResponse);

        // Bind the response to our outstanding AuthnRequest (InResponseTo) if present.
        $session = Session::getInstance();
        $expectedId = $session->get('saml_request_id');

        if (!$response->isValid($expectedId ?: null)) {
            $err = $response->getErrorException();
            $msg = $err ? $err->getMessage() : ($response->getError() ?: 'SAML response validation failed');
            throw new Exception('SAML validation failed: ' . $msg);
        }
        $session->remove('saml_request_id');

        $nameId = $response->getNameId();
        if (empty($nameId)) {
            throw new Exception('No NameID found in SAML Assertion');
        }

        return [
            'nameId'       => $nameId,
            'attributes'   => $this->normalizeAttributes($response->getAttributes()),
            'sessionIndex' => $response->getSessionIndex(),
        ];
    }

    /**
     * Build the onelogin/php-saml settings array from the stored saml_config row.
     */
    private function buildOneLoginSettings() {
        $settings = [
            'strict' => true,
            'sp' => [
                'entityId' => $this->spEntityId,
                'assertionConsumerService' => [
                    'url'     => $this->spAcsUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            ],
            'idp' => [
                'entityId' => $this->idpEntityId,
                'singleSignOnService' => [
                    'url'     => $this->idpSsoUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $this->idpCertificate,
            ],
            'security' => [
                'wantAssertionsSigned'  => true,
                'wantMessagesSigned'    => false,
                'wantNameId'            => true,
                'requestedAuthnContext' => false,
            ],
        ];

        // Pin Destination validation to the externally visible SP origin.
        $acsParts = parse_url($this->spAcsUrl);
        if ($acsParts && !empty($acsParts['scheme']) && !empty($acsParts['host'])) {
            $settings['baseurl'] = $acsParts['scheme'] . '://' . $acsParts['host']
                . (!empty($acsParts['port']) ? ':' . $acsParts['port'] : '');
        }

        if (!empty($this->idpSloUrl)) {
            $settings['idp']['singleLogoutService'] = [
                'url'     => $this->idpSloUrl,
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ];
        }
        if (!empty($this->spSloUrl)) {
            $settings['sp']['singleLogoutService'] = [
                'url'     => $this->spSloUrl,
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ];
        }

        return $settings;
    }

    /**
     * Normalize onelogin getAttributes() output (always arrays) to the shape the
     * rest of this class expects: single value as scalar, multiple as array.
     */
    private function normalizeAttributes(array $attributes) {
        $out = [];
        foreach ($attributes as $name => $values) {
            if (is_array($values)) {
                $out[$name] = (count($values) === 1) ? $values[0] : $values;
            } else {
                $out[$name] = $values;
            }
        }
        return $out;
    }

    /**
     * Validate the StatusCode in the SAML Response.
     */
    private function validateStatus(DOMDocument $doc) {
        $statusCodes = $doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:protocol', 'StatusCode');
        if ($statusCodes->length === 0) {
            throw new Exception('No StatusCode in SAML Response');
        }

        $value = $statusCodes->item(0)->getAttribute('Value');
        if ($value !== 'urn:oasis:names:tc:SAML:2.0:status:Success') {
            // Try to get a status message for better error reporting
            $statusMessages = $doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:protocol', 'StatusMessage');
            $msg = $statusMessages->length > 0 ? $statusMessages->item(0)->textContent : $value;
            throw new Exception('SAML authentication failed: ' . $msg);
        }
    }

    /**
     * Validate XML digital signature against the IdP certificate.
     */
    private function validateSignature(DOMDocument $doc) {
        $nsDS = 'http://www.w3.org/2000/09/xmldsig#';

        $signatureNodes = $doc->getElementsByTagNameNS($nsDS, 'Signature');
        if ($signatureNodes->length === 0) {
            throw new Exception('No XML Signature found in SAML Response');
        }

        // Use the first Signature element found
        $signatureNode = $signatureNodes->item(0);

        // Get SignedInfo and canonicalize it
        $signedInfoNodes = $signatureNode->getElementsByTagNameNS($nsDS, 'SignedInfo');
        if ($signedInfoNodes->length === 0) {
            throw new Exception('No SignedInfo element in Signature');
        }
        $signedInfo = $signedInfoNodes->item(0);

        // Determine canonicalization method
        $c14nMethod = 'http://www.w3.org/2001/10/xml-exc-c14n#'; // default exc-c14n
        $c14nNodes = $signedInfo->getElementsByTagNameNS($nsDS, 'CanonicalizationMethod');
        if ($c14nNodes->length > 0) {
            $c14nMethod = $c14nNodes->item(0)->getAttribute('Algorithm');
        }

        $canonicalSignedInfo = $this->canonicalize($signedInfo, $c14nMethod);

        // Get signature value
        $sigValueNodes = $signatureNode->getElementsByTagNameNS($nsDS, 'SignatureValue');
        if ($sigValueNodes->length === 0) {
            throw new Exception('No SignatureValue element in Signature');
        }
        $signatureValue = base64_decode(preg_replace('/\s+/', '', $sigValueNodes->item(0)->textContent));

        // Determine signature algorithm
        $sigMethodNodes = $signedInfo->getElementsByTagNameNS($nsDS, 'SignatureMethod');
        $algorithm = OPENSSL_ALGO_SHA256; // default
        if ($sigMethodNodes->length > 0) {
            $algoUri = $sigMethodNodes->item(0)->getAttribute('Algorithm');
            $algorithm = $this->mapSignatureAlgorithm($algoUri);
        }

        // Load IdP certificate
        $cert = $this->loadCertificate();
        $publicKey = openssl_pkey_get_public($cert);
        if ($publicKey === false) {
            throw new Exception('Failed to load IdP certificate: ' . openssl_error_string());
        }

        // Verify the signature
        $result = openssl_verify($canonicalSignedInfo, $signatureValue, $publicKey, $algorithm);

        if ($result !== 1) {
            throw new Exception('XML Signature verification failed: ' . openssl_error_string());
        }

        // Verify digest of the referenced element
        $this->validateDigest($doc, $signedInfo);
    }

    /**
     * Validate the digest value in the Reference element.
     */
    private function validateDigest(DOMDocument $doc, DOMElement $signedInfo) {
        $nsDS = 'http://www.w3.org/2000/09/xmldsig#';

        $referenceNodes = $signedInfo->getElementsByTagNameNS($nsDS, 'Reference');
        if ($referenceNodes->length === 0) {
            return; // No references to validate
        }

        $reference = $referenceNodes->item(0);
        $uri = $reference->getAttribute('URI');

        // Find the referenced element
        if (empty($uri) || $uri === '') {
            $referencedNode = $doc->documentElement;
        } else {
            $refId = ltrim($uri, '#');
            $referencedNode = $this->findById($doc, $refId);
            if ($referencedNode === null) {
                throw new Exception('Referenced element not found: ' . $uri);
            }
        }

        // Apply transforms
        $transformNodes = $reference->getElementsByTagNameNS($nsDS, 'Transform');
        $c14nMethod = 'http://www.w3.org/2001/10/xml-exc-c14n#';
        $enveloped = false;
        $inclusivePrefixes = null;

        for ($i = 0; $i < $transformNodes->length; $i++) {
            $transform = $transformNodes->item($i);
            $algo = $transform->getAttribute('Algorithm');
            if ($algo === 'http://www.w3.org/2000/09/xmldsig#enveloped-signature') {
                $enveloped = true;
            } elseif (strpos($algo, 'c14n') !== false || strpos($algo, 'C14N') !== false) {
                $c14nMethod = $algo;
                // Check for InclusiveNamespaces PrefixList (used by exc-c14n)
                $incNs = $transform->getElementsByTagNameNS('http://www.w3.org/2001/10/xml-exc-c14n#', 'InclusiveNamespaces');
                if ($incNs->length > 0) {
                    $prefixList = $incNs->item(0)->getAttribute('PrefixList');
                    if ($prefixList !== '') {
                        $inclusivePrefixes = explode(' ', $prefixList);
                    }
                }
            }
        }

        // For enveloped signature, remove the Signature element before canonicalization
        if ($enveloped) {
            // Remove the Signature from the original tree temporarily
            $sigNode = $referencedNode->getElementsByTagNameNS($nsDS, 'Signature')->item(0);
            $sigParent = $sigNode ? $sigNode->parentNode : null;
            $sigNext = $sigNode ? $sigNode->nextSibling : null;
            if ($sigNode && $sigParent) {
                $sigParent->removeChild($sigNode);
            }
            $canonicalData = $this->canonicalize($referencedNode, $c14nMethod, $inclusivePrefixes);
            // Restore the Signature
            if ($sigNode && $sigParent) {
                if ($sigNext) {
                    $sigParent->insertBefore($sigNode, $sigNext);
                } else {
                    $sigParent->appendChild($sigNode);
                }
            }
        } else {
            $canonicalData = $this->canonicalize($referencedNode, $c14nMethod, $inclusivePrefixes);
        }

        // Determine digest algorithm
        $digestMethodNodes = $reference->getElementsByTagNameNS($nsDS, 'DigestMethod');
        $digestAlgo = 'sha256';
        if ($digestMethodNodes->length > 0) {
            $digestAlgo = $this->mapDigestAlgorithm($digestMethodNodes->item(0)->getAttribute('Algorithm'));
        }

        // Get expected digest
        $digestValueNodes = $reference->getElementsByTagNameNS($nsDS, 'DigestValue');
        if ($digestValueNodes->length === 0) {
            throw new Exception('No DigestValue in Reference');
        }
        $expectedDigest = base64_decode(trim($digestValueNodes->item(0)->textContent));

        // Compute actual digest
        $actualDigest = hash($digestAlgo, $canonicalData, true);

        if (!hash_equals($expectedDigest, $actualDigest)) {
            throw new Exception('Digest value mismatch - SAML Response may have been tampered with');
        }
    }

    /**
     * Find an element by its ID attribute (checks both 'ID' and 'Id').
     */
    private function findById(DOMDocument $doc, $id) {
        $xpath = new DOMXPath($doc);
        // Try common ID attribute names
        foreach (['ID', 'Id', 'id'] as $attr) {
            $nodes = $xpath->query("//*[@{$attr}='{$id}']");
            if ($nodes->length > 0) {
                return $nodes->item(0);
            }
        }
        return null;
    }

    /**
     * Validate Conditions element: NotBefore, NotOnOrAfter, Audience.
     */
    private function validateConditions(DOMElement $assertion) {
        $conditions = $assertion->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Conditions');
        if ($conditions->length === 0) {
            return; // No conditions to validate
        }

        $cond = $conditions->item(0);
        $now = time();

        $notBefore = $cond->getAttribute('NotBefore');
        if (!empty($notBefore)) {
            $nbTime = strtotime($notBefore);
            if ($nbTime !== false && ($now + self::CLOCK_SKEW) < $nbTime) {
                throw new Exception('SAML Assertion not yet valid (NotBefore: ' . $notBefore . ')');
            }
        }

        $notOnOrAfter = $cond->getAttribute('NotOnOrAfter');
        if (!empty($notOnOrAfter)) {
            $noaTime = strtotime($notOnOrAfter);
            if ($noaTime !== false && ($now - self::CLOCK_SKEW) >= $noaTime) {
                throw new Exception('SAML Assertion has expired (NotOnOrAfter: ' . $notOnOrAfter . ')');
            }
        }

        // Validate Audience Restriction
        $audiences = $assertion->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Audience');
        if ($audiences->length > 0) {
            $matched = false;
            for ($i = 0; $i < $audiences->length; $i++) {
                if (trim($audiences->item($i)->textContent) === $this->spEntityId) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new Exception('Audience restriction mismatch: expected ' . $this->spEntityId);
            }
        }
    }

    /**
     * Extract attribute statements from the assertion.
     */
    private function extractAttributes(DOMElement $assertion) {
        $ns = 'urn:oasis:names:tc:SAML:2.0:assertion';
        $attributes = [];

        $attrStatements = $assertion->getElementsByTagNameNS($ns, 'AttributeStatement');
        if ($attrStatements->length === 0) {
            return $attributes;
        }

        $attrNodes = $attrStatements->item(0)->getElementsByTagNameNS($ns, 'Attribute');
        for ($i = 0; $i < $attrNodes->length; $i++) {
            $attr = $attrNodes->item($i);
            $name = $attr->getAttribute('Name');

            $values = [];
            $valueNodes = $attr->getElementsByTagNameNS($ns, 'AttributeValue');
            for ($j = 0; $j < $valueNodes->length; $j++) {
                $values[] = trim($valueNodes->item($j)->textContent);
            }

            // Store single values as string, multiple as array
            $attributes[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $attributes;
    }

    /**
     * Map SAML attributes to user fields based on configured attribute_mapping.
     */
    public function mapAttributesToUser($nameId, array $attributes) {
        $user = [
            'email' => $nameId, // default: NameID is email
        ];

        // Default mappings if none configured
        $mapping = $this->attributeMapping;
        if (empty($mapping)) {
            $mapping = [
                'email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
                'full_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
                'first_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
                'last_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
                'username' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
            ];
        }

        foreach ($mapping as $userField => $samlAttr) {
            if (!empty($samlAttr) && isset($attributes[$samlAttr])) {
                $val = $attributes[$samlAttr];
                $user[$userField] = is_array($val) ? $val[0] : $val;
            }
        }

        // If full_name not found via mapping, try common alternative attribute names
        if (empty($user['full_name'])) {
            $nameAttrs = [
                'displayName', 'name', 'cn', 'commonName',
                'urn:oid:2.16.840.1.113730.3.1.241', // displayName (LDAP)
                'urn:oid:2.5.4.3',                    // cn (LDAP)
            ];
            foreach ($nameAttrs as $attr) {
                if (isset($attributes[$attr])) {
                    $val = $attributes[$attr];
                    $user['full_name'] = is_array($val) ? $val[0] : $val;
                    break;
                }
            }
        }

        // Build full_name from first_name + last_name if still empty
        if (empty($user['full_name'])) {
            $firstName = $user['first_name'] ?? '';
            $lastName  = $user['last_name'] ?? '';

            // Also try common alternative attribute names for first/last
            if (empty($firstName)) {
                $fnAttrs = ['givenName', 'firstName', 'urn:oid:2.5.4.42'];
                foreach ($fnAttrs as $attr) {
                    if (isset($attributes[$attr])) {
                        $firstName = is_array($attributes[$attr]) ? $attributes[$attr][0] : $attributes[$attr];
                        break;
                    }
                }
            }
            if (empty($lastName)) {
                $lnAttrs = ['sn', 'surname', 'lastName', 'urn:oid:2.5.4.4'];
                foreach ($lnAttrs as $attr) {
                    if (isset($attributes[$attr])) {
                        $lastName = is_array($attributes[$attr]) ? $attributes[$attr][0] : $attributes[$attr];
                        break;
                    }
                }
            }

            $combined = trim($firstName . ' ' . $lastName);
            if (!empty($combined)) {
                $user['full_name'] = $combined;
            }
        }

        // Ensure email is set
        if (empty($user['email'])) {
            $user['email'] = $nameId;
        }

        return $user;
    }

    /**
     * Get SAML group values from attributes.
     */
    public function getGroupsFromAttributes(array $attributes) {
        if (empty($this->groupAttribute)) {
            return [];
        }

        $groups = $attributes[$this->groupAttribute] ?? [];
        if (is_string($groups)) {
            $groups = [$groups];
        }

        return $groups;
    }

    /**
     * Generate SP Metadata XML.
     */
    public function generateMetadataXml() {
        $entityId = htmlspecialchars($this->spEntityId, ENT_XML1, 'UTF-8');
        $acsUrl = htmlspecialchars($this->spAcsUrl, ENT_XML1, 'UTF-8');
        $sloUrl = htmlspecialchars($this->spSloUrl ?: '', ENT_XML1, 'UTF-8');

        $sloDescriptor = '';
        if (!empty($this->spSloUrl)) {
            $sloDescriptor = <<<XML

            <md:SingleLogoutService
                Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
                Location="{$sloUrl}"/>
XML;
        }

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
    entityID="{$entityId}">
    <md:SPSSODescriptor
        AuthnRequestsSigned="false"
        WantAssertionsSigned="true"
        protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
        <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>{$sloDescriptor}
        <md:AssertionConsumerService
            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
            Location="{$acsUrl}"
            index="0"
            isDefault="true"/>
    </md:SPSSODescriptor>
</md:EntityDescriptor>
XML;

        return $xml;
    }

    /**
     * Process a SAML LogoutRequest from the IdP.
     * Returns the NameID from the request.
     */
    public function processLogoutRequest($samlRequest) {
        $decoded = base64_decode($samlRequest, true);
        if ($decoded === false) {
            throw new Exception('Invalid base64 in SAMLRequest');
        }

        // Try to inflate (HTTP-Redirect binding uses DEFLATE)
        $inflated = @gzinflate($decoded);
        $xmlStr = $inflated !== false ? $inflated : $decoded;

        $doc = $this->parseXml($xmlStr);

        $logoutRequests = $doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:protocol', 'LogoutRequest');
        if ($logoutRequests->length === 0) {
            throw new Exception('No LogoutRequest found');
        }

        $nameIdNodes = $doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'NameID');
        $nameId = $nameIdNodes->length > 0 ? trim($nameIdNodes->item(0)->textContent) : '';

        return ['nameId' => $nameId];
    }

    /**
     * Build a SAML LogoutResponse to send back to the IdP.
     */
    public function buildLogoutResponse($inResponseTo = '') {
        $id = '_' . bin2hex(random_bytes(16));
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $destination = htmlspecialchars($this->idpSloUrl, ENT_XML1, 'UTF-8');
        $issuer = htmlspecialchars($this->spEntityId, ENT_XML1, 'UTF-8');
        $inResponseToAttr = !empty($inResponseTo) ? ' InResponseTo="' . htmlspecialchars($inResponseTo, ENT_XML1, 'UTF-8') . '"' : '';

        $xml = <<<XML
<samlp:LogoutResponse xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="{$id}"
    Version="2.0"
    IssueInstant="{$issueInstant}"
    Destination="{$destination}"{$inResponseToAttr}>
    <saml:Issuer>{$issuer}</saml:Issuer>
    <samlp:Status>
        <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/>
    </samlp:Status>
</samlp:LogoutResponse>
XML;

        return $xml;
    }

    /**
     * Get the SLO redirect URL with an encoded LogoutResponse.
     */
    public function getLogoutResponseUrl($inResponseTo = '') {
        if (empty($this->idpSloUrl)) {
            return null;
        }

        $response = $this->buildLogoutResponse($inResponseTo);
        $deflated = gzdeflate($response);
        $encoded = base64_encode($deflated);

        $separator = (strpos($this->idpSloUrl, '?') === false) ? '?' : '&';
        return $this->idpSloUrl . $separator . http_build_query(['SAMLResponse' => $encoded]);
    }

    /**
     * Get the SP-initiated logout URL to redirect to IdP SLO.
     */
    public function getLogoutUrl() {
        if (empty($this->idpSloUrl)) {
            return null;
        }

        $id = '_' . bin2hex(random_bytes(16));
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');

        $session = Session::getInstance();
        $nameId = $session->get('saml_name_id', '');
        $sessionIndex = $session->get('saml_session_index', '');

        $sessionIndexXml = '';
        if (!empty($sessionIndex)) {
            $sessionIndexXml = '<samlp:SessionIndex>' . htmlspecialchars($sessionIndex, ENT_XML1, 'UTF-8') . '</samlp:SessionIndex>';
        }

        $xml = <<<XML
<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="{$id}"
    Version="2.0"
    IssueInstant="{$issueInstant}"
    Destination="{$this->idpSloUrl}">
    <saml:Issuer>{$this->spEntityId}</saml:Issuer>
    <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">{$nameId}</saml:NameID>
    {$sessionIndexXml}
</samlp:LogoutRequest>
XML;

        $deflated = gzdeflate($xml);
        $encoded = base64_encode($deflated);

        $separator = (strpos($this->idpSloUrl, '?') === false) ? '?' : '&';
        return $this->idpSloUrl . $separator . http_build_query(['SAMLRequest' => $encoded]);
    }

    // --- Private helpers ---

    /**
     * Parse XML with security protections against XXE.
     */
    private function parseXml($xmlString) {
        // Reject DTDs outright. SAML never legitimately needs a DOCTYPE, and
        // allowing one enables XXE / billion-laughs entity-expansion DoS.
        if (preg_match('/<!DOCTYPE/i', $xmlString)) {
            throw new Exception('DOCTYPE declarations are not allowed in SAML XML');
        }

        $doc = new DOMDocument();

        // XXE protection: block external entity loading unconditionally (not
        // version-gated), and parse with no DTD load and no network access.
        $previousValue = libxml_use_internal_errors(true);
        if (function_exists('libxml_set_external_entity_loader')) {
            libxml_set_external_entity_loader(function () { return null; });
        }
        $loadFlags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;

        if (!$doc->loadXML($xmlString, $loadFlags)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previousValue);
            $errorMsg = !empty($errors) ? $errors[0]->message : 'Unknown XML parse error';
            throw new Exception('Failed to parse SAML XML: ' . trim($errorMsg));
        }

        libxml_use_internal_errors($previousValue);

        return $doc;
    }

    /**
     * Canonicalize an XML node.
     */
    private function canonicalize(DOMNode $node, $method, ?array $inclusivePrefixes = null) {
        $exclusive = (strpos($method, 'exc-c14n') !== false || strpos($method, 'exc_c14n') !== false);
        $withComments = (strpos($method, 'WithComments') !== false || strpos($method, '#withComments') !== false);

        // C14N signature: C14N(bool $exclusive, bool $withComments, ?array $xpath, ?array $nsPrefixes)
        // The nsPrefixes param is the InclusiveNamespaces PrefixList for exc-c14n
        if ($exclusive && $inclusivePrefixes !== null) {
            return $node->C14N($exclusive, $withComments, null, $inclusivePrefixes);
        }
        return $node->C14N($exclusive, $withComments);
    }

    /**
     * Map XML signature algorithm URI to OpenSSL constant.
     */
    private function mapSignatureAlgorithm($uri) {
        $map = [
            'http://www.w3.org/2000/09/xmldsig#rsa-sha1'       => OPENSSL_ALGO_SHA1,
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384' => OPENSSL_ALGO_SHA384,
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => OPENSSL_ALGO_SHA512,
        ];

        return $map[$uri] ?? OPENSSL_ALGO_SHA256;
    }

    /**
     * Map XML digest algorithm URI to PHP hash algorithm name.
     */
    private function mapDigestAlgorithm($uri) {
        $map = [
            'http://www.w3.org/2000/09/xmldsig#sha1'       => 'sha1',
            'http://www.w3.org/2001/04/xmlenc#sha256'       => 'sha256',
            'http://www.w3.org/2001/04/xmldsig-more#sha384' => 'sha384',
            'http://www.w3.org/2001/04/xmlenc#sha512'       => 'sha512',
        ];

        return $map[$uri] ?? 'sha256';
    }

    /**
     * Load and format the IdP certificate for OpenSSL.
     */
    private function loadCertificate() {
        $cert = trim($this->idpCertificate);

        // If already has PEM headers, use as-is
        if (strpos($cert, '-----BEGIN CERTIFICATE-----') !== false) {
            return $cert;
        }

        // Strip any whitespace and wrap in PEM format
        $cert = preg_replace('/\s+/', '', $cert);
        $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($cert, 64, "\n") . "-----END CERTIFICATE-----\n";

        return $cert;
    }
}
