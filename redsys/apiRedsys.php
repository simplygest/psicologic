<?php

class RedsysAPI
{
    var $vars_pay = array();

    function setParameter($key, $value)
    {
        $this->vars_pay[$key] = $value;
    }

    function getParameter($key)
    {
        return $this->vars_pay[$key];
    }

    function encrypt_3DES($message, $key)
    {
        $l = ceil(strlen($message) / 8) * 8;
        return substr(openssl_encrypt($message . str_repeat("\0", $l - strlen($message)), 'des-ede3-cbc', $key, OPENSSL_RAW_DATA, "\0\0\0\0\0\0\0\0"), 0, $l);
    }

    function base64_url_encode($input)
    {
        return strtr(base64_encode($input), '+/', '-_');
    }

    function encodeBase64($data)
    {
        return base64_encode($data);
    }

    function base64_url_decode($input)
    {
        return base64_decode(strtr($input, '-_', '+/'));
    }

    function decodeBase64($data)
    {
        return base64_decode($data);
    }

    function mac256($ent, $key)
    {
        return hash_hmac('sha256', $ent, $key, true);
    }

    function getOrder()
    {
        if (empty($this->vars_pay['DS_MERCHANT_ORDER'])) {
            return $this->vars_pay['Ds_Merchant_Order'];
        }

        return $this->vars_pay['DS_MERCHANT_ORDER'];
    }

    function arrayToJson()
    {
        return json_encode($this->vars_pay);
    }

    function createMerchantParameters()
    {
        return $this->encodeBase64($this->arrayToJson());
    }

    function createMerchantSignature($key)
    {
        $key = $this->decodeBase64($key);
        $ent = $this->createMerchantParameters();
        $key = $this->encrypt_3DES($this->getOrder(), $key);
        return $this->encodeBase64($this->mac256($ent, $key));
    }

    function getOrderNotif()
    {
        if (empty($this->vars_pay['Ds_Order'])) {
            return $this->vars_pay['DS_ORDER'];
        }

        return $this->vars_pay['Ds_Order'];
    }

    function getOrderNotifSOAP($datos)
    {
        $posPedidoIni = strrpos($datos, '<Ds_Order>');
        $tamPedidoIni = strlen('<Ds_Order>');
        $posPedidoFin = strrpos($datos, '</Ds_Order>');
        return substr($datos, $posPedidoIni + $tamPedidoIni, $posPedidoFin - ($posPedidoIni + $tamPedidoIni));
    }

    function getRequestNotifSOAP($datos)
    {
        $posReqIni = strrpos($datos, '<Request');
        $posReqFin = strrpos($datos, '</Request>');
        $tamReqFin = strlen('</Request>');
        return substr($datos, $posReqIni, ($posReqFin + $tamReqFin) - $posReqIni);
    }

    function getResponseNotifSOAP($datos)
    {
        $posReqIni = strrpos($datos, '<Response');
        $posReqFin = strrpos($datos, '</Response>');
        $tamReqFin = strlen('</Response>');
        return substr($datos, $posReqIni, ($posReqFin + $tamReqFin) - $posReqIni);
    }

    function stringToArray($datosDecod)
    {
        $this->vars_pay = json_decode($datosDecod, true);
    }

    function decodeMerchantParameters($datos)
    {
        $decodec = $this->base64_url_decode($datos);
        $this->stringToArray($decodec);
        return $decodec;
    }

    function createMerchantSignatureNotif($key, $datos)
    {
        $key = $this->decodeBase64($key);
        $decodec = $this->base64_url_decode($datos);
        $this->stringToArray($decodec);
        $key = $this->encrypt_3DES($this->getOrderNotif(), $key);
        return $this->base64_url_encode($this->mac256($datos, $key));
    }

    function createMerchantSignatureNotifSOAPRequest($key, $datos)
    {
        $key = $this->decodeBase64($key);
        $datos = $this->getRequestNotifSOAP($datos);
        $key = $this->encrypt_3DES($this->getOrderNotifSOAP($datos), $key);
        return $this->encodeBase64($this->mac256($datos, $key));
    }

    function createMerchantSignatureNotifSOAPResponse($key, $datos, $numPedido)
    {
        $key = $this->decodeBase64($key);
        $datos = $this->getResponseNotifSOAP($datos);
        $key = $this->encrypt_3DES($numPedido, $key);
        return $this->encodeBase64($this->mac256($datos, $key));
    }
}
