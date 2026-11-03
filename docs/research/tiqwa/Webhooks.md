# Webhooks

Learn how to listen to events when certain actions occur in your integration with Tiqwa API.

## What are webhooks?

Webhooks are basically ways for apps to communicate between themselves automatically. Whenever certain key events are triggered on your integration with our API, we send you an event with the payload of that event.

For example, when one of your client's flight ticket has been issued we send you a `ticket.issued` event, containing the details of the flight including the ticket number of the flight.

This helps you provide timely update to your client, and you can choose to do anything with the payload neing sent to you.

For our system to send your app the details of an event, you must have set a `webhook URL`
in your app details. We have made it flexible enough that you can set different webhook URLs for different apps created on your Tiqwa dashboard.

You can specify the webhook URL for any of your apps on your Tiqwa dashboard while creating or updating the app.

<img src="https://i.ibb.co/vq1pvMm/webhook.png"
  alt="webhook"
  style="display: block;
margin-left: auto;
margin-right: auto;
width: auto;"
/>

Here are some things to keep in mind while setting up webhook for your app:

1. Ensure the endpoint accepts a `POST` request
2. The timeout for our webhook is 30 seconds. If your webhook operation is heavy you might consider running it asynchronously.
3. Do a test request on your endpoint to ensure it receives the payload

## Listening for events

All you need to do to receive webhook events is to create a `POST` endpoint that does not require authentication. Our event object is sent as JSON in the request body.

Example of receiving event in NodeJS using Express

```javascript

var express = require('express');
var app = express();

app.configure(function(){
  app.set('port', process.env.PORT || 3000);
  app.use(express.bodyParser());
});

app.post('/event', function (req, res) {

  var eventPayload = req.body;

  // Now you have the event payload, you can do
  
});

```

## Types of events

We have different events that you can listen to, and the events have distinct payload structure. Some of the events currently available are:

| Event         | Description                        |
| ------------- | ---------------------------------- |
| ticket.issued | A ticket has been issued           |
| flight.booked | A flight booking has been reserved |

### ticket.issued Event

When a ticket has been issued, a `ticket.issued` event is triggered, and you can listen to the event by checking the `event` field of the JSON payload. An example of what can be done with this event is sending your customers an automated email of containing the ticket details (ticket number etc.).

Here is the payload structure for the `ticket.issued` event:

```json
{
"event": "ticket.issued",
"data": {
    "pnr": "12UQFR",
    "amount": 40560.62,
    "status": "issued",
    "inbound": [],
    "pricing": {
        "markup": {
            "markup_type": "fixed",
            "markup_value": 1000
        },
        "payable": 39560.62
    },
    "tickets": [
        {
            "status": "issued",
            "traveler": "Ogbeni Lagbaja",
            "ticket_number": [
                "708 2402628015"
            ]
        }
    ],
    "currency": "NGN",
    "outbound": [
        {
            "baggage": "1x 20kg checkin allowance",
            "layover": null,
            "duration": 75,
            "overnight": false,
            "airport_to": "LOS",
            "cabin_type": "ECONOMY",
            "airport_from": "ABV",
            "arrival_time": "2021-07-30T19:30:00",
            "booking_class": null,
            "flight_number": "N2-128",
            "departure_time": "2021-07-30T18:15:00",
            "equipment_type": null,
            "marriage_group": null,
            "airline_details": {
                "code": "AJ",
                "logo": "https://images.tiqwa.com/airlines/AJ.png",
                "name": "Aero Contractors"
            },
            "marketing_airline": "AJ",
            "operating_airline": "AJ"
        }
    ],
    "reference": "TW-4BNXJSQG40",
    "created_at": "2021-07-26 12:11:33.081043",
    "expires_at": "2021-07-27T13:11:29",
    "fare_basis": null,
    "passengers": [
        {
            "dob": "1980-09-20",
            "email": "ogbeni_labgaja@tiqwa.com",
            "title": "mr",
            "gender": "male",
            "documents": {
                "holder": true,
                "number": "string",
                "expiry_date": "2022-10-30",
                "issuing_date": "2018-10-30",
                "document_type": "passport",
                "issuing_country": "NG",
                "nationality_country": "NG"
            },
            "last_name": "Ogbeni",
            "first_name": "Lagbaja",
            "phone_number": "08030000000",
            "passenger_type": "adult"
        }
    ],
    "price_change": false,
    "inbound_stops": 0,
    "bookable_seats": 8,
    "outbound_stops": 0,
    "total_duration": 75,
    "travelers_price": [
        {
            "adult": 36920
        }
    ],
    "document_required": false,
    "total_inbound_duration": null,
    "total_outbound_duration": 75
  }

}

```
