<?php
// path: modules/flights/tiqwa/index.php
// Tiqwa Flights Module — Entry Point.
// Loads all Tiqwa routes into the existing $router instance. Included by
// modules/index.php. Order matters: creds.php first (defines the credential /
// HTTP / log helpers the others call), then search/revalidate, then the
// utilities (airports/airlines/wallet/markup/office_id — admin & reference),
// then the state-changing actions.
//
// Provider shape (authoritative: docs/research/tiqwa/*.json):
//   Flight service  (base .../v1/flight): search, multi_city/search,
//     confirm_price/{flight_id}, book/{flight_id}, {booking_reference} (details),
//     {booking_reference} POST (cancel), pay/{reference} (issue).
//   Utilities service (base .../v1): airports, airlines, wallet, markup CRUD,
//     office_id.
// Auth: single Bearer access token (sandbox OR production), from the dashboard.
//
// NB: the INBOUND webhook receiver (ticket.issued / flight.booked) deliberately
// lives OUTSIDE this gateway — in app/routes/flights/tiqwaWebhookRoutes.php — so
// it is a PUBLIC no-auth POST (the /modules/* central guard would 403 it) and is
// authenticated by a shared-secret path token + a verified read-back.

require_once 'creds.php';
require_once 'search.php';
require_once 'revalidate.php';
require_once 'utilities.php'; // airports + airlines + wallet + markup CRUD + office_id + booking-details/poll

// ACTIONS
require_once 'actions/issue.php';
require_once 'actions/cancel.php';
require_once 'actions/void.php';
require_once 'actions/refund.php';
