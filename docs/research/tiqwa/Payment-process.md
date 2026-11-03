# Payment & Ticket Issuing

In this document we will describe how ticket issuing works in the travel space, and how you can pay for a reserved booking on Tiqwa.

## Ticket Issuing

As you might have already known the [Flight Booking Process](https://docs.travelwahoo.com/docs/tiqwa/reference/Flight.v1.json) takes 3 steps:

1. Flight Search
2. Price Confirmation of selected flight
3. Flight Booking

Completing these three steps only `Reserves` the flight , at this point ticket(s) has not been issued. On the [Booking Details Endpoint](https://docs.travelwahoo.com/docs/tiqwa/reference/Flight.v1.json/paths/\~1%7Bbooking_reference%7D/get) response, you find see an `expires_at`  field that tells you when a booking reservation will be cancelled automatically if ticket is not issued for that booking.

For you to finalize the ticket issuing, payment must be made for the booking reference generated.

## Payment Methods

We understand that depositing large funds to your Tiqwa Wallet in advance is relatively expensive, as most new Online Travel Agencies might not be able afford it; this is why we have made it easy(commercially) for you to request for ticket issuing through:

1. **Bank Transfer:** You can transfer the amount of a ticket to our bank account(Found on the wallet section of your account dashboard) after collecting payment from your customer. You will have to use the booking `reference` e.g `TW-FLMSQMQO19` as transfer remark. The system will automatically issue ticket for the provided booking reference.

2. **Automatic Issuing:** You can also issue ticket automatically if you ticket wallet is funded, using the [Issue Ticket Endpoint](https://docs.tiqwa.com/docs/tiqwa/cc40d70792d1b-issue-ticket)

Once a booking has been paid for the `ticket` object of the [Booking Details Response](https://docs.travelwahoo.com/docs/tiqwa/reference/Flight.v1.json/paths/\~1%7Bbooking_reference%7D/get) will have the ticket details for the flight booking.

> We are working on making this process seamless, by providing more Payment Methods. Follow our [Twitter Page](https://twitter.com/TiqwaHQ) to get notifications when more payment methods are added.
