<?php

class RedsysPayment {
    private string $merchantCode;
    private string $terminal;
    private string $secretKey;
    private string $tpvUrl;

    public function __construct() {
        $this->merchantCode = REDSYS_MERCHANT_CODE;
        $this->terminal     = str_pad(REDSYS_TERMINAL, 3, '0', STR_PAD_LEFT);
        $this->secretKey    = REDSYS_SECRET_KEY;
        $this->tpvUrl       = REDSYS_URL;
    }

    // AES-128-CBC encryption (matches Node.js encryptAES)
    private function encryptAES(string $data, string $key): string {
        $fixedKey = str_pad(substr($key, 0, 16), 16, "0");
        $iv = str_repeat("\0", 16);
        $encrypted = openssl_encrypt($data, 'aes-128-cbc', $fixedKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode($encrypted);
    }

    // HMAC-SHA512 (matches Node.js mac512)
    private function mac512(string $data, string $key): string {
        return hash_hmac('sha512', $data, $key, true);
    }

    // Base64 URL-safe encode (matches Node.js base64UrlEncodeSafe)
    private function base64UrlEncode($data): string {
        $encoded = base64_encode($data);
        return str_replace(['=', '+', '/'], ['', '-', '_'], $encoded);
    }

    // Base64 URL-safe decode (matches Node.js base64UrlDecodeSafe)
    private function base64UrlDecode(string $str): string {
        $str = str_pad(
            strtr($str, '-_', '+/'),
            strlen($str) + (4 - strlen($str) % 4) % 4,
            '='
        );
        return base64_decode($str);
    }

    public function generatePaymentRequest(
        int $amountCents,
        string $orderId,
        string $urlOk,
        string $urlKo,
        string $merchantUrl,
        string $description = 'Compra'
    ): array {
        $merchantParameters = [
            'Ds_Merchant_Amount'             => (string)$amountCents,
            'Ds_Merchant_Order'              => $orderId,
            'Ds_Merchant_MerchantCode'       => $this->merchantCode,
            'Ds_Merchant_Currency'           => '978', // EUR
            'Ds_Merchant_TransactionType'    => '0',   // Authorization
            'Ds_Merchant_Terminal'           => $this->terminal,
            'Ds_Merchant_MerchantURL'        => $merchantUrl,
            'Ds_Merchant_UrlOK'              => $urlOk,
            'Ds_Merchant_UrlKO'              => $urlKo,
            'Ds_Merchant_ProductDescription' => $description,
            // Card only — no PayMethods set
        ];

        $jsonParams = json_encode($merchantParameters);
        $Ds_MerchantParameters = $this->base64UrlEncode($jsonParams);

        // Diversify key with order ID using AES
        $diversifiedKey = $this->encryptAES($orderId, $this->secretKey);

        // HMAC-SHA512 of parameters using diversified key
        $mac = $this->mac512($Ds_MerchantParameters, $diversifiedKey);
        $Ds_Signature = $this->base64UrlEncode($mac);

        return [
            'url'    => $this->tpvUrl,
            'params' => [
                'Ds_SignatureVersion'   => 'HMAC_SHA512_V2',
                'Ds_MerchantParameters' => $Ds_MerchantParameters,
                'Ds_Signature'          => $Ds_Signature,
            ]
        ];
    }

    public function verifyCallback(
        string $dsSignatureVersion,
        string $dsMerchantParameters,
        string $dsSignature
    ): bool {
        $jsonParams = $this->base64UrlDecode($dsMerchantParameters);
        $params = json_decode($jsonParams, true);
        $orderId = $params['Ds_Merchant_Order'] ?? $params['Ds_Order'] ?? null;
        if (!$orderId) return false;

        $diversifiedKey = $this->encryptAES($orderId, $this->secretKey);
        $mac = $this->mac512($dsMerchantParameters, $diversifiedKey);
        $expectedSignature = $this->base64UrlEncode($mac);

        return hash_equals($expectedSignature, $dsSignature);
    }

    public function decodeParameters(string $dsMerchantParameters): array {
        return json_decode($this->base64UrlDecode($dsMerchantParameters), true);
    }
}
