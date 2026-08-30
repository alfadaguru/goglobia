# **![mozio\_logo\_320.png][image1]**

# **FAQ API QUESTIONS**

### **GENERAL**

**1\. What architecture does Mozio API use?** 

Mozio API has been designed using a RESTful architecture. It relies on predictable, resource-oriented URLs and HTTP response codes to indicate errors.

**2\. What are the key features of Mozio API?**

 Mozio API utilizes built-in HTTP features such as HTTP authentication and HTTP verbs, which are supported by most HTTP clients.

**3\. What format are API responses returned in?** 

JSON is returned by all API responses, including errors. It's essential to ensure that the appropriate Content-Type is present in your HTTP requests, typically set to application/json.

**4\. Do I need to include the Content-Type header in my requests?**

Yes, you need to include the appropriate Content-Type header in your HTTP requests, indicating that the data is in JSON format. However, for the sake of simplicity, the Content-Type header is omitted in sample requests/responses provided.

**5\. What URLs should I use for development and production environments?** 

For development and testing purposes, point your requests to [https://api-testing.mozio.com](https://api-testing.mozio.com). When ready for production, switch to [https://api.mozio.com](https://api.mozio.com). Regardless of the environment, HTTPS is required. Any request not using HTTPS will be ignored.

**6\. What is the current API version?** 

The current API version is 2\. All endpoints described in the documentation will begin with /v2/.

### **AUTHENTICATION**

**7\. What is required for authentication when using Mozio API?** 

To access any resource in the Mozio API, you must have a valid Mozio API key. This key needs to be included as a header in your requests, named API-KEY. Failure to provide a valid API key will result in a HTTP 403 response.

**8\. How do I include my Mozio API key in my requests?** 

You need to include your Mozio API key as a header in your HTTP requests. Add a header named API-KEY with your API key as the value.

**9\. Can you provide an example of how to send API requests with the Mozio API key?** 

```py
GET /v2/some_resource
API-KEY: <myMozioAPIkey>
HTTP/1.1 200 OK
{
  "response": "OK" 
}
```

**10\. What should I do if I don't have API keys?** 

If you don't have API keys, please contact the Mozio team to obtain them. They will assist you in acquiring the necessary API keys for accessing the Mozio API. 

**11\. Does Mozio API support localization?** 

Yes, Mozio API supports localization to 17 languages by adding an optional LANG header to your requests. The LANG header value must follow the IETF format.

### **LANGUAGES**

**12\. What are some of the supported languages for localization?** 

Some of the supported languages include:

* English (en-US, default value)  
* Spanish (es-ES)  
* French (fr-FR)  
* German (de-DE)  
* Portuguese (pt-BR)

### **ERRORS**

**13\. What are the major categories of errors in Mozio API responses?** 

The errors in Mozio API responses typically fall into two major categories:

* Authentication errors (HTTP 403): Often caused by missing or invalid API keys.  
* Bad request errors (HTTP 400): Occur due to malformed inputs.  
* 

**14\. How are bad request errors handled in Mozio API responses?** 

For bad requests, the JSON object returned includes all fields that were malformed during the request. Each field includes a 3-tuple structure containing:

* Code: Categorizes the error.  
* Message: Targeted at developers for debugging purposes.  
* User\_message: Targeted at end-users, which can be null.

**15\.  What happens when errors are not related to specific parameters but to all data instead?** 

When errors are not related to specific parameters but to all data due to global validation issues, a list of the same 3-tuple structure will be available inside a special object called non\_field\_errors.

**16\. Can you provide examples of bad API requests and their corresponding error responses?**

Example of a bad API request raising a specific-parameter error:

```
GET /v2/some_resource/
API-KEY: <myMozioAPIkey>
HTTP/1.1 400 Bad Request
{
  "specific_param": [
    {
      "code": "required",
      "message": "This field is required.",
      "user_message": "You must send this param"
    }
  ]}
```

Example of a bad API request raising a global validation error:

```
GET /v2/some_resource/
API-KEY: <myMozioAPIkey>
HTTP/1.1 400 Bad Request
{
  "specific_param": [
    {
      "code": "missing_currency",
      "message": "You must provide a price_currency param.",
      "user_message": "Ooops, you didn’t choose a currency."
    }
  ]
}
```

**17\. What should I do if I encounter an error that I cannot debug myself?** 

If you encounter an error that you cannot debug yourself, please contact the Mozio Tech team for assistance. Include the error structure as part of your debugging request for efficient resolution.

### **API INTEGRATION STEPS**

**18\. What are the key steps involved in integrating with Mozio API?** 

The integration flow with Mozio API can be summarized in three main steps:

* Searching  
* Booking  
* Cancellation

### **SEARCH**

**19\. What does the searching step entail?** 

Searching involves providing basic inputs describing the intended trip, such as start and end locations, desired pickup time, and the number of passengers. The API then returns quotes that fulfill the trip requirements.

**20\. How does the API handle pickup time if it's unknown?** 

If the desired pickup time is unknown, the API can calculate an estimated pickup time using flight data, including time, airline, and flight number. For domestic flights, 30 minutes are added to the flight datetime, while for international flights, 60 minutes are added.

**21**. **Can you explain the search process in more detail?** 

The search process consists of two subprocesses: search request and search polling.

* Search request: Initiates a new search process.  
* Search polling: Involves querying the API every few seconds for the results of the search. Each poll returns new quotes, and polling continues until no more search results are expected.

**22\. What information can I expect from the quotes returned during the search process?** 

Each quote includes comprehensive data describing the offered trip, such as the provider fulfilling the service, the type of vehicle to expect, any restrictions (e.g., maximum number of passengers and luggage, schedules, boarding points), and the total price for all passengers.

**23\. How does the API handle the composition of quotes and the retrieval process?** 

The API composes a list of quotes using its in-house ground providers platform and queries external providers' APIs for quotes in real-time. As this is a dynamic process with inherent delays, quotes are not returned immediately. Instead, the search process involves polling for results until no more quotes are expected back.

**24**. **What is involved in the booking process with Mozio API?** 

Booking refers to the process of requesting an external provider to secure and fulfill a trip described by a quote obtained from search results.

### **BOOKING**

**25\. How do I start the search process with Mozio API?** 

You can initiate the search process by sending a POST request to the endpoint /v2/search/.

**26\. What are the allowed methods for starting a search?** 

The allowed method for starting a search is POST.

**27\. What information do I need to provide to start a search?**

* You can choose to provide either the locations’ addresses or GPS coordinates. For airports, send the airports’ IATA code (e.g., JFK).  
* Provide the pickup\_datetime when possible. If not available and the search involves an airport, you may use flight\_datetime, and our API will calculate an estimated pickup time.  
* If performing a round-trip search, ensure to provide either return\_flight\_datetime or return\_pickup\_datetime.  
* You can pass campaign and branch parameters to differentiate searches from different sources on your platform. Campaign identifies major sources of traffic, while branch helps differentiate within the campaign itself.

**28\. What are the rate limits for search requests?**

* In the production environment, Mozio API allows 100 search requests per minute. Requests exceeding this limit will be throttled.  
* In the testing environment, the API allows 30 search requests per minute.

**29\. Can you provide an example of starting a search with Mozio API?**

```
POST /v2/search/
API-KEY: <myMozioAPIkey>
{
  "start_address": "433 Park Ave, New York",
  "end_address": "JFK",
  "mode": "one_way",
  "pickup_datetime": "2017-08-16 15:30",
  "num_passengers": 2,
  "currency": "USD",
  "campaign": "confirmation_email",
  "branch": "version_a"
}
HTTP/1.1 201 Created
{
  "created_at": 1492730991,
  "currency_info": {
    "code": "USD",
    "prefix_symbol": "$",
    "suffix_symbol": ""
  },
  "end_location": {
    "formatted_address": "John F Kennedy International Airport",
    "full_address": "John F Kennedy International Airport",
    "iata_code": "JFK",
    "icao_code": "KJFK",
    "lat": 40.639751,
    "lng": -73.778926,
    "place_id": "",
    "timezone": "America/New_York"
  },
  "expires_at": 1492731891,
  "flight_datetime": null,
  "flight_type": "domestic",
  "more_coming": true,
  "num_passengers": 2,
  "pickup_datetime": "2017-08-16T15:30:00-04:00",
  "results": [],
  "search_id": "5c8563a5d31d4c33a3a1eafbbfe59c4b",
  "start_location": {
    "formatted_address": "433 Park Ave, New York, NY 10022, USA",
    "full_address": "433 Park Ave, New York",
    "iata_code": "",
    "icao_code": "",
    "lat": 40.7607634,
    "lng": -73.971212,
    "place_id": "EiU0MzMgUGFyayBBdmUsIE5ldyBZb3JrLCBOWSAxMDAyMiwgVVNB",
    "timezone": "America/New_York"
  }
}
```

Note: The response includes details about the search request and the start and end locations, but the results array will be empty as polling is expected.

**30**. **How do I perform an hourly search with Mozio API?**

To perform an hourly search, you use the same endpoint and method as a normal point-to-point search (/v2/search/ and POST). However, you need to specify "hourly" for the value of the "mode" parameter and include an additional parameter called "hourly\_booking\_duration". No end\_location information should be included in the search.

**31\. Can you provide an example of starting an hourly search with Mozio API?**

```
POST /v2/search/
API-KEY: <myMozioAPIkey>
{
  "start_address": "433 Park Ave, New York",
  "mode": "hourly",
  "hourly_booking_duration": 2,
  "pickup_datetime": "2017-08-16 15:30",
  "num_passengers": 2,
  "currency": "USD"
}
HTTP/1.1 201 Created
{
  "created_at": 1492730991,
  "currency_info": {
    "code": "USD",
    "prefix_symbol": "$",
    "suffix_symbol": ""
  },
  "expires_at": 1492731891,
  "flight_datetime": null,
  "flight_type": "domestic",
  "more_coming": true,
  "num_passengers": 2,
  "pickup_datetime": "2017-08-16T15:30:00-04:00",
  "results": [],
  "search_id": "5c8563a5d31d4c33a3a1eafbbfe59c4b",
  "start_location": {
    "formatted_address": "433 Park Ave, New York, NY 10022, USA",
    "full_address": "433 Park Ave, New York",
    "iata_code": "",
    "icao_code": "",
    "lat": 40.7607634,
    "lng": -73.971212,
    "place_id": "EiU0MzMgUGFyayBBdmUsIE5ldyBZb3JrLCBOWSAxMDAyMiwgVVNB",
    "timezone": "America/New_York"
  }
}
```

Note: The response includes details about the search request, but the results array will be empty as polling is expected.

**32\. How do I poll for search results with Mozio API?** 

To poll for search results, you use the endpoint /v2/search/\<search\_id\>/poll/ with the GET method. 

**33\. What are the recommended intervals for polling?** 

We recommend calling this endpoint approximately every 1-2 seconds for 10 seconds. However, the vast majority of our search results will be available in 3-6 seconds. Polling more frequently, such as every hundred milliseconds, is unnecessary as there will not be new results in such a small timeframe.

**34\.** **How long should I continue polling for results?** 

You should keep polling as long as the more\_coming value is true. However, always set a timeout in your application to stop polling after a certain maximum amount of time. Normally, the maximum number of polls expected is 10\.

**35\. Can you provide an example of polling for search results with Mozio API?**

```
GET /v2/search/5c8563a5d31d4c33a3a1eafbbfe59c4b/poll/
API-KEY: <myMozioAPIkey>
HTTP/1.1 200 OK
{
  "created_at": 1492730991,
  "currency_info": {
    "code": "USD",
    "prefix_symbol": "$",
    "suffix_symbol": ""
  },
  "expires_at": 1492731891,
  "flight_datetime": null,
  "flight_type": "domestic",
  "more_coming": false,
  "num_passengers": 2,
  "pickup_datetime": "2017-08-16T15:30:00-04:00",
  "results": [
    {
      "result_id": "763475651c06f5ac31ea587084dc0629",
      "steps": [
        {
          "details": {
            "alternative_times": {
              "default_index": 0,
              "options": [
                {
                  "arrival_datetime": "2017-08-16T15:06:10.949620-04:00",
                  "departure_datetime": "2017-08-16T14:40:00-04:00"
                },
                ...
              ]
            },
            "bookable": true,
            "cancellation": {...},
            "departure_datetime": "2017-08-16T14:40:00-04:00",
            "price": {...},
            "provider": {...},
            "vehicle": {...}
          },
          "main": true,
          "step_type": "car"
        }
      ],
      "tags": [],
      "total_price": {...}
    },
    ...
  ],
  "search_id": "5c8563a5d31d4c33a3a1eafbbfe59c4b",
  "start_location": {...}
}
```

Note: The response includes detailed information about the search results, including providers, vehicles, prices, and more.

**36\. How can I update and refresh an expired quote with Mozio API?** 

You can update and refresh an expired quote using the endpoint /v2/search/\<search\_id\>/\<result\_id\>/ with the PATCH method.

**37\. Why would I need to update an expired quote?** 

Mozio searches are valid for 15 minutes. After this time, the pricing and availability may change. Updating an expired quote ensures that the pricing and availability remain accurate before booking.

**38\. What parameters can I update with this API call?** 

You can update parameters such as the pickup time for a single quote that the customer is interested in booking. You can pass any parameter of the search to update it.

**39**.**What does the response format look like when updating a quote?** 

The response format is the same as a new search. It includes detailed information about the updated search results, and polling is required to retrieve the data.

**40\. Can you provide an example of updating a search with Mozio API?**

```
PATCH /v2/search/d198708f579a42e49e49b94f5d796c52/170da70e405c9c65979d8412b046ec9f//d
API-KEY: <myMozioAPIkey>
{
  "pickup_datetime": "2017-08-16 15:30",
  "currency": "USD"
}
HTTP/1.1 201 Created
{
  "created_at": 1492730991,
  "currency_info": {...},
  "expires_at": 1492731891,
  "flight_datetime": null,
  "flight_type": "domestic",
  "more_coming": true,
  "num_passengers": 2,
  "pickup_datetime": "2017-08-16T15:30:00-04:00",
  "results": [],
  "search_id": "5c8563a5d31d4c33a3a1eafbbfe59c4b",
  "start_location": {...}
}
```

Note: The response includes detailed information about the updated search results, but the results array will be empty as polling is expected.

**41\. What information is required for booking a trip?**

In addition to the data from the quote, you must provide contact details for the passengers, including names, email addresses, phone numbers, and relevant flight data if applicable. Passengers can also include special instructions for providers if supported (e.g., specific meeting instructions).

**42\. How is the booking process structured?** 

Similar to searches, the booking process consists of two stages: booking request and booking polling. First, you place the request for a reservation, then you start polling while the API connects to the provider’s API and awaits a response. 

**43\. What happens after a reservation is successfully placed?** 

Once a reservation is successfully placed, the API returns a confirmation number that can be used for further API requests and customer support as needed by the passenger. Contact details from the provider are also included. Additionally, the passenger receives a confirmation email with details about the reservation and instructions on meeting the driver or boarding the vehicle.

**44\. Can passengers communicate special instructions to providers?** 

Yes, passengers can send special instructions to providers if supported by them. These instructions can include requests like "Please announce yourself in the lobby" or "Please text me, my doorbell is broken".

**45\. How do I initiate the booking process with Mozio API?** 

You can initiate the booking process by sending a POST request to the endpoint /v2/reservations/.

**46\. What information do I need to provide to create a reservation?** 

You need to provide the search\_id and result\_id from the selected quote obtained during the search process. Additionally, you need to provide contact details of the passenger(s), including email, phone number, first name, and last name.

**47\. What does the response from the booking endpoint indicate?** 

The response informs you about the status of the reservation you tried to place. A successful response typically indicates a pending status, meaning our API is contacting the external provider and waiting for a response. However, it's important to check the response as it may not always be successful, indicating that the reservation wasn't created.

**48\. Is flight data required for all bookings?** 

Flight data (airline’s IATA code and flight number) is required for bookings going to/from an airport, unless you are booking an express train or public bus. If the search result indicates that flight data is not required, you can omit it from the reservation payload.

**49\. What should I do if the selected transfer option requires extra passenger information?** 

If the selected transfer option requires extra passenger information (extra\_pax\_required \= True), ensure to include this information in the reservation payload. Failure to do so will result in a 400 error, indicating that the reservation cannot be created.

**50**. **Can you provide an example of creating a reservation with Mozio API?**

```
POST /v2/reservations/
API-KEY: <myMozioAPIkey>
{
  "search_id": "5c8563a5d31d4c33a3a1eafbbfe59c4b",
  "result_id": "763475651c06f5ac31ea587084dc0629",
  "email": "happytraveler@mozio.com",
  "extra_pax_info": [
       { 
         "first_name": "JOSE",
         "last_name": "SMITH"
       } 
   ],
   "country_code_name": "US",
   "phone_number": "+18775998200",
   "first_name": "Happy",
   "last_name": "Traveler",
   "airline": "AA",
   "flight_number": "123",
   "customer_special_instructions": "My doorbell is broken, please yell"
}
HTTP/1.1 201 Created
{
  "reservations": [],
  "status": "pending"
}
```

Note: The response includes the status of the reservation, which in this case is pending, indicating that the booking process is ongoing.

**51\. What is the purpose of polling for reservation results?** 

Polling for reservation results allows you to monitor the status of reservations placed through Mozio API and retrieve details about the reservations.

**52\. How do I initiate polling for reservation results?** 

You can initiate polling by sending a GET request to the endpoint /v2/reservations/\<search\_id\>/poll/, where \<search\_id\> is the unique identifier of the reservation.

**53\. What are the possible states of a reservation?** 

Reservations can have one of three possible states: pending (initial state, indicating it has been processed), completed (successful reservation), or failed (an error occurred during the reservation process).

**54\. How frequently should I poll for reservation results?** 

It is recommended to poll every 1-2 seconds until the status of the reservation changes. However, you should implement a timeout in your application to stop polling after a certain aximum amount of time to prevent unnecessary resource consumption.

**55\. What information will the polling response contain?** 

The polling response will contain details of the reservation, including the airline, amount paid, confirmation number, passenger information, pickup instructions, provider details, and more.

**56\. Is there a time limit for retrieving reservation details after completion?** 

Yes, if the reservation status is completed, you can retrieve details of the reservation for an hour from the time of making the reservation.

**57\. Can you provide an example of polling for reservation results with Mozio API?** 

```
GET /v2/reservations/5c8563a5d31d4c33a3a1eafbbfe59c4b/poll/
API-KEY: <myMozioAPIkey>
HTTP/1.1 201 Created
{
  "reservations": [
    {
      "airline": "American Airlines",
      "amount_paid": "$48.86",
      "confirmation_number": "hudsontest",
      "currency": "USD",
      "email": "happytraveller@mozio.com",
      "first_name": "Happy",
      "flight_number": "123",
      "last_name": "Traveler",
      "phone_number": "+1 877-666-5544",
      "pickup_instructions": "The driver will call you when he arrives.",
      "provider": {
        "name": "GO Airlink NYC",
        "phone_number": "+18775998200",
        "logo_url": "https://.../blackcar_logo.png",
        "email": "info@goairportshuttle.com",
        "rating": 5
      },
      "total_price": {
        "compact": "$49",
        "display": "$48.86",
        "value": "48.86"
      }
    }
  ],
  "status": "completed"
}
```

Note: The response includes details of the completed reservation, including passenger information, pickup instructions, provider details, and total price.

**58\. How can I retrieve details of an individual reservation using Mozio API?** 

You can retrieve details of an individual reservation by sending a GET request to one of the following endpoints:

* /v2/reservations/by\_confirmation\_number/\<user\_reservation\_id\>/  
* /v2/reservations/\<hashed\_id\>/  
* /v2/reservations/by\_external\_id/\<external\_id\>/  
* /v2/reservations/by\_search\_id/\<search\_id\>/

**59\. What information do I need to provide to retrieve reservation details?** 

You need to provide the relevant identifier for the reservation, such as the user\_reservation\_id, hashed\_id, external\_id, or search\_id, depending on the endpoint you choose.

**60\. How do I find the hashed\_id needed for retrieving reservation details?** 

The hashed\_id can be found in the response of the poll method under the id value. After obtaining it, you can use it to retrieve the details of the reservation.

**61\. Can you provide an example of retrieving reservation details using the hashed\_id?**

```
GET /v2/reservations/5c8563a5d31d4c33a3a1eafbbfe59c4b/poll/
API-KEY: <myMozioAPIkey>
HTTP/1.1 200 OK
{
    "status": "completed",
    "reservations": [
        {
            "url": "https://api-testing.mozio.com/v2/reservations/b8150702cb874abc8d592610afa2a2d9/",
            "id": "b8150702cb874abc8d592610afa2a2d9",
            "amount_paid": "$1,434.14",
            "gratuity": "$0.00",
            "pickup_instructions": "Your driver will be waiting for you near the baggage claim with your name on a sign. Emergency number: +18002665254",
            "confirmation_number": "6202191",
            ...
        }
    ]
}
```

**62**. **Is there a way to retrieve details of all reservations associated with a partner?** 

Yes, you can retrieve details of all reservations associated with a partner by sending a GET request to the endpoint /v2/reservations-by-partner/. Additionally, you need to pass your ref parameter in the headers along with the API-KEY.

**63\. What should I do if I don't know what my ref parameter is?** 

If you're unsure about your ref parameter, you should get in touch with the Mozio team for assistance. They can provide you with the necessary information to include in the headers for retrieving partner-specific reservations.

### **CANCELLATION**

**64\. How does the cancellation process work with Mozio API?** 

The cancellation process allows passengers to cancel an existing reservation and receive a full refund. However, not all reservations are cancellable. Each quote includes a cancellation policy specifying whether the trip is cancellable and the notice required for a 100% refund.

**65\. What information is required to initiate a cancellation?** 

To proceed with a cancellation, our API requires the confirmation number of the reservation. It's important to note that this operation cannot be undone.

**66\. How long does it take to receive a refund after cancellation?** 

Refunds may take up to 5 business days to be issued by our payments processor after a successful cancellation.

**67\. What happens after a successful cancellation is made?** 

After a successful cancellation, the passenger will receive an email notification confirming the operation.

**68\. How can I cancel a reservation using the Mozio API?** 

You can cancel a reservation by sending a DELETE request to the endpoint /v2/reservations/\<reservation\_id\>/, where \<reservation\_id\> is the identifier of the reservation you want to cancel.

**69\. What HTTP method should I use to cancel a reservation?** 

You should use the DELETE method to cancel a reservation.

**70\. Does the cancellation process involve polling?** 

No, the cancellation process does not involve polling. Once the DELETE request is successfully processed, the reservation is canceled without the need for further polling.

**71\. Can you provide an example of canceling a reservation?**

```
DELETE /v2/reservations/
API-KEY: <myMozioAPIkey>
HTTP/1.1 202 Accepted
{
  "cancelled": 1,
  "refunded": 1
}
```

In this example, a DELETE request is sent to cancel the reservation. Upon successful cancellation, the response indicates that the reservation has been canceled and any applicable refund has been processed.

**72\. What status code can I expect in the response upon successful cancellation?** 

Upon successful cancellation, you can expect a status code of 202 Accepted in the response. This indicates that the cancellation request has been accepted and processed by the server.

### **RESERVATION CHANGES**

**73\. How do I initiate changes to a reservation using the Mozio API?** 

To make changes to a reservation, you need to follow a specific process. First, perform a re-search by sending a POST request to the /v2/search/reservation\_changes/ endpoint. This request should include the parameters you want to change, such as the pickup datetime, and the reservation hashed ID.

**74\. What is the purpose of the re-search endpoint in the reservation change process?** 

The re-search endpoint allows you to find options for the requested changes while considering the original reservation details. It simplifies the process by only providing options from the same provider as the original reservation, making the search faster.

**75\. Can you provide an example of performing a re-search for reservation changes?**

```
POST /v2/search/reservation_changes/
API-KEY: <myMozioAPIkey>
{ 
  "pickup_datetime": "2017-08-30 22:00", 
  "reservation_hashed_id": "5c8563a5d31d4c33a3a1eafbbfe59c4b"
}
```

**76\. What is the next step after performing the re-search?** 

After obtaining the search results, you need to create a new reservation using the /v2/reservations/changes/ endpoint. This endpoint requires parameters such as the old reservation ID, search ID, result ID, and any additional data that needs to be updated.

**77\. What should I do after creating the changed reservation?** 

Once the changed reservation is created, you need to poll the reservation status using the poll reservation endpoint to get the final result. This step is necessary to ensure the changes are successfully processed, similar to a normal reservation process.

**78\. Is there anything else I need to know about the reservation change process?** 

Yes, it's important to note that only cancelable reservations can be changed. Additionally, the price of the reservation is subject to change based on the modifications, so be prepared to charge or refund your customer accordingly.

### **AMENITIES**

**79\. What are amenities in the context of Mozio's trip booking services?** 

Amenities are optional upgrades that can enhance a trip, such as child seats, baby boosters, or meet and greet services. These enhancements are available to customize your travel experience.

**80\. How do amenities vary based on providers and search parameters?** 

Amenities offered by providers can vary depending on factors such as the provider's offerings and the specific parameters of the trip search. For example, one provider may offer certain amenities on certain days but not on others.

**81\. How many of each amenity can be added to a trip?** 

When adding an amenity to a trip, only one of each amenity can be added by default. For example, if you add a child seat, it will be limited to one seat per trip.

**82\. Can I request more than one of the same amenity for a trip?** 

Yes, if you require more than one of the same amenity, you can specify your request using the "customer\_special\_instructions" field. Simply include a message detailing the quantity needed, such as "Please provide 2 child seats."

**83\. Are amenities automatically included in a trip booking?** 

No, amenities are optional upgrades that need to be added to the trip booking. You can select the desired amenities during the booking process to tailor the trip to your preferences and needs.

**84\. What is the purpose of the /v2/amenities/ endpoint in Mozio's API?** 

The /v2/amenities/ endpoint allows users to retrieve a list of available amenities that can be added to a trip booking.

**85\. How can I access the list of available amenities?** 

You can access the list of available amenities by sending a GET request to the /v2/amenities/ endpoint and including your API key in the request headers.

**86\. What information is provided in the response from the /v2/amenities/ endpoint?** 

The response from the /v2/amenities/ endpoint includes details about each amenity, such as the amenity ID, display name, description, and image URLs.

**87\. Can I see the available amenities for a specific trip search?** 

Yes, after performing a trip search and receiving a response, you can see the available amenities for each search result by checking the "amenities" field in the response.

**88\. How do I retrieve the available amenities for a specific search result?** 

To retrieve the available amenities for a specific search result, you need to send a GET request to the /v2/search/\<search\_id\>/poll/ endpoint and include your API key. The response will contain information about the available amenities for that particular search result.

**89\. What is the purpose of the /v2/pricing/ endpoint?**

The /v2/pricing/ endpoint is designed to retrieve the total cost of a trip, inclusive of selected amenities.

**90\. What HTTP methods are allowed for accessing the endpoint?**

Both POST and GET methods are allowed for retrieving the total cost of a trip with amenities.

**91\. How can I add amenities to a trip when using this endpoint?**

Amenities can be added to a trip by providing the result\_id, search\_id, and a list of amenities' keys either as data or query parameters.

**92\. What format should the amenities be provided in when using query parameters?**

When using query parameters, amenities should be provided as key=value pairs for each list member. For example, ?amenities=wifi\&amenities=child\_booster will be treated as \["wifi", "child\_booster"\].

**93\. Can you provide an example of how to retrieve a total price inclusive of amenities using the POST method?**

Example POST request:

```
POST /v2/pricing/
API-KEY: <myMozioAPIkey>
{
  "search_id": "73a5670b476649a985f0535db1077c05",
  "result_id": "fe94b51ccd0623a9f1adabfbe0614d34",
  "optional_amenities":["baby_seats", "child_booster"]
}
```

**94\. What information does the response include when retrieving the total cost of a trip with amenities?**

The response includes details of each selected amenity, such as name, description, image URL, input type, and price. Additionally, it provides the final price of the trip with all selected amenities.

**95\. How is the final price of the trip represented in the response?**

The final price is represented as a JSON object with key-value pairs indicating the value, display format, currency, and smallest currency unit.

**96\. Is there any specific formatting for the displayed price?**

The displayed price is formatted with a dollar sign ($) and two decimal places. However, a compact version with no decimal places is also provided.

**97\. Are the amenities included in the final price automatically?**

No, amenities are not automatically included in the final price. Their prices are added to the base trip cost to calculate the total.

**98\. How can I identify which amenities are selected and their respective prices in the response?**

Each amenity in the response has a "selected" attribute set to true, and its price details are provided within the response JSON.

**99\. What is the purpose of the /v2/reservations/ endpoint?**

The /v2/reservations/ endpoint is used for creating and retrieving details of bookings, including optional amenities.

**100\. How can I initiate a search for booking options?**

To start a search, send a POST request to /v2/reservations/ with your API key. Note that the initial response may not contain booking results, as polling is expected.

**101\. How can I create a reservation that includes optional amenities?**

Create a reservation by sending a POST request to /v2/reservations/ with the necessary parameters, including search\_id, result\_id, email, country\_code\_name, phone\_number, first\_name, last\_name, airline, flight\_number, customer\_special\_instructions, and optional\_amenities.

**102\. Can you provide an example of creating a reservation with amenities?**

Example POST request:

```
POST /v2/reservations/
API-KEY: <myMozioAPIkey>
{
  "search_id": "73a5670b476649a985f0535db1077c05",
  "result_id": "fe94b51ccd0623a9f1adabfbe0614d34",
  "email": "happytraveler@mozio.com",
  "country_code_name": "US",
  "phone_number": "8776665544",
  "first_name": "Happy",
  "last_name": "Traveler",
  "airline": "AA",
  "flight_number": "123",
  "customer_special_instructions": "My doorbell is broken, please yell",
  "optional_amenities": ["baby_seats", "child_booster"]
}
```

**103**. **What HTTP status code confirms the successful creation of a reservation?**

A successful reservation creation returns a HTTP status code of 201 (Created).

**104\. How can I retrieve details of a booking, including selected amenities?**

Retrieve booking details by sending a GET request to /v2/reservations/\<search\_id\>/poll/ with your API key.

**105\. Are amenities automatically included in a booking?**

No, amenities are optional. If amenities are added to a booking, their "selected" attribute is set to true in the response.

**106\. How are amenities represented in the booking details response?**

Amenities are represented as JSON objects with attributes such as name, description, image URL, input type, price, and selected status.

**107\. Can you provide an example of retrieving booking details with amenities included?**

Example GET request:

```
GET /v2/reservations/73a5670b476649a985f0535db1077c05/poll/
API-KEY: <myMozioAPIkey>
```

**108\. How can I identify whether an amenity is selected or not in the booking details response?**

If an amenity is added to a booking, its "selected" attribute is set to true; otherwise, it is set to false.

### **FLIGHTS VALIDATION**

**109\. What is the purpose of the /v2/flights/verify/ endpoint?**

The /v2/flights/verify/ endpoint allows you to verify if a selected pick-up time is suitable for a flight or if opting for an earlier time would be more convenient.

**110\. How can I retrieve flight information using this endpoint?**

Send a POST request to /v2/flights/verify/ with parameters such as return\_voyage, pickup\_datetime, airline\_code, airport\_code, flight\_direction, and flight\_number.

**111\. Can you provide an example of retrieving flight information with this endpoint?**

Example POST request:

```
POST /v2/flights/verify
API-KEY: <myMozioAPIkey>
{
    "return_voyage": true,
    "pickup_datetime": "2024-06-26T08:00:00+07:00",
    "airline_code": "5J",
    "airport_code": "BKK",
    "flight_direction": "dep",
    "flight_number": "930",
    "search_id": "7814f4dde857468dadb5d0f964237525"
}
```

**112\. What does the response from the /v2/flights/verify/ endpoint contain?**

The response includes whether the selected pick-up time is valid, a message title, a message providing guidance, and extra data such as a possible alternate pick-up time.

**113\. How can I check details of a flight using the /v2/flights/ endpoint?**

Send a GET request to /v2/flights/ with parameters airline\_code, airport\_code, flight\_direction, pickup\_datetime, and flight\_number.

**114\. Can you provide an example of retrieving flight details with the /v2/flights/ endpoint?**

Example GET request:

```
GET /v2/flights/?airline_code=W6&airport_code=BCN&flight_direction=arriving&pickup_datetime=2024-06-30T10%3A45&flight_number=1705
```

**115\. What information does the response from the /v2/flights/ endpoint provide?**

The response includes details of the flights matching the parameters provided, such as airline code, airline name, flight number, flight type, origin, destination, terminal information, departure time, and datetime.

**116\. Are there any specific methods allowed for accessing these endpoints?**

The /v2/flights/verify/ endpoint allows POST requests, while the /v2/flights/ endpoint allows GET requests.

**117\. How can I ensure that I'm using the correct parameters when accessing these endpoints?**

Ensure that you provide accurate parameters such as airline code, airport code, flight direction, pickup datetime, and flight number to retrieve relevant flight information.

**118\. Can I use these endpoints to verify both outbound and return flights for a trip?**

Yes, the endpoints support verifying both outbound and return flights by providing appropriate parameters such as flight\_direction and return\_voyage.

### **PHONE NUMBER VALIDATION**

**119\. What does the phone validation look like?** 

We use methods is\_possible\_number && is\_valid\_number from the following library [https://pypi.org/project/phonenumbers/](https://pypi.org/project/phonenumbers/) to validate the phone numbers.

. 

[image1]: <data:image/png;base64,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>