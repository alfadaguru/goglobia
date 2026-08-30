<?php

$curl = curl_init();

curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://test.api.b2b.kikoto.com/v1/prices',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => '{
    "sailings": [
        {
            "departure_port_id": 1,
            "destination_port_id": 2,
            "shipping_company_id": 1,
            "departure_datetime": "2026-06-29T07:30:00+02:00",
            "arrival_datetime": "2026-06-29T08:30:00+02:00",
            "accommodations": [
                {
                    "id": 1,
                    "type": "seat",
                    "title": "Jet Class",
                    "subtitle": "",
                    "description": "",
                    "code": "JC",
                    "passengers": [
                        {
                            "id": 1
                        }
                    ]
                }
            ]
        }
    ],
    "passengers": [
        {
            "id": 1,
            "ticket_type_id": 10
        }
    ]
}',
    CURLOPT_HTTPHEADER => array(
        'Accept-Language: en',
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer zrOPZWOlp_ysifmbagp6jwD12gL10wal',
        'Cookie: _csrf=rnDbPE6MnmA7UiyIkWCWesw5Sdnggr_3'
    ),
));

$response = curl_exec($curl);

curl_close($curl);
echo $response;
