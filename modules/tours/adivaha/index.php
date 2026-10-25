<?php
// path: modules/tours/adivaha/index.php
// Adivaha B2C Excursions (Tours & Activities) Module — Entry Point.
// Loads all Adivaha excursion routes into the existing $router. Included by
// modules/index.php. Order matters: creds.php first (defines the credential /
// token / HTTP helpers + offer-cache helpers the others call), then
// search/details, then the state-changing actions.

global $router;

include __DIR__ . "/creds.php";
include __DIR__ . "/search.php";
include __DIR__ . "/details.php";

// ACTIONS
include __DIR__ . "/actions/issue.php";
include __DIR__ . "/actions/cancel.php";
include __DIR__ . "/actions/void.php";
include __DIR__ . "/actions/refund.php";
