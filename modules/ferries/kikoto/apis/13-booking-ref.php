<?php

$curl = curl_init();

curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://test.api.b2b.kikoto.com/v1/bookings/284EDD84CF/confirm',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_HTTPHEADER => array(
        'Accept-Language: en',
        'Accept: application/json',
        'Authorization: Bearer zrOPZWOlp_ysifmbagp6jwD12gL10wal',
        'Cookie: _csrf=mxAGeus1KCg-rcq8benqyfCD0LBttFWo'
    ),
));

$response = curl_exec($curl);

curl_close($curl);
echo $response;
