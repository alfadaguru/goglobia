<?php

$curl = curl_init();

curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://test.api.b2b.kikoto.com/v1/bonuses/1',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'GET',
    CURLOPT_HTTPHEADER => array(
        'Accept-Language: en',
        'Accept: application/json',
        'Authorization: Bearer zrOPZWOlp_ysifmbagp6jwD12gL10wal',
        'Cookie: _csrf=FOXCeC3-mTB3U5Z1e317Crom0-XYCj20'
    ),
));

$response = curl_exec($curl);

curl_close($curl);
echo $response;
