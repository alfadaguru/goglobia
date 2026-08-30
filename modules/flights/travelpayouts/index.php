<?php

// Travelpayouts Flights Module

// Include credentials validation route
include "creds.php";
include "search.php";

// ACTIONS
include "actions/issue.php";
include "actions/cancel.php";
include "actions/void.php";
include "actions/refund.php";
