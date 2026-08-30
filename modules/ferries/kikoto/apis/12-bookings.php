<?php

$curl = curl_init();

curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://test.api.b2b.kikoto.com/v1/bookings',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => '{
    "title": "mr",
    "name": "Polly",
    "first_surname": "Koepp",
    "second_surname": "Blick",
    "email": "Verlie_Stroman37@hotmail.com",
    "phone_country_code": "34",
    "phone": "581-291-5034",
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
            "ticket_type_id": 1,
            "title": "mr",
            "name": "Fae",
            "first_surname": "Ondricka",
            "second_surname": "Schumm",
            "birthdate": "1990-01-01",
            "nationality": "ESP",
            "identity_type": "dni",
            "identity_number": "12345678Z",
            "identity_expiration": "2050-01-01"
        }
    ]
}',
    CURLOPT_HTTPHEADER => array(
        'Accept-Language: en',
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer zrOPZWOlp_ysifmbagp6jwD12gL10wal',
        'Cookie: _csrf=tlydaufoSXMYnyxYehl_h08y-3blePil'
    ),
));

$response = curl_exec($curl);

curl_close($curl);
echo $response;
